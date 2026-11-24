<script setup>
import CurrencySelect from '@/Components/CurrencySelect.vue';
import { useCurrency } from '@/composables/useCurrency';

import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import TaxInput from '@/Components/TaxInput.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PurchaseOrderExchangeRate from '@/Components/PurchaseOrderExchangeRate.vue';
import ProductPicker from '@/Components/ui/ProductPicker.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { defaultSupplierSku, defaultTaxRate, defaultUnitCost } from '@/lib/purchaseOrderLine';
import { formatTaxRate, rateValue } from '@/lib/lineTax';
import { usePurchaseOrderTotals } from '@/composables/usePurchaseOrderTotals';
import { toIsoDate } from '@/lib/dates';
import { formatMoney } from '@/lib/money';
import { ArrowLeft, Plus, Trash2 } from '@lucide/vue';

const { organizationCurrency } = useCurrency();
const { t } = useI18n();

const props = defineProps({
    purchaseOrder: Object,
    // The PO-level part of the tax (purchaseOrder.tax already includes the
    // line taxes, so it must not seed the PO tax field).
    documentLevelTax: { type: [String, Number], default: 0 },
    suppliers: Array,
    products: Array,
    exchangeRate: { type: Object, default: null },
    exchangeRates: { type: Object, default: () => ({}) },
    pluginComponents: Object,
});

const form = useForm({
    supplier_id: props.purchaseOrder.supplier_id,
    order_date: toIsoDate(props.purchaseOrder.order_date),
    expected_date: toIsoDate(props.purchaseOrder.expected_date),
    currency: props.purchaseOrder.currency || organizationCurrency.value,
    shipping: props.purchaseOrder.shipping || 0,
    tax: Number(props.documentLevelTax) || 0,
    notes: props.purchaseOrder.notes || '',
    items: props.purchaseOrder.items?.map(item => ({
        id: item.id,
        product_id: item.product_id,
        product_variant_id: item.product_variant_id ?? null,
        product_name: item.product_name,
        sku: item.sku,
        receiving_location: item.receiving_location,
        quantity: item.quantity_ordered,
        unit_cost: item.unit_cost,
        supplier_sku: item.supplier_sku || '',
        tax_rate: rateValue(item.tax_rate) ?? '',
        // A line without a rate keeps the tax amount it has (API lines);
        // a rated line's amount is recomputed from its rate.
        tax: rateValue(item.tax_rate) === null ? item.tax : 0,
    })) || [],
    // Only sent when a permitted user enters (manual), accepts (confirm) or
    // refreshes (auto) the rate; otherwise the saved rate is kept.
    exchange_rate_mode: null,
    exchange_rate: null,
});

// The rate the PO will be saved at (for the organization-currency amounts).
const effectiveRate = ref(null);

const selectedProductId = ref('');
const selectedVariantId = ref(null);
const variantError = ref('');
const quantity = ref(1);
const unitCost = ref(0);
const supplierSku = ref('');

// Net, tax per line and per rate, and gross, as the server will save them.
const totals = usePurchaseOrderTotals(form);

// An amount in the organization's currency at the PO's rate.
const converted = (value) => formatMoney(Number(value) * Number(effectiveRate.value), organizationCurrency.value);

const productFor = (productId) => props.products.find(p => p.id == productId) ?? null;

const chosenProduct = computed(() => productFor(selectedProductId.value));

const chosenVariants = computed(() =>
    chosenProduct.value?.has_variants ? chosenProduct.value.variants.filter(v => v.is_active) : []
);

// The variants a line can switch between: the active ones plus the one the
// line already buys (kept in the list even if since deactivated).
const lineVariants = (item) => {
    const product = productFor(item.product_id);
    if (!product?.has_variants) return [];
    return product.variants.filter(v => v.is_active || v.id === item.product_variant_id);
};

const onLineVariantChange = (index) => {
    const item = form.items[index];
    const variant = productFor(item.product_id)?.variants.find(v => v.id === item.product_variant_id);
    item.sku = variant?.sku || productFor(item.product_id)?.sku || item.sku;
};

const addItem = () => {
    const product = chosenProduct.value;
    if (!product || quantity.value < 1) return;

    let variant = null;
    if (product.has_variants) {
        variant = product.variants.find(v => v.id === selectedVariantId.value) ?? null;
        if (!variant) {
            variantError.value = t('orders.create.variantRequired');
            return;
        }
    }
    const variantId = variant?.id ?? null;

    const existingIndex = form.items.findIndex(item => item.product_id == product.id && (item.product_variant_id ?? null) === variantId);
    if (existingIndex >= 0) {
        form.items[existingIndex].quantity += quantity.value;
        form.items[existingIndex].unit_cost = unitCost.value;
    } else {
        form.items.push({
            id: null,
            product_id: product.id,
            product_variant_id: variantId,
            product_name: product.name,
            sku: variant?.sku || product.sku,
            quantity: quantity.value,
            unit_cost: unitCost.value || defaultUnitCost(product, variant, form.supplier_id, form.currency),
            supplier_sku: supplierSku.value,
            // Starts at the product's rate (variants share it); editable,
            // blank for none.
            tax_rate: defaultTaxRate(product),
            tax: 0,
        });
    }

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
    unitCost.value = defaultUnitCost(product, variant, form.supplier_id, form.currency);
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

const submit = () => {
    // Once a line has a tax rate the PO's tax is the sum of the line taxes,
    // and the server refuses a PO-level tax on top of it.
    form
        .transform((data) => (totals.value.lineRates ? { ...data, tax: 0 } : data))
        .put(route('purchase-orders.update', props.purchaseOrder.id));
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
    <Head :title="t('purchaseOrders.edit.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('purchase-orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('purchase-orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('purchaseOrders.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('purchaseOrders.edit.title') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ purchaseOrder.po_number }}</span>
            </div>
        </template>

        <PageHeader :title="t('purchaseOrders.edit.title')" :description="t('purchaseOrders.edit.poNumber', { number: purchaseOrder.po_number })">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('purchase-orders.show', purchaseOrder.id)">
                    {{ t('purchaseOrders.edit.viewDetails') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('purchase-orders.index')">
                    <ArrowLeft :size="14" />
                    {{ t('purchaseOrders.edit.backToPo') }}
                </Button>
            </template>
        </PageHeader>

        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <form @submit.prevent="submit" class="mt-6 space-y-4">
            <!-- Order Details -->
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('purchaseOrders.create.orderDetails') }} - {{ purchaseOrder.po_number }}</h3>
                </div>
                <div class="space-y-4 p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label for="supplier_id" :class="fieldLabel">{{ t('purchaseOrders.supplier') }} *</label>
                            <select id="supplier_id" v-model="form.supplier_id" :class="fieldInput" required>
                                <option value="">{{ t('purchaseOrders.create.selectSupplier') }}</option>
                                <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">
                                    {{ supplier.name }}
                                </option>
                            </select>
                            <p v-if="form.errors.supplier_id" :class="fieldError">{{ form.errors.supplier_id }}</p>
                        </div>

                        <div>
                            <label for="currency" :class="fieldLabel">{{ t('common.currency') }}</label>
                            <CurrencySelect id="currency" v-model="form.currency" :class="fieldInput" />
                            <p v-if="form.errors.currency" :class="fieldError">{{ form.errors.currency }}</p>
                        </div>

                        <div>
                            <label for="order_date" :class="fieldLabel">{{ t('purchaseOrders.create.orderDate') }}</label>
                            <input id="order_date" v-model="form.order_date" type="date" :class="fieldInput" required />
                            <p v-if="form.errors.order_date" :class="fieldError">{{ form.errors.order_date }}</p>
                        </div>

                        <div>
                            <label for="expected_date" :class="fieldLabel">{{ t('purchaseOrders.create.expectedDelivery') }}</label>
                            <input id="expected_date" v-model="form.expected_date" type="date" :class="fieldInput" />
                            <p v-if="form.errors.expected_date" :class="fieldError">{{ form.errors.expected_date }}</p>
                        </div>
                    </div>

                    <div>
                        <label for="notes" :class="fieldLabel">{{ t('common.notes') }}</label>
                        <textarea id="notes" v-model="form.notes" rows="3" :class="fieldArea"></textarea>
                        <p v-if="form.errors.notes" :class="fieldError">{{ form.errors.notes }}</p>
                    </div>

                    <PurchaseOrderExchangeRate
                        :form="form"
                        :currency="form.currency"
                        :order-date="form.order_date"
                        :organization-currency="organizationCurrency"
                        :total="totals.total"
                        :can-override="!!exchangeRates?.canOverride"
                        :current="exchangeRate ? { ...exchangeRate, order_date: purchaseOrder.order_date } : null"
                        @rate="(rate) => (effectiveRate = rate)"
                    />
                </div>
            </Card>

            <!-- Add Items -->
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('purchaseOrders.create.addItems') }}</h3>
                </div>
                <div class="space-y-4 p-5">
                    <div class="@container rounded-lg border border-border-subtle bg-surface-canvas p-4">
                        <div class="grid grid-cols-1 gap-3 @lg:grid-cols-4" :class="chosenProduct?.has_variants ? '@5xl:grid-cols-8' : '@4xl:grid-cols-6'">
                            <div class="@lg:col-span-4" :class="chosenProduct?.has_variants ? '@5xl:col-span-2' : '@4xl:col-span-2'">
                                <label for="product" :class="fieldLabel">{{ t('common.product') }}</label>
                                <ProductPicker id="product" v-model="selectedProductId" :products="products" :stock-label="t('components.productPicker.onHand')" @change="onProductSelected" />
                            </div>

                            <div v-if="chosenProduct?.has_variants" class="@lg:col-span-4 @5xl:col-span-2">
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

                    <p v-if="form.errors.items" :class="fieldError">{{ form.errors.items }}</p>
                </div>
            </Card>

            <!-- Items -->
            <Card v-if="form.items.length > 0" :padded="false">
                <div class="px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('purchaseOrders.create.orderItems', { count: form.items.length }) }}</h3>
                </div>
                <div class="space-y-3 p-5">
                    <div
                        v-for="(item, index) in form.items"
                        :key="index"
                        class="@container rounded-lg border border-border-subtle bg-surface-canvas p-4"
                    >
                        <!-- The product gets the full card width; the figures below
                             are laid out by the card's width, not the viewport's. -->
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-text-primary">{{ item.product_name }}</p>
                                <p v-if="item.receiving_location" class="text-xs text-text-secondary">{{ t('variantReplenishment.receiveAt', { location: item.receiving_location.name, warehouse: item.receiving_location.warehouse?.name }) }}</p>
                                <p class="text-xs text-text-tertiary">SKU: {{ item.sku || '-' }}</p>
                                <p class="text-xs text-text-tertiary">{{ t('purchaseOrders.create.supplierSku') }}: {{ item.supplier_sku || '-' }}</p>
                                <template v-if="productFor(item.product_id)?.has_variants">
                                    <label :for="`line-variant-${index}`" class="mb-1 mt-2 block text-[11px] text-text-tertiary">{{ t('orders.create.variant') }}</label>
                                    <select :id="`line-variant-${index}`" v-model="item.product_variant_id" @change="onLineVariantChange(index)" :class="fieldInput" required>
                                        <option :value="null">{{ t('orders.create.chooseVariant') }}</option>
                                        <option v-for="variant in lineVariants(item)" :key="variant.id" :value="variant.id">
                                            {{ variant.title }}<template v-if="variant.sku"> ({{ variant.sku }})</template>
                                        </option>
                                    </select>
                                </template>
                                <p v-if="form.errors[`items.${index}.product_variant_id`]" :class="fieldError">{{ form.errors[`items.${index}.product_variant_id`] }}</p>
                                <p v-if="form.errors[`items.${index}.tax_rate`]" :class="fieldError">{{ form.errors[`items.${index}.tax_rate`] }}</p>
                            </div>
                            <button :aria-label="t('common.delete')"
                                type="button"
                                @click="removeItem(index)"
                                class="shrink-0 rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-danger"
                                :title="t('orders.edit.removeItem')"
                            >
                                <Trash2 :size="16" />
                            </button>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-3 @sm:grid-cols-3 @3xl:grid-cols-6">
                            <div>
                                <label :for="`quantity-${index}`" :class="fieldLabel">{{ t('common.quantity') }}</label>
                                <input
                                    :id="`quantity-${index}`"
                                    type="number"
                                    :value="item.quantity"
                                    @input="updateItemQuantity(index, parseInt($event.target.value))"
                                    min="1"
                                    :class="fieldInput"
                                />
                            </div>
                            <div>
                                <label :for="`cost-${index}`" :class="fieldLabel">{{ t('purchaseOrders.create.unitCost') }}</label>
                                <input
                                    :id="`cost-${index}`"
                                    type="number"
                                    :value="item.unit_cost"
                                    @input="updateItemCost(index, $event.target.value)"
                                    step="0.01"
                                    min="0"
                                    :class="fieldInput"
                                />
                            </div>
                            <div>
                                <label :for="`tax-rate-${index}`" :class="fieldLabel">{{ t('tax.lineRate') }}</label>
                                <input
                                    :id="`tax-rate-${index}`"
                                    type="number"
                                    v-model="item.tax_rate"
                                    step="0.001"
                                    min="0"
                                    max="100"
                                    :class="fieldInput"
                                />
                            </div>
                            <div class="text-end">
                                <p :class="fieldLabel">{{ t('tax.net') }}</p>
                                <p class="whitespace-nowrap text-sm tabular-nums text-text-primary">{{ formatCurrency(totals.lineNets[index]) }}</p>
                            </div>
                            <div class="text-end">
                                <p :class="fieldLabel">{{ t('tax.lineTax') }}</p>
                                <p class="whitespace-nowrap text-sm tabular-nums text-text-secondary">{{ formatCurrency(totals.lineTaxes[index]) }}</p>
                            </div>
                            <div class="text-end">
                                <p :class="fieldLabel">{{ t('tax.gross') }}</p>
                                <p class="whitespace-nowrap text-sm font-semibold tabular-nums text-text-primary">{{ formatCurrency(totals.lineGross[index]) }}</p>
                                <p v-if="effectiveRate" class="whitespace-nowrap text-[11px] tabular-nums text-text-tertiary">{{ converted(totals.lineGross[index]) }}</p>
                            </div>
                        </div>
                    </div>
                    <p class="text-xs text-text-tertiary">{{ t('purchaseOrders.create.netCostHint') }}</p>

                    <!-- Totals -->
                    <div class="flex justify-end pt-2">
                        <div class="w-full max-w-xs space-y-3 border-t border-border-subtle pt-3">
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
                                <p v-if="form.errors.tax" :class="fieldError">{{ form.errors.tax }}</p>
                            </template>
                            <TaxInput v-else v-model="form.tax" :base="totals.subtotal" :error="form.errors.tax" />
                            <div>
                                <label for="shipping" class="mb-1 block text-sm text-text-secondary">{{ t('common.shipping') }}</label>
                                <input id="shipping" v-model.number="form.shipping" type="number" step="0.01" min="0" :class="fieldInput" />
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

            <!-- Actions -->
            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" size="lg" as="Link" :href="route('purchase-orders.show', purchaseOrder.id)">
                    {{ t('common.cancel') }}
                </Button>
                <Button
                    type="submit"
                    variant="default"
                    size="lg"
                    :loading="form.processing"
                    :disabled="form.processing || form.items.length === 0"
                >
                    {{ t('purchaseOrders.edit.updatePo') }}
                </Button>
            </div>
        </form>

        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
    </AppLayout>
</template>
