<script setup>
import { useId as useFieldId } from 'vue';
const fieldIdPrefix = useFieldId();
import CurrencySelect from '@/Components/CurrencySelect.vue';
import { useCurrency } from '@/composables/useCurrency';

import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import FormActions from '@/Components/ui/FormActions.vue';
import FormErrorSummary from '@/Components/ui/FormErrorSummary.vue';
import { useFormFeedback } from '@/composables/useFormFeedback';
import ProductPicker from '@/Components/ui/ProductPicker.vue';
import TaxInput from '@/Components/TaxInput.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PurchaseOrderExchangeRate from '@/Components/PurchaseOrderExchangeRate.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { defineAsyncComponent, ref, computed, watch } from 'vue';
import { ArrowLeft, Plus, Trash2, ScanLine } from '@lucide/vue';

const BarcodeScannerModal = defineAsyncComponent(() => import('@/Components/BarcodeScannerModal.vue'));
import { useI18n } from 'vue-i18n';
import { defaultSupplierSku, defaultTaxRate, defaultUnitCost } from '@/lib/purchaseOrderLine';
import { formatTaxRate } from '@/lib/lineTax';
import { usePurchaseOrderTotals } from '@/composables/usePurchaseOrderTotals';
import { todayIsoDate } from '@/lib/dates';
import { formatMoney } from '@/lib/money';

const { organizationCurrency } = useCurrency();
const { t } = useI18n();

const props = defineProps({
    suppliers: Array,
    products: Array,
    exchangeRates: { type: Object, default: () => ({}) },
    pluginComponents: Object,
});

const form = useForm({
    supplier_id: '',
    order_date: todayIsoDate(),
    expected_date: '',
    currency: organizationCurrency.value,
    shipping: 0,
    tax: 0,
    notes: '',
    items: [],
    // Only sent when a permitted user enters (manual) or accepts (confirm)
    // a rate; otherwise the server applies the provider's rate.
    exchange_rate_mode: null,
    exchange_rate: null,
});
const { errorSummary, errorId, fieldErrorAttrs, focusErrors } = useFormFeedback(form);
const errorTargets = computed(() => ({
    supplier_id: 'supplier_id',
    currency: 'currency',
    order_date: 'order_date',
    expected_date: 'expected_date',
    notes: 'notes',
    shipping: 'shipping',
    tax: 'tax',
    items: 'product',
    ...Object.fromEntries(form.items.flatMap((_, index) => [[`items.${index}.quantity`, fieldIdPrefix + '-repeat-1-' + index], [`items.${index}.unit_cost`, fieldIdPrefix + '-repeat-2-' + index], [`items.${index}.tax_rate`, fieldIdPrefix + '-repeat-3-' + index]])),
}));


// The rate the PO will be saved at (for the organization-currency amounts).
const effectiveRate = ref(null);

const showScanner = ref(false);
const selectedProductId = ref('');
const selectedVariantId = ref(null);
const variantError = ref('');
const quantity = ref(1);
const unitCost = ref(0);
const supplierSku = ref('');

const selectedSupplier = computed(() => {
    return props.suppliers.find(s => s.id == form.supplier_id);
});

watch(() => form.supplier_id, (newVal) => {
    if (newVal && selectedSupplier.value) {
        form.currency = selectedSupplier.value.currency || organizationCurrency.value;
    }
});

// Net, tax per line and per rate, and gross, as the server will save them.
const totals = usePurchaseOrderTotals(form);

// An amount in the organization's currency at the rate the PO will take.
const converted = (value) => formatMoney(Number(value) * Number(effectiveRate.value), organizationCurrency.value);

const chosenProduct = computed(() => props.products.find(p => p.id == selectedProductId.value) ?? null);

const chosenVariants = computed(() =>
    chosenProduct.value?.has_variants ? chosenProduct.value.variants.filter(v => v.is_active) : []
);

const addItem = () => {
    const product = chosenProduct.value;
    if (!product || quantity.value < 1) return;

    // A product bought by variant needs one, so receiving credits that
    // variant's stock rather than the parent's.
    let variant = null;
    if (product.has_variants) {
        variant = product.variants.find(v => v.id === selectedVariantId.value) ?? null;
        if (!variant) {
            variantError.value = t('orders.create.variantRequired');
            return;
        }
    }
    const variantId = variant?.id ?? null;

    // Check if this product (and variant) already exists in items
    const existingIndex = form.items.findIndex(item => item.product_id == product.id && item.product_variant_id === variantId);
    if (existingIndex >= 0) {
        form.items[existingIndex].quantity += quantity.value;
        form.items[existingIndex].unit_cost = unitCost.value;
    } else {
        form.items.push({
            product_id: product.id,
            product_variant_id: variantId,
            product_name: product.name,
            variant_title: variant?.title ?? null,
            sku: variant?.sku || product.sku,
            quantity: quantity.value,
            unit_cost: unitCost.value || defaultUnitCost(product, variant, form.supplier_id),
            supplier_sku: supplierSku.value,
            // Starts at the product's rate (variants share it); editable,
            // blank for none.
            tax_rate: defaultTaxRate(product),
        });
    }

    // Reset inputs
    selectedProductId.value = '';
    selectedVariantId.value = null;
    variantError.value = '';
    quantity.value = 1;
    unitCost.value = 0;
    supplierSku.value = '';
};

const removeItem = (index) => {
    form.items.splice(index, 1);
};

const updateItemQuantity = (index, newQty) => {
    if (newQty >= 1) {
        form.items[index].quantity = newQty;
    }
};

const updateItemCost = (index, newCost) => {
    form.items[index].unit_cost = parseFloat(newCost) || 0;
};

// Prefill the pending line from the chosen supplier's linked cost and SKU
// when the product has one, else from the product's purchase price.
const prefillLine = () => {
    const product = chosenProduct.value;
    if (!product) return;
    const variant = product.variants?.find(v => v.id === selectedVariantId.value) ?? null;
    unitCost.value = defaultUnitCost(product, variant, form.supplier_id);
    supplierSku.value = defaultSupplierSku(product, form.supplier_id);
};

const onProductSelected = () => {
    selectedVariantId.value = null;
    variantError.value = '';
    prefillLine();
};

const onVariantSelected = () => {
    variantError.value = '';
    prefillLine();
};

watch(() => form.supplier_id, () => prefillLine());

const onProductFound = (product, variant = null) => {
    // Add product from barcode scanner. A variant barcode resolves to its
    // variant; a variant product scanned by its own code waits for a pick.
    selectedProductId.value = product.id;
    onProductSelected();
    showScanner.value = false;
    if (variant) {
        selectedVariantId.value = variant.id;
        onVariantSelected();
    } else if (chosenProduct.value?.has_variants) {
        return;
    }
    addItem();
};

const submit = () => {
    if (form.processing) return;
    // Once a line has a tax rate the PO's tax is the sum of the line taxes,
    // and the server refuses a PO-level tax on top of it.
    form
        .transform((data) => (totals.value.lineRates ? { ...data, tax: 0 } : data))
        .post(route('purchase-orders.store'), { preserveScroll: true, onError: focusErrors });
};

const formatCurrency = (value) => {
    return formatMoney(value, form.currency);
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('purchaseOrders.create.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('purchase-orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('purchase-orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.purchaseOrders') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('common.create') }}</span>
            </div>
        </template>

        <PageHeader :title="t('purchaseOrders.create.title')" :description="t('purchaseOrders.create.description')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('purchase-orders.index')">
                    <ArrowLeft :size="14" />
                    {{ t('purchaseOrders.create.backToPo') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <form @submit.prevent="submit" class="mt-6 space-y-4">
            <FormErrorSummary ref="errorSummary" :errors="form.errors" :targets="errorTargets" />
            <!-- Order Details -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('purchaseOrders.create.orderDetails') }}</h3></div>
                <div class="space-y-4 p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label for="supplier_id" :class="fieldLabel">{{ t('purchaseOrders.supplier') }} *</label>
                            <select id="supplier_id" v-model="form.supplier_id" required :class="fieldInput" v-bind="fieldErrorAttrs('supplier_id')">
                                <option value="">{{ t('purchaseOrders.create.selectSupplier') }}</option>
                                <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">
                                    {{ supplier.name }}
                                </option>
                            </select>
                            <p v-if="form.errors.supplier_id" :class="fieldError" :id="errorId('supplier_id')">{{ form.errors.supplier_id }}</p>
                        </div>

                        <div>
                            <label for="currency" :class="fieldLabel">{{ t('common.currency') }}</label>
                            <CurrencySelect id="currency" v-model="form.currency" :class="fieldInput" v-bind="fieldErrorAttrs('currency')" />
                            <p v-if="form.errors.currency" :class="fieldError" :id="errorId('currency')">{{ form.errors.currency }}</p>
                        </div>

                        <div>
                            <label for="order_date" :class="fieldLabel">{{ t('purchaseOrders.create.orderDate') }}</label>
                            <input id="order_date" v-model="form.order_date" type="date" :class="fieldInput" required v-bind="fieldErrorAttrs('order_date')" />
                            <p v-if="form.errors.order_date" :class="fieldError" :id="errorId('order_date')">{{ form.errors.order_date }}</p>
                        </div>

                        <div>
                            <label for="expected_date" :class="fieldLabel">{{ t('purchaseOrders.create.expectedDelivery') }}</label>
                            <input id="expected_date" v-model="form.expected_date" type="date" :class="fieldInput" v-bind="fieldErrorAttrs('expected_date')" />
                            <p v-if="form.errors.expected_date" :class="fieldError" :id="errorId('expected_date')">{{ form.errors.expected_date }}</p>
                        </div>
                    </div>

                    <div>
                        <label for="notes" :class="fieldLabel">{{ t('common.notes') }}</label>
                        <textarea id="notes" v-model="form.notes" rows="3" :class="fieldArea" v-bind="fieldErrorAttrs('notes')"></textarea>
                        <p v-if="form.errors.notes" :class="fieldError" :id="errorId('notes')">{{ form.errors.notes }}</p>
                    </div>

                    <PurchaseOrderExchangeRate
                        :form="form"
                        :currency="form.currency"
                        :order-date="form.order_date"
                        :organization-currency="organizationCurrency"
                        :total="totals.total"
                        :can-override="!!exchangeRates?.canOverride"
                        :current="null"
                        @rate="(rate) => (effectiveRate = rate)"
                    />
                </div>
            </Card>

            <!-- Add Items -->
            <Card :padded="false">
                <div class="flex items-center justify-between px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('purchaseOrders.create.addItems') }}</h3>
                    <Button type="button" variant="secondary" size="sm" @click="showScanner = true">
                        <ScanLine :size="14" />
                        {{ t('components.barcodeScanner.title') }}
                    </Button>
                </div>
                <div class="p-5">
                    <div class="rounded-lg border border-border-subtle bg-surface-canvas p-4">
                        <div class="grid grid-cols-1 gap-3" :class="chosenProduct?.has_variants ? 'md:grid-cols-8' : 'md:grid-cols-6'">
                            <div class="md:col-span-2">
                                <label for="product" :class="fieldLabel">{{ t('common.product') }}</label>
                                <ProductPicker id="product" v-model="selectedProductId" :products="products" :stock-label="t('components.productPicker.onHand')" @change="onProductSelected" />
                            </div>

                            <div v-if="chosenProduct?.has_variants" class="md:col-span-2">
                                <label for="variant" :class="fieldLabel">{{ t('orders.create.variant') }}</label>
                                <select id="variant" v-model="selectedVariantId" @change="onVariantSelected" :class="fieldInput">
                                    <option :value="null">{{ t('orders.create.chooseVariant') }}</option>
                                    <option v-for="variant in chosenVariants" :key="variant.id" :value="variant.id">
                                        {{ variant.title }}<template v-if="variant.sku"> ({{ variant.sku }})</template> - {{ t('orders.create.stockCount', { count: variant.stock }) }}
                                    </option>
                                </select>
                                <p v-if="variantError" :class="fieldError">{{ variantError }}</p>
                            </div>

                            <div>
                                <label for="quantity" :class="fieldLabel">{{ t('common.quantity') }}</label>
                                <input id="quantity" v-model.number="quantity" type="number" min="1" :class="fieldInput" />
                            </div>

                            <div>
                                <label for="unit_cost" :class="fieldLabel">{{ t('purchaseOrders.create.unitCost') }}</label>
                                <input id="unit_cost" v-model.number="unitCost" type="number" step="0.01" min="0" :class="fieldInput" />
                            </div>

                            <div>
                                <label for="supplier_sku" :class="fieldLabel">{{ t('purchaseOrders.create.supplierSku') }}</label>
                                <input id="supplier_sku" v-model="supplierSku" type="text" :class="fieldInput" :placeholder="t('common.optional')" />
                            </div>

                            <div class="flex items-end">
                                <Button type="button" variant="default" class="w-full" :disabled="!selectedProductId" @click="addItem">
                                    <Plus :size="14" />{{ t('orders.create.add') }}
                                </Button>
                            </div>
                        </div>
                    </div>

                    <p v-if="form.errors.items" :class="fieldError" :id="errorId('items')">{{ form.errors.items }}</p>
                </div>
            </Card>

            <!-- Items -->
            <Card v-if="form.items.length > 0" :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('purchaseOrders.create.orderItems', { count: form.items.length }) }}</h3></div>
                <div class="p-5">
                    <div class="space-y-3">
                        <div v-for="(item, index) in form.items" :key="index" class="flex flex-wrap items-center gap-4 rounded-lg border border-border-subtle bg-surface-canvas p-4">
                            <div class="min-w-0 flex-1 basis-full sm:basis-0">
                                <p class="font-medium text-text-primary">{{ item.product_name }}</p>
                                <p v-if="item.variant_title" class="text-xs text-text-secondary">{{ t('orders.create.variant') }}: {{ item.variant_title }}</p>
                                <p class="text-xs text-text-tertiary">
                                    SKU: {{ item.sku || '-' }}
                                    <span v-if="item.supplier_sku"> &middot; {{ t('purchaseOrders.create.supplierSku') }}: {{ item.supplier_sku }}</span>
                                </p>
                                <p v-if="form.errors[`items.${index}.product_variant_id`]" :class="fieldError" :id="errorId(`items.${index}.product_variant_id`)">{{ form.errors[`items.${index}.product_variant_id`] }}</p>
                                <p v-if="form.errors[`items.${index}.tax_rate`]" :class="fieldError" :id="errorId(`items.${index}.tax_rate`)">{{ form.errors[`items.${index}.tax_rate`] }}</p>
                            </div>
                            <div class="w-20">
                                <label :for="fieldIdPrefix + '-repeat-1-' + index" class="mb-1 block text-[11px] text-text-tertiary">{{ t('common.quantity') }}</label>
                                <input :id="fieldIdPrefix + '-repeat-1-' + index" :value="item.quantity" @input="updateItemQuantity(index, parseInt($event.target.value))" type="number" min="1" :class="fieldInput" v-bind="fieldErrorAttrs(`items.${index}.quantity`)" />
                                <p v-if="form.errors[`items.${index}.quantity`]" :id="errorId(`items.${index}.quantity`)" :class="fieldError">{{ form.errors[`items.${index}.quantity`] }}</p>
                            </div>
                            <div class="w-28">
                                <label :for="fieldIdPrefix + '-repeat-2-' + index" class="mb-1 block text-[11px] text-text-tertiary">{{ t('purchaseOrders.create.unitCost') }}</label>
                                <input :id="fieldIdPrefix + '-repeat-2-' + index" :value="item.unit_cost" @input="updateItemCost(index, $event.target.value)" type="number" step="0.01" min="0" :class="fieldInput" v-bind="fieldErrorAttrs(`items.${index}.unit_cost`)" />
                                <p v-if="form.errors[`items.${index}.unit_cost`]" :id="errorId(`items.${index}.unit_cost`)" :class="fieldError">{{ form.errors[`items.${index}.unit_cost`] }}</p>
                            </div>
                            <div class="w-20">
                                <label :for="fieldIdPrefix + '-repeat-3-' + index" class="mb-1 block text-[11px] text-text-tertiary">{{ t('tax.lineRate') }}</label>
                                <input :id="fieldIdPrefix + '-repeat-3-' + index" v-model="item.tax_rate" type="number" step="0.001" min="0" max="100" :class="fieldInput" v-bind="fieldErrorAttrs(`items.${index}.tax_rate`)" />
                            </div>
                            <div class="w-24 text-end">
                                <p class="mb-1 text-[11px] text-text-tertiary">{{ t('tax.net') }}</p>
                                <p class="tabular-nums text-text-primary">{{ formatCurrency(totals.lineNets[index]) }}</p>
                            </div>
                            <div class="w-20 text-end">
                                <p class="mb-1 text-[11px] text-text-tertiary">{{ t('tax.lineTax') }}</p>
                                <p class="tabular-nums text-text-secondary">{{ formatCurrency(totals.lineTaxes[index]) }}</p>
                            </div>
                            <div class="w-28 text-end">
                                <p class="mb-1 text-[11px] text-text-tertiary">{{ t('tax.gross') }}</p>
                                <p class="font-semibold tabular-nums text-text-primary">{{ formatCurrency(totals.lineGross[index]) }}</p>
                                <p v-if="effectiveRate" class="text-[11px] tabular-nums text-text-tertiary">{{ converted(totals.lineGross[index]) }}</p>
                            </div>
                            <button :aria-label="t('common.delete')" type="button" @click="removeItem(index)" class="mt-4 rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-danger"><Trash2 :size="16" /></button>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-text-tertiary">{{ t('purchaseOrders.create.netCostHint') }}</p>

                    <!-- Totals -->
                    <div class="mt-5 flex justify-end border-t border-border-subtle pt-5">
                        <div class="w-72 space-y-3">
                            <div class="flex justify-between text-sm">
                                <span class="text-text-secondary">{{ t('common.subtotal') }}</span>
                                <span class="font-medium tabular-nums text-text-primary">{{ formatCurrency(totals.subtotal) }}</span>
                            </div>
                            <template v-if="totals.lineRates">
                                <div v-for="entry in totals.taxByRate" :key="entry.rate" class="flex justify-between gap-3 text-sm">
                                    <span class="text-text-secondary">{{ t('tax.byRate', { rate: formatTaxRate(entry.rate), amount: formatCurrency(entry.taxable) }) }}</span>
                                    <span class="text-end font-medium tabular-nums text-text-primary">
                                        {{ formatCurrency(entry.tax) }}
                                        <span v-if="effectiveRate" class="block text-[11px] font-normal text-text-tertiary">{{ converted(entry.tax) }}</span>
                                    </span>
                                </div>
                                <p class="text-xs text-text-tertiary">{{ t('tax.fromLineRatesPo') }}</p>
                                <p v-if="form.errors.tax" :class="fieldError" :id="errorId('tax')">{{ form.errors.tax }}</p>
                            </template>
                            <TaxInput v-else v-model="form.tax" :base="totals.subtotal" :error="form.errors.tax" />
                            <div class="flex items-center justify-between text-sm">
                                <label for="shipping" class="text-text-secondary">{{ t('common.shipping') }}</label>
                                <input id="shipping" v-model.number="form.shipping" type="number" step="0.01" min="0" class="h-9 w-28 rounded-md border border-border-subtle bg-surface-canvas px-3 text-end text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring" v-bind="fieldErrorAttrs('shipping')" />
                            </div>
                            <div class="flex items-center justify-between border-t border-border-subtle pt-3">
                                <span class="text-sm font-semibold text-text-primary">{{ t('common.total') }}</span>
                                <span class="text-xl font-bold text-brand">{{ formatCurrency(totals.total) }}</span>
                            </div>
                            <div v-if="effectiveRate" class="flex items-center justify-between text-sm">
                                <span class="text-text-secondary">{{ t('exchangeRates.po.totalIn', { currency: organizationCurrency }) }}</span>
                                <span class="font-semibold tabular-nums text-text-primary">{{ converted(totals.total) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </Card>

            <FormActions :label="t('purchaseOrders.create.createPo')" :cancel-href="route('purchase-orders.index')" :processing="form.processing" :disabled="form.items.length === 0" />
        </form>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />

        <!-- Barcode Scanner Modal -->
        <BarcodeScannerModal
            :show="showScanner"
            @close="showScanner = false"
            @product-found="onProductFound"
        />
    </AppLayout>
</template>
