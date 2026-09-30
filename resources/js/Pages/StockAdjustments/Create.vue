<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { defineAsyncComponent, computed, watch, ref, nextTick } from 'vue';
import { ScanLine, AlertTriangle } from 'lucide-vue-next';

const BarcodeScannerModal = defineAsyncComponent(() => import('@/Components/BarcodeScannerModal.vue'));
import { useI18n } from 'vue-i18n';

const { t, te } = useI18n();

const props = defineProps({
    products: Array,
    types: Object,
    locations: { type: Array, default: () => [] },
    preselectedProductId: { type: Number, default: null },
});

const form = useForm({
    product_id: props.products?.some(p => p.id === props.preselectedProductId) ? props.preselectedProductId : '',
    product_variant_id: null,
    type: 'manual',
    adjustment_quantity: 0,
    location_id: null,
    reason: '',
    notes: '',
});

// Barcode scanner state
const showScannerModal = ref(false);

const openScanner = () => {
    showScannerModal.value = true;
};

const closeScanner = () => {
    showScannerModal.value = false;
};

// A scanned variant barcode arrives with its variant, so the adjustment
// targets that variant directly; a product code leaves the variant to pick.
const handleProductFound = (product, variant = null) => {
    form.product_id = product.id;
    form.product_variant_id = variant?.id ?? null;
    closeScanner();

    // Focus next field (adjustment quantity)
    nextTick(() => {
        const qtyInput = document.querySelector('input[type="number"]');
        if (qtyInput) qtyInput.focus();
    });
};

const selectedProduct = computed(() => {
    return props.products.find(p => p.id === form.product_id);
});

const selectedVariant = computed(() =>
    selectedProduct.value?.variants?.find(v => v.id === form.product_variant_id) ?? null
);

// Picking a different product clears a variant that belonged to the old one.
watch(() => form.product_id, () => {
    if (form.product_variant_id && !selectedVariant.value) {
        form.product_variant_id = null;
    }
});

// Variant stock is not tracked by location, so a variant clears the location.
watch(() => form.product_variant_id, (variantId) => {
    if (variantId) {
        form.location_id = null;
    }
});

// The count being adjusted: the variant's when one is chosen. A product sold
// by variant has no count of its own until a variant is picked.
const currentStock = computed(() => {
    if (selectedVariant.value) return selectedVariant.value.stock;
    if (selectedProduct.value?.has_variants) return null;
    return selectedProduct.value?.stock ?? null;
});

const newStock = computed(() => {
    if (currentStock.value === null) return 0;
    return currentStock.value + parseInt(form.adjustment_quantity || 0);
});

const adjustmentType = computed(() => {
    if (form.adjustment_quantity > 0) return 'increase';
    if (form.adjustment_quantity < 0) return 'decrease';
    return 'none';
});

watch(() => form.adjustment_quantity, (newVal) => {
    // Auto-select reason based on adjustment type
    if (newVal > 0 && !form.reason) {
        form.reason = t('stockAdjustments.create.stockIncrease');
    } else if (newVal < 0 && !form.reason) {
        form.reason = t('stockAdjustments.create.stockDecrease');
    }
});

const typeLabel = (value, label) => (te(`stockAdjustments.types.${value}`) ? t(`stockAdjustments.types.${value}`) : label);

const submit = () => {
    form.post(route('stock-adjustments.store'), {
        preserveScroll: true,
    });
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('stockAdjustments.create.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('stock-adjustments.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('stock-adjustments.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.stockAdjustments') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('common.new') }}</span>
            </div>
        </template>

        <PageHeader :title="t('stockAdjustments.create.title')" :description="t('stockAdjustments.create.subtitle')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-adjustments.index')">
                    {{ t('stockAdjustments.create.backToList') }}
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6">
            <div class="mx-auto max-w-3xl">
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('stockAdjustments.create.adjustmentDetails') }}</h3></div>
                    <div class="space-y-6 p-5">
                        <!-- Product Selection -->
                        <div>
                            <label :class="fieldLabel">
                                {{ t('common.product') }} <span class="text-status-danger">*</span>
                            </label>
                            <div class="relative">
                                <select
                                    v-model="form.product_id"
                                    required
                                    :class="[fieldInput, 'pr-12', { 'border-status-danger': form.errors.product_id }]"
                                >
                                    <option value="">{{ t('stockAdjustments.create.selectProduct') }}</option>
                                    <option v-for="product in products" :key="product.id" :value="product.id">
                                        {{ product.name }} ({{ product.sku }}) - {{ t('stockAdjustments.create.currentStockValue', { stock: product.stock }) }}
                                    </option>
                                </select>
                                <!-- Scan Icon Button -->
                                <button
                                    v-if="$page.props.auth.permissions.includes('stock_adjustments.create')"
                                    type="button"
                                    @click="openScanner"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-text-tertiary transition-colors hover:text-brand"
                                    :title="t('stockAdjustments.create.scanBarcode')"
                                >
                                    <ScanLine :size="18" />
                                </button>
                            </div>
                            <p v-if="form.errors.product_id" :class="fieldError">
                                {{ form.errors.product_id }}
                            </p>
                        </div>

                        <!-- Variant (products sold by variant) -->
                        <div v-if="selectedProduct?.has_variants">
                            <label for="product_variant_id" :class="fieldLabel">
                                {{ t('orders.create.variant') }} <span class="text-status-danger">*</span>
                            </label>
                            <select
                                id="product_variant_id"
                                v-model="form.product_variant_id"
                                required
                                :class="[fieldInput, { 'border-status-danger': form.errors.product_variant_id }]"
                            >
                                <option :value="null">{{ t('orders.create.chooseVariant') }}</option>
                                <option v-for="variant in selectedProduct.variants" :key="variant.id" :value="variant.id">
                                    {{ variant.title }}<template v-if="variant.sku"> ({{ variant.sku }})</template> - {{ t('stockAdjustments.create.currentStockValue', { stock: variant.stock }) }}
                                </option>
                            </select>
                            <p v-if="form.errors.product_variant_id" :class="fieldError">
                                {{ form.errors.product_variant_id }}
                            </p>
                        </div>

                        <!-- Current Stock Display -->
                        <div v-if="currentStock !== null" class="rounded-lg border border-status-info/20 bg-status-info-soft p-4">
                            <div class="grid grid-cols-3 gap-4 text-center">
                                <div>
                                    <p class="mb-1 text-sm text-text-secondary">{{ t('stockAdjustments.create.currentStock') }}</p>
                                    <p class="text-2xl font-bold text-text-primary">{{ currentStock }}</p>
                                </div>
                                <div>
                                    <p class="mb-1 text-sm text-text-secondary">{{ t('stockAdjustments.create.adjustment') }}</p>
                                    <p class="text-2xl font-bold" :class="{
                                        'text-status-success': adjustmentType === 'increase',
                                        'text-status-danger': adjustmentType === 'decrease',
                                        'text-text-secondary': adjustmentType === 'none'
                                    }">
                                        {{ form.adjustment_quantity > 0 ? '+' : '' }}{{ form.adjustment_quantity || 0 }}
                                    </p>
                                </div>
                                <div>
                                    <p class="mb-1 text-sm text-text-secondary">{{ t('stockAdjustments.create.newStock') }}</p>
                                    <p class="text-2xl font-bold text-text-primary">{{ newStock }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Adjustment Type -->
                        <div>
                            <label :class="fieldLabel">
                                {{ t('common.type') }} <span class="text-status-danger">*</span>
                            </label>
                            <select
                                v-model="form.type"
                                required
                                :class="[fieldInput, { 'border-status-danger': form.errors.type }]"
                            >
                                <option v-for="(label, value) in types" :key="value" :value="value">{{ typeLabel(value, label) }}</option>
                            </select>
                            <p v-if="form.errors.type" :class="fieldError">
                                {{ form.errors.type }}
                            </p>
                        </div>

                        <!-- Location (optional) -->
                        <div v-if="locations.length && !form.product_variant_id">
                            <label :class="fieldLabel">{{ t('stockAdjustments.create.locationOptional') }}</label>
                            <select
                                v-model="form.location_id"
                                :class="[fieldInput, { 'border-status-danger': form.errors.location_id }]"
                            >
                                <option :value="null">{{ t('stockAdjustments.create.noSpecificLocation') }}</option>
                                <option v-for="location in locations" :key="location.id" :value="location.id">
                                    {{ location.name }}<template v-if="location.code"> ({{ location.code }})</template>
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-text-tertiary">
                                {{ t('stockAdjustments.create.locationHint') }}
                            </p>
                            <p v-if="form.errors.location_id" :class="fieldError">
                                {{ form.errors.location_id }}
                            </p>
                        </div>

                        <!-- Adjustment Quantity -->
                        <div>
                            <label :class="fieldLabel">
                                {{ t('stockAdjustments.create.adjustmentQuantity') }} <span class="text-status-danger">*</span>
                            </label>
                            <input
                                v-model="form.adjustment_quantity"
                                type="number"
                                required
                                step="1"
                                :class="[fieldInput, { 'border-status-danger': form.errors.adjustment_quantity }]"
                                :placeholder="t('stockAdjustments.create.quantityPlaceholder')"
                            />
                            <p class="mt-1 text-xs text-text-tertiary">
                                {{ t('stockAdjustments.create.adjustmentHint') }}
                            </p>
                            <p v-if="form.errors.adjustment_quantity" :class="fieldError">
                                {{ form.errors.adjustment_quantity }}
                            </p>
                        </div>

                        <!-- Reason -->
                        <div>
                            <label :class="fieldLabel">
                                {{ t('stockAdjustments.create.reason') }} <span class="text-status-danger">*</span>
                            </label>
                            <input
                                v-model="form.reason"
                                type="text"
                                required
                                maxlength="255"
                                :class="[fieldInput, { 'border-status-danger': form.errors.reason }]"
                                :placeholder="t('stockAdjustments.create.reasonPlaceholder')"
                            />
                            <p v-if="form.errors.reason" :class="fieldError">
                                {{ form.errors.reason }}
                            </p>
                        </div>

                        <!-- Notes -->
                        <div>
                            <label :class="fieldLabel">
                                {{ t('stockAdjustments.create.notesOptional') }}
                            </label>
                            <textarea
                                v-model="form.notes"
                                rows="4"
                                :class="[fieldArea, { 'border-status-danger': form.errors.notes }]"
                                :placeholder="t('stockAdjustments.create.notesPlaceholder')"
                            ></textarea>
                            <p v-if="form.errors.notes" :class="fieldError">
                                {{ form.errors.notes }}
                            </p>
                        </div>

                        <!-- Warning for negative adjustments -->
                        <div v-if="form.adjustment_quantity < 0 && currentStock !== null && newStock < 0" class="rounded-lg border border-status-danger/20 bg-status-danger-soft p-4">
                            <div class="flex gap-3">
                                <AlertTriangle :size="20" class="shrink-0 text-status-danger" />
                                <div>
                                    <p class="text-sm font-medium text-status-danger">{{ t('stockAdjustments.create.negativeWarning') }}</p>
                                    <p class="mt-1 text-xs text-text-secondary">
                                        {{ t('stockAdjustments.create.negativeWarningValue', { stock: newStock }) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-end gap-3 border-t border-border-subtle p-5">
                        <Button variant="secondary" as="Link" :href="route('stock-adjustments.index')">
                            {{ t('common.cancel') }}
                        </Button>
                        <Button
                            type="submit"
                            variant="default"
                            :loading="form.processing"
                            :disabled="form.processing || !form.product_id || (selectedProduct?.has_variants && !form.product_variant_id) || form.adjustment_quantity === 0"
                        >
                            {{ form.processing ? t('common.creating') : t('stockAdjustments.create.createAdjustment') }}
                        </Button>
                    </div>
                </Card>
            </div>
        </form>

        <!-- Barcode Scanner Modal -->
        <BarcodeScannerModal
            :show="showScannerModal"
            @close="closeScanner"
            @product-found="handleProductFound"
        />
    </AppLayout>
</template>
