<script setup>
import { useId as useFieldId } from 'vue';
const fieldIdPrefix = useFieldId();
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import FormActions from '@/Components/ui/FormActions.vue';
import FormErrorSummary from '@/Components/ui/FormErrorSummary.vue';
import { useFormFeedback } from '@/composables/useFormFeedback';
import ProductPicker from '@/Components/ui/ProductPicker.vue';
import axios from 'axios';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, defineAsyncComponent, ref, watch, onBeforeUnmount } from 'vue';
import { useBarcodeWedge, useBarcodeLookup } from '@/composables/useBarcodeWedge';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, ArrowRight, Plus, Trash2, ScanLine } from '@lucide/vue';

const BarcodeScannerModal = defineAsyncComponent(() => import('@/Components/BarcodeScannerModal.vue'));

const { t } = useI18n();

const props = defineProps({
    locations: Array,
    products: Array,
    // Null when the user may send from any location; otherwise the ids of
    // locations in the warehouses they are assigned to.
    sourceLocationIds: { type: Array, default: null },
});

const availableFromLocations = computed(() => {
    if (!props.sourceLocationIds) return props.locations;
    return props.locations.filter(loc => props.sourceLocationIds.includes(loc.id));
});

const form = useForm({
    from_location_id: '',
    to_location_id: '',
    notes: '',
    items: [
        { product_id: '', product_variant_id: '', quantity: 1, notes: '' },
    ],
});
const { errorSummary, errorId, fieldErrorAttrs, focusErrors } = useFormFeedback(form);
const errorTargets = computed(() => ({
    from_location_id: fieldIdPrefix + '-1',
    to_location_id: fieldIdPrefix + '-2',
    notes: fieldIdPrefix + '-3',
    items: fieldIdPrefix + '-repeat-1-0',
    ...Object.fromEntries(form.items.flatMap((_, index) => [[`items.${index}.product_id`, fieldIdPrefix + '-repeat-1-' + index], [`items.${index}.product_variant_id`, fieldIdPrefix + '-repeat-2-' + index], [`items.${index}.quantity`, fieldIdPrefix + '-repeat-3-' + index], [`items.${index}.notes`, fieldIdPrefix + '-repeat-4-' + index]])),
}));


const sourceStock = ref(null);
const sourceStockState = ref('idle');
let stockRequest;
const loadSourceStock = async locationId => {
    stockRequest?.abort();
    sourceStock.value = null;
    sourceStockState.value = locationId ? 'loading' : 'idle';
    if (!locationId) return;
    const controller = new AbortController();
    stockRequest = controller;
    try {
        const { data } = await axios.get(route('stock-transfers.source-stock'), { params: { location_id: locationId }, signal: controller.signal });
        if (controller.signal.aborted) return;
        sourceStock.value = data.data;
        sourceStockState.value = 'ready';
    } catch (error) {
        if (!controller.signal.aborted) sourceStockState.value = 'error';
    }
};
watch(() => form.from_location_id, loadSourceStock);
onBeforeUnmount(() => stockRequest?.abort());
const stockKey = (productId, variantId = null) => `${Number(productId)}:${Number(variantId) || 'base'}`;
const sourceBalances = computed(() => new Map((sourceStock.value || []).map(row => [stockKey(row.product_id, row.product_variant_id), row.available])));
const sourceAvailable = (productId, variantId = null) => sourceStock.value === null ? null
    : sourceBalances.value.get(stockKey(productId, variantId)) ?? 0;
const stockByProduct = computed(() => sourceStock.value === null ? null : Object.fromEntries(props.products.map(product => [product.id, sourceAvailable(product.id)])));
const sourceStockMessage = computed(() => t(`components.productPicker.${({ idle: 'chooseSource', loading: 'loadingSource', error: 'sourceError', ready: 'atSource' })[sourceStockState.value]}`));

const addItem = () => {
    form.items.push({ product_id: '', product_variant_id: '', quantity: 1, notes: '' });
};

const removeItem = (index) => {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
    }
};

const availableToLocations = computed(() => {
    return props.locations.filter(loc => loc.id !== parseInt(form.from_location_id));
});

const getProduct = (productId) => {
    return props.products.find(p => p.id === parseInt(productId));
};

const totalItems = computed(() => {
    return form.items.reduce((sum, item) => sum + (parseInt(item.quantity) || 0), 0);
});

const hasValidItems = computed(() => {
    return form.items.some(item => item.product_id && item.quantity > 0);
});

// --- Scanning ---------------------------------------------------------
// Scan (camera modal or keyboard-wedge scanner) to add a product line, or
// bump the quantity when the product is already on the transfer. Transfers
// retain the scanned variant SKU on its own line.
const showScanner = ref(false);
const scanMessage = ref('');
const scanMessageTone = ref('info');
const { lookup } = useBarcodeLookup();

const addScannedProduct = (product, variant = null) => {
    showScanner.value = false;
    const known = props.products.find(p => p.id === product.id);
    if (!known) {
        scanMessage.value = t('scanning.notTransferable', { name: product.name });
        scanMessageTone.value = 'warning';
        return;
    }
    if (known.has_variants && !variant) {
        scanMessage.value = t('orders.create.chooseVariant');
        scanMessageTone.value = 'warning';
        return;
    }
    const matches = item => parseInt(item.product_id) === known.id && (Number(item.product_variant_id) || null) === (variant?.id || null);
    const existing = form.items.find(matches);
    if (existing) {
        existing.quantity = (parseInt(existing.quantity) || 0) + 1;
    } else {
        const blank = form.items.find(item => !item.product_id);
        if (blank) {
            blank.product_id = known.id;
            blank.product_variant_id = variant?.id || '';
            blank.quantity = 1;
        } else {
            form.items.push({ product_id: known.id, product_variant_id: variant?.id || '', quantity: 1, notes: '' });
        }
    }
    const line = form.items.find(matches);
    scanMessage.value = t('scanning.addedToLine', { name: known.name, quantity: line.quantity });
    scanMessageTone.value = 'success';
};

const onScannedCode = async (code) => {
    try {
        const found = await lookup(code);
        if (!found) {
            scanMessage.value = t('scanning.notFound', { code });
            scanMessageTone.value = 'danger';
            return;
        }
        addScannedProduct(found.product, found.variant);
    } catch (error) {
        scanMessage.value = t('scanning.lookupFailed');
        scanMessageTone.value = 'danger';
    }
};

useBarcodeWedge(onScannedCode, { enabled: () => !showScanner.value });

const submit = () => {
    if (form.processing) return;
    form.post(route('stock-transfers.store'), {
        preserveScroll: true,
        onError: focusErrors,
    });
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('stockTransfers.create.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link data-crumb-root :href="route('stock-transfers.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('stock-transfers.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.stockTransfers') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('common.new') }}</span>
            </div>
        </template>

        <PageHeader :title="t('stockTransfers.create.title')" :description="t('stockTransfers.subtitle')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-transfers.index')">
                    <ArrowLeft :size="14" />
                    {{ t('stockTransfers.backToList') }}
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6 space-y-4">
            <FormErrorSummary ref="errorSummary" :errors="form.errors" :targets="errorTargets" />
            <!-- Location Selection -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('stockTransfers.transferDetails') }}</h3></div>
                <div class="p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <!-- From Location -->
                        <div>
                            <label :for="fieldIdPrefix + '-1'" :class="fieldLabel">{{ t('stockTransfers.fromLocation') }} <span class="text-status-danger">*</span></label>
                            <select :id="fieldIdPrefix + '-1'"
                                v-model="form.from_location_id"
                                required
                                :class="fieldInput"
                                v-bind="fieldErrorAttrs('from_location_id')">
                                <option value="">{{ t('stockTransfers.create.selectSource') }}</option>
                                <option v-for="location in availableFromLocations" :key="location.id" :value="location.id">
                                    {{ location.name }} {{ location.code ? `(${location.code})` : '' }}
                                </option>
                            </select>
                            <p v-if="form.errors.from_location_id" :class="fieldError" :id="errorId('from_location_id')">
                                {{ form.errors.from_location_id }}
                            </p>
                        </div>

                        <!-- To Location -->
                        <div>
                            <label :for="fieldIdPrefix + '-2'" :class="fieldLabel">{{ t('stockTransfers.toLocation') }} <span class="text-status-danger">*</span></label>
                            <select :id="fieldIdPrefix + '-2'"
                                v-model="form.to_location_id"
                                required
                                :class="fieldInput"
                                v-bind="fieldErrorAttrs('to_location_id')">
                                <option value="">{{ t('stockTransfers.create.selectDestination') }}</option>
                                <option v-for="location in availableToLocations" :key="location.id" :value="location.id">
                                    {{ location.name }} {{ location.code ? `(${location.code})` : '' }}
                                </option>
                            </select>
                            <p v-if="form.errors.to_location_id" :class="fieldError" :id="errorId('to_location_id')">
                                {{ form.errors.to_location_id }}
                            </p>
                        </div>
                    </div>

                    <!-- Arrow indicator between locations -->
                    <div v-if="form.from_location_id && form.to_location_id" class="mt-4 rounded-lg border border-border-subtle bg-surface-canvas p-3">
                        <div class="flex items-center justify-center gap-3 text-sm text-text-secondary">
                            <span class="font-medium text-text-primary">{{ locations.find(l => l.id === parseInt(form.from_location_id))?.name }}</span>
                            <ArrowRight :size="18" class="text-text-tertiary" />
                            <span class="font-medium text-text-primary">{{ locations.find(l => l.id === parseInt(form.to_location_id))?.name }}</span>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="mt-4">
                        <label :for="fieldIdPrefix + '-3'" :class="fieldLabel">{{ t('stockAdjustments.create.notesOptional') }}</label>
                        <textarea :id="fieldIdPrefix + '-3'"
                            v-model="form.notes"
                            rows="3"
                            :class="fieldArea"
                            :placeholder="t('stockTransfers.create.notesPlaceholder')"
                            v-bind="fieldErrorAttrs('notes')"></textarea>
                        <p v-if="form.errors.notes" :class="fieldError" :id="errorId('notes')">
                            {{ form.errors.notes }}
                        </p>
                    </div>
                </div>
            </Card>

            <!-- Transfer Items -->
            <Card :padded="false">
                <div class="flex flex-wrap items-center justify-between gap-2 px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('stockTransfers.transferItems') }}</h3>
                    <div class="flex flex-wrap gap-2">
                        <Button type="button" variant="secondary" size="lg" class="min-h-11" @click="showScanner = true">
                            <ScanLine :size="16" />
                            {{ t('scanning.scan') }}
                        </Button>
                        <Button type="button" variant="default" size="lg" class="min-h-11" @click="addItem">
                            <Plus :size="14" />
                            {{ t('stockTransfers.create.addItem') }}
                        </Button>
                    </div>
                </div>
                <div class="px-5 pt-2">
                    <p class="text-xs text-text-tertiary">{{ t('scanning.transferCreateHint') }}</p>
                    <p
                        v-if="scanMessage"
                        class="mt-1 text-sm font-medium"
                        :class="{
                            'text-status-success': scanMessageTone === 'success',
                            'text-status-warning': scanMessageTone === 'warning',
                            'text-status-danger': scanMessageTone === 'danger',
                        }"
                        role="status"
                        aria-live="polite"
                    >
                        {{ scanMessage }}
                    </p>
                </div>
                <div class="p-5">
                    <p v-if="form.errors.items" :class="fieldError" class="mb-4" :id="errorId('items')">
                        {{ form.errors.items }}
                    </p>

                    <div class="space-y-3">
                        <div
                            v-for="(item, index) in form.items"
                            :key="index"
                            class="flex flex-wrap items-start gap-4 rounded-lg border border-border-subtle bg-surface-canvas p-4"
                        >
                            <!-- Product -->
                            <div class="min-w-0 flex-1 basis-full sm:basis-auto sm:min-w-[200px]">
                                <label :for="fieldIdPrefix + '-repeat-1-' + index" :class="fieldLabel">{{ t('common.product') }} <span class="text-status-danger">*</span></label>
                                <ProductPicker :id="fieldIdPrefix + '-repeat-1-' + index" v-model="item.product_id" :products="products" :stock-by-product="stockByProduct" :stock-label="sourceStockState === 'ready' ? t('components.productPicker.atSource') : null" required :invalid="Boolean(form.errors[`items.${index}.product_id`])" :describedby="form.errors[`items.${index}.product_id`] ? errorId(`items.${index}.product_id`) : null" @change="item.product_variant_id = ''" />
                                <p v-if="form.errors[`items.${index}.product_id`]" :class="fieldError" :id="errorId(`items.${index}.product_id`)">
                                    {{ form.errors[`items.${index}.product_id`] }}
                                </p>
                            </div>

                            <div v-if="getProduct(item.product_id)?.active_variants?.length" class="min-w-48 flex-1">
                                <label :for="fieldIdPrefix + '-repeat-2-' + index" :class="fieldLabel">{{ t('orders.create.variant') }}</label>
                                <select :id="fieldIdPrefix + '-repeat-2-' + index" v-model="item.product_variant_id" v-bind="fieldErrorAttrs(`items.${index}.product_variant_id`)" required :class="fieldInput">
                                    <option value="">{{ t('orders.create.chooseVariant') }}</option>
                                    <option v-for="variant in getProduct(item.product_id).active_variants" :key="variant.id" :value="variant.id">{{ variant.sku || variant.title }}<template v-if="sourceStockState === 'ready'"> · {{ t('components.productPicker.atSource') }}: {{ sourceAvailable(item.product_id, variant.id) }}</template></option>
                                </select>
                                <p v-if="form.errors[`items.${index}.product_variant_id`]" :class="fieldError" :id="errorId(`items.${index}.product_variant_id`)">{{ form.errors[`items.${index}.product_variant_id`] }}</p>
                            </div>
                            <!-- Quantity -->
                            <div class="w-32">
                                <label :for="fieldIdPrefix + '-repeat-3-' + index" :class="fieldLabel">{{ t('stockTransfers.create.qty') }} <span class="text-status-danger">*</span></label>
                                <input :id="fieldIdPrefix + '-repeat-3-' + index"
                                    v-model="item.quantity"
                                    type="number"
                                    min="1"
                                    required
                                    :class="fieldInput"
                                    v-bind="fieldErrorAttrs(`items.${index}.quantity`)" />
                                <p v-if="item.product_id" class="mt-1 text-xs text-text-secondary" role="status">
                                    {{ sourceStockMessage }}<template v-if="sourceStockState === 'ready' && (!getProduct(item.product_id)?.has_variants || item.product_variant_id)">: {{ sourceAvailable(item.product_id, item.product_variant_id) }}</template>
                                </p>
                                <Button v-if="index === 0 && sourceStockState === 'error'" type="button" variant="secondary" size="sm" class="mt-2" @click="loadSourceStock(form.from_location_id)">{{ t('common.retry') }}</Button>
                                <p v-if="form.errors[`items.${index}.quantity`]" :class="fieldError" :id="errorId(`items.${index}.quantity`)">
                                    {{ form.errors[`items.${index}.quantity`] }}
                                </p>
                            </div>

                            <!-- Notes -->
                            <div class="min-w-0 flex-1 sm:min-w-[150px]">
                                <label :for="fieldIdPrefix + '-repeat-4-' + index" :class="fieldLabel">{{ t('common.notes') }}</label>
                                <input :id="fieldIdPrefix + '-repeat-4-' + index"
                                    v-model="item.notes"
                                    type="text"
                                    :class="fieldInput"
                                    :placeholder="t('stockTransfers.create.itemNotesPlaceholder')"
                                    v-bind="fieldErrorAttrs(`items.${index}.notes`)" />
                            </div>

                            <!-- Remove button -->
                            <div class="pt-7">
                                <button :aria-label="t('common.delete')"
                                    type="button"
                                    @click="removeItem(index)"
                                    :disabled="form.items.length <= 1"
                                    class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-danger disabled:cursor-not-allowed disabled:opacity-30"
                                >
                                    <Trash2 :size="16" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Summary -->
                    <div v-if="hasValidItems" class="mt-4 rounded-lg border border-border-subtle bg-surface-canvas p-4">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-text-secondary">
                                {{ t('stockTransfers.create.totalProducts', { count: form.items.filter(i => i.product_id).length }, form.items.filter(i => i.product_id).length) }}
                            </span>
                            <span class="text-sm font-medium text-text-secondary">
                                {{ t('stockTransfers.create.totalUnits', { count: totalItems }, totalItems) }}
                            </span>
                        </div>
                    </div>
                </div>
            </Card>

            <FormActions :label="t('stockTransfers.create.createTransfer')" :cancel-href="route('stock-transfers.index')" :processing="form.processing" :disabled="!form.from_location_id || !form.to_location_id || !hasValidItems" />
        </form>

        <!-- Barcode Scanner Modal -->
        <BarcodeScannerModal
            :show="showScanner"
            @close="showScanner = false"
            @product-found="addScannedProduct"
        />
    </AppLayout>
</template>
