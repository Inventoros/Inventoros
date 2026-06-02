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

const { t } = useI18n();

const props = defineProps({
    products: Array,
    types: Object,
});

const form = useForm({
    product_id: '',
    type: 'manual',
    adjustment_quantity: 0,
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

const handleProductFound = (product) => {
    form.product_id = product.id;
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

const newStock = computed(() => {
    if (!selectedProduct.value) return 0;
    return selectedProduct.value.stock + parseInt(form.adjustment_quantity || 0);
});

const adjustmentType = computed(() => {
    if (form.adjustment_quantity > 0) return 'increase';
    if (form.adjustment_quantity < 0) return 'decrease';
    return 'none';
});

watch(() => form.adjustment_quantity, (newVal) => {
    // Auto-select reason based on adjustment type
    if (newVal > 0 && !form.reason) {
        form.reason = 'Stock increase';
    } else if (newVal < 0 && !form.reason) {
        form.reason = 'Stock decrease';
    }
});

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
    <Head title="Create Stock Adjustment" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('stock-adjustments.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('stock-adjustments.index')" class="text-text-tertiary hover:text-text-primary">Stock Adjustments</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">New</span>
            </div>
        </template>

        <PageHeader title="Create Stock Adjustment" description="Manually adjust product stock levels">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-adjustments.index')">
                    Back to List
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6">
            <div class="mx-auto max-w-3xl">
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Adjustment Details</h3></div>
                    <div class="space-y-6 p-5">
                        <!-- Product Selection -->
                        <div>
                            <label :class="fieldLabel">
                                Product <span class="text-status-danger">*</span>
                            </label>
                            <div class="relative">
                                <select
                                    v-model="form.product_id"
                                    required
                                    :class="[fieldInput, 'pr-12', { 'border-status-danger': form.errors.product_id }]"
                                >
                                    <option value="">Select a product</option>
                                    <option v-for="product in products" :key="product.id" :value="product.id">
                                        {{ product.name }} ({{ product.sku }}) - Current Stock: {{ product.stock }}
                                    </option>
                                </select>
                                <!-- Scan Icon Button -->
                                <button
                                    v-if="$page.props.auth.permissions.includes('stock_adjustments.create')"
                                    type="button"
                                    @click="openScanner"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-text-tertiary transition-colors hover:text-brand"
                                    title="Scan barcode to find product"
                                >
                                    <ScanLine :size="18" />
                                </button>
                            </div>
                            <p v-if="form.errors.product_id" :class="fieldError">
                                {{ form.errors.product_id }}
                            </p>
                        </div>

                        <!-- Current Stock Display -->
                        <div v-if="selectedProduct" class="rounded-lg border border-status-info/20 bg-status-info-soft p-4">
                            <div class="grid grid-cols-3 gap-4 text-center">
                                <div>
                                    <p class="mb-1 text-sm text-text-secondary">Current Stock</p>
                                    <p class="text-2xl font-bold text-text-primary">{{ selectedProduct.stock }}</p>
                                </div>
                                <div>
                                    <p class="mb-1 text-sm text-text-secondary">Adjustment</p>
                                    <p class="text-2xl font-bold" :class="{
                                        'text-status-success': adjustmentType === 'increase',
                                        'text-status-danger': adjustmentType === 'decrease',
                                        'text-text-secondary': adjustmentType === 'none'
                                    }">
                                        {{ form.adjustment_quantity > 0 ? '+' : '' }}{{ form.adjustment_quantity || 0 }}
                                    </p>
                                </div>
                                <div>
                                    <p class="mb-1 text-sm text-text-secondary">New Stock</p>
                                    <p class="text-2xl font-bold text-text-primary">{{ newStock }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Adjustment Type -->
                        <div>
                            <label :class="fieldLabel">
                                Type <span class="text-status-danger">*</span>
                            </label>
                            <select
                                v-model="form.type"
                                required
                                :class="[fieldInput, { 'border-status-danger': form.errors.type }]"
                            >
                                <option v-for="(label, value) in types" :key="value" :value="value">{{ label }}</option>
                            </select>
                            <p v-if="form.errors.type" :class="fieldError">
                                {{ form.errors.type }}
                            </p>
                        </div>

                        <!-- Adjustment Quantity -->
                        <div>
                            <label :class="fieldLabel">
                                Adjustment Quantity <span class="text-status-danger">*</span>
                            </label>
                            <input
                                v-model="form.adjustment_quantity"
                                type="number"
                                required
                                step="1"
                                :class="[fieldInput, { 'border-status-danger': form.errors.adjustment_quantity }]"
                                placeholder="Enter positive number to add, negative to subtract"
                            />
                            <p class="mt-1 text-xs text-text-tertiary">
                                Use positive numbers to increase stock (+10), negative to decrease stock (-5)
                            </p>
                            <p v-if="form.errors.adjustment_quantity" :class="fieldError">
                                {{ form.errors.adjustment_quantity }}
                            </p>
                        </div>

                        <!-- Reason -->
                        <div>
                            <label :class="fieldLabel">
                                Reason <span class="text-status-danger">*</span>
                            </label>
                            <input
                                v-model="form.reason"
                                type="text"
                                required
                                maxlength="255"
                                :class="[fieldInput, { 'border-status-danger': form.errors.reason }]"
                                placeholder="e.g., Damaged items, Inventory recount, Customer return"
                            />
                            <p v-if="form.errors.reason" :class="fieldError">
                                {{ form.errors.reason }}
                            </p>
                        </div>

                        <!-- Notes -->
                        <div>
                            <label :class="fieldLabel">
                                Notes (Optional)
                            </label>
                            <textarea
                                v-model="form.notes"
                                rows="4"
                                :class="[fieldArea, { 'border-status-danger': form.errors.notes }]"
                                placeholder="Add any additional details about this adjustment..."
                            ></textarea>
                            <p v-if="form.errors.notes" :class="fieldError">
                                {{ form.errors.notes }}
                            </p>
                        </div>

                        <!-- Warning for negative adjustments -->
                        <div v-if="form.adjustment_quantity < 0 && selectedProduct && newStock < 0" class="rounded-lg border border-status-danger/20 bg-status-danger-soft p-4">
                            <div class="flex gap-3">
                                <AlertTriangle :size="20" class="shrink-0 text-status-danger" />
                                <div>
                                    <p class="text-sm font-medium text-status-danger">Warning: Negative Stock</p>
                                    <p class="mt-1 text-xs text-text-secondary">
                                        This adjustment will result in negative stock ({{ newStock }}). Please verify the quantity.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-end gap-3 border-t border-border-subtle p-5">
                        <Button variant="secondary" as="Link" :href="route('stock-adjustments.index')">
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="default"
                            :loading="form.processing"
                            :disabled="form.processing || !form.product_id || form.adjustment_quantity === 0"
                        >
                            {{ form.processing ? 'Creating...' : 'Create Adjustment' }}
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
