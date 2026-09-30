<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, defineAsyncComponent, ref } from 'vue';
import { useBarcodeWedge, useBarcodeLookup } from '@/composables/useBarcodeWedge';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, ArrowRight, Plus, Trash2, ScanLine } from 'lucide-vue-next';

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
        { product_id: '', quantity: 1, notes: '' },
    ],
});

const addItem = () => {
    form.items.push({ product_id: '', quantity: 1, notes: '' });
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
// move whole products, so a variant barcode resolves to its product.
const showScanner = ref(false);
const scanMessage = ref('');
const scanMessageTone = ref('info');
const { lookup } = useBarcodeLookup();

const addScannedProduct = (product) => {
    showScanner.value = false;
    const known = props.products.find(p => p.id === product.id);
    if (!known) {
        scanMessage.value = t('scanning.notTransferable', { name: product.name });
        scanMessageTone.value = 'warning';
        return;
    }
    const existing = form.items.find(item => parseInt(item.product_id) === known.id);
    if (existing) {
        existing.quantity = (parseInt(existing.quantity) || 0) + 1;
    } else {
        const blank = form.items.find(item => !item.product_id);
        if (blank) {
            blank.product_id = known.id;
            blank.quantity = 1;
        } else {
            form.items.push({ product_id: known.id, quantity: 1, notes: '' });
        }
    }
    const line = form.items.find(item => parseInt(item.product_id) === known.id);
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
        addScannedProduct(found.product);
    } catch (error) {
        scanMessage.value = t('scanning.lookupFailed');
        scanMessageTone.value = 'danger';
    }
};

useBarcodeWedge(onScannedCode, { enabled: () => !showScanner.value });

const submit = () => {
    form.post(route('stock-transfers.store'), {
        preserveScroll: true,
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
                <Link :href="route('stock-transfers.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
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
            <!-- Location Selection -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('stockTransfers.transferDetails') }}</h3></div>
                <div class="p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <!-- From Location -->
                        <div>
                            <label :class="fieldLabel">{{ t('stockTransfers.fromLocation') }} <span class="text-status-danger">*</span></label>
                            <select
                                v-model="form.from_location_id"
                                required
                                :class="fieldInput"
                            >
                                <option value="">{{ t('stockTransfers.create.selectSource') }}</option>
                                <option v-for="location in availableFromLocations" :key="location.id" :value="location.id">
                                    {{ location.name }} {{ location.code ? `(${location.code})` : '' }}
                                </option>
                            </select>
                            <p v-if="form.errors.from_location_id" :class="fieldError">
                                {{ form.errors.from_location_id }}
                            </p>
                        </div>

                        <!-- To Location -->
                        <div>
                            <label :class="fieldLabel">{{ t('stockTransfers.toLocation') }} <span class="text-status-danger">*</span></label>
                            <select
                                v-model="form.to_location_id"
                                required
                                :class="fieldInput"
                            >
                                <option value="">{{ t('stockTransfers.create.selectDestination') }}</option>
                                <option v-for="location in availableToLocations" :key="location.id" :value="location.id">
                                    {{ location.name }} {{ location.code ? `(${location.code})` : '' }}
                                </option>
                            </select>
                            <p v-if="form.errors.to_location_id" :class="fieldError">
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
                        <label :class="fieldLabel">{{ t('stockAdjustments.create.notesOptional') }}</label>
                        <textarea
                            v-model="form.notes"
                            rows="3"
                            :class="fieldArea"
                            :placeholder="t('stockTransfers.create.notesPlaceholder')"
                        ></textarea>
                        <p v-if="form.errors.notes" :class="fieldError">
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
                    <p v-if="form.errors.items" :class="fieldError" class="mb-4">
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
                                <label :class="fieldLabel">{{ t('common.product') }} <span class="text-status-danger">*</span></label>
                                <select
                                    v-model="item.product_id"
                                    required
                                    :class="fieldInput"
                                >
                                    <option value="">{{ t('stockTransfers.create.selectProduct') }}</option>
                                    <option v-for="product in products" :key="product.id" :value="product.id">
                                        {{ t('stockTransfers.create.productOption', { name: product.name, sku: product.sku, stock: product.stock }) }}
                                    </option>
                                </select>
                                <p v-if="form.errors[`items.${index}.product_id`]" :class="fieldError">
                                    {{ form.errors[`items.${index}.product_id`] }}
                                </p>
                            </div>

                            <!-- Quantity -->
                            <div class="w-32">
                                <label :class="fieldLabel">{{ t('stockTransfers.create.qty') }} <span class="text-status-danger">*</span></label>
                                <input
                                    v-model="item.quantity"
                                    type="number"
                                    min="1"
                                    required
                                    :class="fieldInput"
                                />
                                <p v-if="item.product_id && getProduct(item.product_id)" class="mt-1 text-xs text-text-tertiary">
                                    {{ t('stockTransfers.create.available', { count: getProduct(item.product_id).stock }) }}
                                </p>
                                <p v-if="form.errors[`items.${index}.quantity`]" :class="fieldError">
                                    {{ form.errors[`items.${index}.quantity`] }}
                                </p>
                            </div>

                            <!-- Notes -->
                            <div class="min-w-0 flex-1 sm:min-w-[150px]">
                                <label :class="fieldLabel">{{ t('common.notes') }}</label>
                                <input
                                    v-model="item.notes"
                                    type="text"
                                    :class="fieldInput"
                                    :placeholder="t('stockTransfers.create.itemNotesPlaceholder')"
                                />
                            </div>

                            <!-- Remove button -->
                            <div class="pt-7">
                                <button
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

            <!-- Actions -->
            <div class="flex justify-end gap-3">
                <Button variant="secondary" as="Link" :href="route('stock-transfers.index')">{{ t('common.cancel') }}</Button>
                <Button
                    type="submit"
                    variant="default"
                    :loading="form.processing"
                    :disabled="form.processing || !form.from_location_id || !form.to_location_id || !hasValidItems"
                >
                    {{ form.processing ? t('common.creating') : t('stockTransfers.create.createTransfer') }}
                </Button>
            </div>
        </form>

        <!-- Barcode Scanner Modal -->
        <BarcodeScannerModal
            :show="showScanner"
            @close="showScanner = false"
            @product-found="addScannedProduct"
        />
    </AppLayout>
</template>
