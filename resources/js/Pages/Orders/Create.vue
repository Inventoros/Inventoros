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
import TaxInput from '@/Components/TaxInput.vue';
import CustomerPicker from '@/Components/CustomerPicker.vue';
import ProductPicker from '@/Components/ui/ProductPicker.vue';
import DiscountInput from '@/Components/DiscountInput.vue';
import { useOrderTotals, lineNetCents } from '@/composables/useOrderTotals';
import { formatTaxRate } from '@/lib/lineTax';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, defineAsyncComponent } from 'vue';
import { useBarcodeWedge, useBarcodeLookup } from '@/composables/useBarcodeWedge';
import { useI18n } from 'vue-i18n';
import { todayIsoDate } from '@/lib/dates';
import { formatMoney } from '@/lib/money';
import { linePrice } from '@/lib/orderLinePrice';
import { ArrowLeft, Plus, Trash2, PackageOpen, ScanLine } from '@lucide/vue';

const BarcodeScannerModal = defineAsyncComponent(() => import('@/Components/BarcodeScannerModal.vue'));

const { t } = useI18n();

const props = defineProps({
    // The organization requires order approval: a new order waits for it
    // and cannot start out shipped or delivered.
    ordersNeedApproval: { type: Boolean, default: false },
    products: Array,
    // The organization's currency, and the currencies an order can be in.
    defaultCurrency: { type: String, default: 'USD' },
    currencies: { type: Array, default: () => [] },
});

const form = useForm({
    customer_id: null,
    customer_name: '',
    customer_email: '',
    customer_address: '',
    currency: props.defaultCurrency,
    status: 'pending',
    order_date: todayIsoDate(),
    shipping: 0,
    tax: 0,
    discount_type: '',
    discount_value: null,
    notes: '',
    items: [],
});
const { errorSummary, errorId, fieldErrorAttrs, focusErrors } = useFormFeedback(form);
const errorTargets = computed(() => ({
    customer_name: 'customer_name',
    customer_email: 'customer_email',
    customer_address: 'customer_address',
    order_date: 'order_date',
    currency: 'currency',
    status: 'status',
    notes: 'notes',
    shipping: 'shipping',
    tax: 'tax',
    items: 'add_product',
    ...Object.fromEntries(form.items.flatMap((_, index) => [[`items.${index}.quantity`, fieldIdPrefix + '-repeat-1-' + index], [`items.${index}.unit_price`, fieldIdPrefix + '-repeat-2-' + index], [`items.${index}.tax_rate`, fieldIdPrefix + '-repeat-3-' + index]])),
}));


const selectedCustomer = ref(null);

const selectCustomer = (customer) => {
    selectedCustomer.value = customer;
    form.customer_id = customer.id;
    form.customer_name = customer.name;
    form.customer_email = customer.email ?? '';
    form.customer_address = customer.shipping_address || customer.billing_address || '';
    // A customer billed in their own currency gets orders in it.
    if (customer.currency && customer.currency !== form.currency) {
        form.currency = customer.currency;
        repriceLines();
    }
};

// Back to a one-off customer: unlink, but keep what was typed so it can be edited.
const clearCustomer = () => {
    selectedCustomer.value = null;
    form.customer_id = null;
};

const selectedProduct = ref(null);
const selectedVariant = ref(null);
const quantity = ref(1);
const variantError = ref('');

const chosenProduct = computed(() => props.products.find(p => p.id === selectedProduct.value) ?? null);

// A variant-tracked product is sold by variant, so its own stock is not the
// sellable count; only variants with stock can be picked.
const availableVariants = computed(() =>
    chosenProduct.value?.has_variants
        ? chosenProduct.value.variants.filter(v => v.is_active && v.stock > 0)
        : []
);

// A new line starts at the product's tax rate (variants share it); blank
// when the product has none. The rate stays editable per line.
const productTaxRate = (product) => (product?.tax_rate != null && product.tax_rate !== '' ? parseFloat(product.tax_rate) : '');

const onProductChange = () => {
    selectedVariant.value = null;
    variantError.value = '';
};

const addItem = () => {
    const product = chosenProduct.value;
    if (!product || quantity.value < 1) return;

    let variant = null;
    if (product.has_variants) {
        variant = product.variants.find(v => v.id === selectedVariant.value) ?? null;
        if (!variant) {
            variantError.value = t('orders.create.variantRequired');
            return;
        }
    }

    const variantId = variant?.id ?? null;
    const existingIndex = form.items.findIndex(item => item.product_id === product.id && item.product_variant_id === variantId);
    if (existingIndex >= 0) {
        form.items[existingIndex].quantity += quantity.value;
    } else {
        form.items.push({
            product_id: product.id,
            product_variant_id: variantId,
            product_name: product.name,
            variant_title: variant?.title ?? null,
            sku: variant?.sku || product.sku,
            quantity: quantity.value,
            // The catalogue price in the order's currency (the variant's own
            // price in the product's currency); blank when there is none.
            unit_price: linePrice(product, variant, form.currency) ?? '',
            discount_type: '',
            discount_value: null,
            tax_rate: productTaxRate(product),
        });
    }
    selectedProduct.value = null;
    selectedVariant.value = null;
    variantError.value = '';
    quantity.value = 1;
};

// --- Scanning ---------------------------------------------------------
// Scan (camera modal or keyboard-wedge scanner) to add a line. A variant
// barcode adds that variant directly; a product that is sold by variant but
// was scanned by its own code is loaded into the picker above so the variant
// can be chosen. Scanning a line that already exists adds one to it.
const showScanner = ref(false);
const scanMessage = ref('');
const scanMessageTone = ref('info');
const { lookup } = useBarcodeLookup();

const setScanMessage = (message, tone) => {
    scanMessage.value = message;
    scanMessageTone.value = tone;
};

const addScannedLine = (product, variant) => {
    const variantId = variant?.id ?? null;
    const existing = form.items.find(item => item.product_id === product.id && item.product_variant_id === variantId);
    if (existing) {
        existing.quantity += 1;
    } else {
        form.items.push({
            product_id: product.id,
            product_variant_id: variantId,
            product_name: product.name,
            variant_title: variant?.title ?? null,
            sku: variant?.sku || product.sku,
            quantity: 1,
            unit_price: linePrice(product, variant, form.currency) ?? '',
            tax_rate: productTaxRate(product),
        });
    }
    const line = form.items.find(item => item.product_id === product.id && item.product_variant_id === variantId);
    const label = variant ? `${product.name} (${variant.title})` : product.name;
    setScanMessage(t('scanning.addedToLine', { name: label, quantity: line.quantity }), 'success');
};

const onScannedProduct = (scannedProduct, scannedVariant = null) => {
    showScanner.value = false;
    // Use the form's own product data (price, active variants, stock).
    const product = props.products.find(p => p.id === scannedProduct.id);
    if (!product) {
        setScanMessage(t('scanning.notSellable', { name: scannedProduct.name }), 'warning');
        return;
    }

    if (product.has_variants) {
        const variant = scannedVariant
            ? product.variants.find(v => v.id === scannedVariant.id && v.is_active) ?? null
            : null;
        if (!variant) {
            // No variant came back: load the product so the variant can be picked.
            selectedProduct.value = product.id;
            selectedVariant.value = null;
            quantity.value = 1;
            variantError.value = '';
            setScanMessage(t('scanning.pickVariant', { name: product.name }), 'warning');
            document.getElementById('add_variant')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        addScannedLine(product, variant);
        return;
    }

    addScannedLine(product, null);
};

const onScannedCode = async (code) => {
    try {
        const found = await lookup(code);
        if (!found) {
            setScanMessage(t('scanning.notFound', { code }), 'danger');
            return;
        }
        onScannedProduct(found.product, found.variant);
    } catch (error) {
        setScanMessage(t('scanning.lookupFailed'), 'danger');
    }
};

useBarcodeWedge(onScannedCode, { enabled: () => !showScanner.value });

const removeItem = (index) => {
    form.items.splice(index, 1);
};

const updateItemQuantity = (index, newQuantity) => {
    if (newQuantity < 1) {
        removeItem(index);
    } else {
        form.items[index].quantity = newQuantity;
    }
};

const updateItemPrice = (index, newPrice) => {
    form.items[index].unit_price = parseFloat(newPrice) || 0;
};

// Preview only: the server recomputes every total on save.
const totals = useOrderTotals(form);

const money = (value) => formatMoney(value, form.currency);

// Changing the order's currency re-prices every line from the catalogue in
// that currency; a line with no price in it is left blank to fill in.
function repriceLines() {
    for (const item of form.items) {
        const product = props.products.find(p => p.id === item.product_id);
        const variant = product?.variants?.find(v => v.id === item.product_variant_id) ?? null;
        item.unit_price = product ? (linePrice(product, variant, form.currency) ?? '') : item.unit_price;
    }
}

const submit = () => {
    if (form.processing) return;
    if (form.items.length === 0) {
        alert(t('orders.create.addAtLeastOneProduct'));
        return;
    }
    // Once a line has a tax rate the order's tax is the sum of the line
    // taxes, and the server refuses an order-level tax on top of it.
    form
        .transform((data) => (totals.value.lineRates ? { ...data, tax: 0 } : data))
        .post(route('orders.store'), { preserveScroll: true, onError: focusErrors });
};

const availableProducts = computed(() =>
    [...props.products]
        .filter(p => (p.has_variants ? p.variants.some(v => v.is_active && v.stock > 0) : p.stock > 0))
        .sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }))
);

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('orders.create.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('orders.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('orders.create.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('orders.create.title')" :description="t('orders.create.description')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('orders.index')">
                    <ArrowLeft :size="14" />
                    {{ t('orders.create.backToOrders') }}
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6">
            <FormErrorSummary ref="errorSummary" :errors="form.errors" :targets="errorTargets" />
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                <!-- Left column -->
                <div class="space-y-4 xl:col-span-2">
                    <!-- Customer info -->
                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.create.customerInfo') }}</h3></div>
                        <div class="space-y-4 p-5">
                            <CustomerPicker :selected="selectedCustomer" @select="selectCustomer" @clear="clearCustomer" />
                            <p v-if="form.errors.customer_id" :class="fieldError" :id="errorId('customer_id')">{{ form.errors.customer_id }}</p>
                            <div>
                                <label for="customer_name" :class="fieldLabel">{{ t('orders.create.customerName') }}</label>
                                <input id="customer_name" v-model="form.customer_name" type="text" :class="fieldInput" required v-bind="fieldErrorAttrs('customer_name')" />
                                <p v-if="form.errors.customer_name" :class="fieldError" :id="errorId('customer_name')">{{ form.errors.customer_name }}</p>
                            </div>
                            <div>
                                <label for="customer_email" :class="fieldLabel">{{ t('orders.create.customerEmail') }}</label>
                                <input id="customer_email" v-model="form.customer_email" type="email" :class="fieldInput" v-bind="fieldErrorAttrs('customer_email')" />
                                <p v-if="form.errors.customer_email" :class="fieldError" :id="errorId('customer_email')">{{ form.errors.customer_email }}</p>
                            </div>
                            <div>
                                <label for="customer_address" :class="fieldLabel">{{ t('orders.create.shippingAddress') }}</label>
                                <textarea id="customer_address" v-model="form.customer_address" rows="3" :class="fieldArea" v-bind="fieldErrorAttrs('customer_address')"></textarea>
                                <p v-if="form.errors.customer_address" :class="fieldError" :id="errorId('customer_address')">{{ form.errors.customer_address }}</p>
                            </div>
                        </div>
                    </Card>

                    <!-- Order items -->
                    <Card :padded="false">
                        <div class="flex flex-wrap items-center justify-between gap-2 px-5 pt-5">
                            <h3 class="text-sm font-semibold text-text-primary">{{ t('orders.create.orderItems') }}</h3>
                            <Button type="button" variant="secondary" size="lg" class="min-h-11" @click="showScanner = true">
                                <ScanLine :size="16" />
                                {{ t('scanning.scan') }}
                            </Button>
                        </div>
                        <div class="px-5 pt-2">
                            <p class="text-xs text-text-tertiary">{{ t('scanning.orderHint') }}</p>
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
                            <!-- Add item -->
                            <div class="@container mb-5 rounded-lg border border-border-subtle bg-surface-canvas p-4">
                                <div class="grid grid-cols-1 gap-3 @lg:grid-cols-12">
                                    <div :class="chosenProduct?.has_variants ? '@lg:col-span-4' : '@lg:col-span-7'">
                                        <label for="add_product" :class="fieldLabel">{{ t('orders.create.selectProduct') }}</label>
                                        <ProductPicker id="add_product" v-model="selectedProduct" :products="availableProducts" :stock-label="t('components.productPicker.onHand')" @change="onProductChange">
                                            <template #details="{ product }"><span v-if="!product.has_variants" class="block text-xs text-text-secondary">{{ formatMoney(product.price, product.currency) }}</span></template>
                                        </ProductPicker>
                                        <p v-if="chosenProduct && !chosenProduct.has_variants" class="mt-1 text-xs text-text-secondary">{{ formatMoney(chosenProduct.price, chosenProduct.currency) }}</p>
                                    </div>
                                    <div v-if="chosenProduct?.has_variants" class="@lg:col-span-3">
                                        <label for="add_variant" :class="fieldLabel">{{ t('orders.create.variant') }}</label>
                                        <select id="add_variant" v-model="selectedVariant" :class="fieldInput" @change="variantError = ''">
                                            <option :value="null">{{ t('orders.create.chooseVariant') }}</option>
                                            <option v-for="variant in availableVariants" :key="variant.id" :value="variant.id">
                                                {{ variant.title }}<template v-if="variant.sku"> ({{ variant.sku }})</template> - {{ t('orders.create.stockCount', { count: variant.stock }) }} - {{ formatMoney(variant.price, chosenProduct?.currency) }}
                                            </option>
                                        </select>
                                        <p v-if="variantError" :class="fieldError">{{ variantError }}</p>
                                    </div>
                                    <div class="@lg:col-span-3">
                                        <label :for="fieldIdPrefix + '-1'" :class="fieldLabel">{{ t('common.quantity') }}</label>
                                        <input :id="fieldIdPrefix + '-1'" v-model.number="quantity" type="number" min="1" :class="fieldInput" />
                                    </div>
                                    <div class="flex items-end @lg:col-span-2">
                                        <Button type="button" variant="default" class="w-full" @click="addItem">
                                            <Plus :size="14" />{{ t('orders.create.add') }}
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            <!-- Items list -->
                            <div v-if="form.items.length > 0" class="space-y-3">
                                <div v-for="(item, index) in form.items" :key="index" class="@container rounded-lg border border-border-subtle bg-surface-canvas p-4">
                                <!-- The product gets the full card width; the figures below
                                     are laid out by the card's width, not the viewport's. -->
                                <div class="flex items-start gap-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-text-primary">{{ item.product_name }}</p>
                                        <p v-if="item.variant_title" class="text-xs text-text-secondary">{{ t('orders.create.variant') }}: {{ item.variant_title }}</p>
                                        <p class="whitespace-nowrap text-xs text-text-tertiary">SKU: {{ item.sku }}</p>
                                        <p v-if="form.errors[`items.${index}.product_variant_id`]" :class="fieldError" :id="errorId(`items.${index}.product_variant_id`)">{{ form.errors[`items.${index}.product_variant_id`] }}</p>
                                        <p v-if="form.errors[`items.${index}.unit_price`]" :class="fieldError" :id="errorId(`items.${index}.unit_price`)">{{ form.errors[`items.${index}.unit_price`] }}</p>
                                        <p v-if="form.errors[`items.${index}.tax_rate`]" :class="fieldError" :id="errorId(`items.${index}.tax_rate`)">{{ form.errors[`items.${index}.tax_rate`] }}</p>
                                    </div>
                                    <button :aria-label="t('common.delete')" type="button" @click="removeItem(index)" class="shrink-0 rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-danger"><Trash2 :size="16" /></button>
                                </div>
                                <div class="mt-3 grid grid-cols-2 gap-3 @sm:grid-cols-3 @3xl:grid-cols-6">
                                    <div>
                                        <label :for="fieldIdPrefix + '-repeat-1-' + index" class="mb-1 block text-[11px] text-text-tertiary">{{ t('orders.edit.qty') }}</label>
                                        <input :id="fieldIdPrefix + '-repeat-1-' + index" :value="item.quantity" @input="updateItemQuantity(index, parseInt($event.target.value))" type="number" min="1" :class="fieldInput" v-bind="fieldErrorAttrs(`items.${index}.quantity`)" />
                                <p v-if="form.errors[`items.${index}.quantity`]" :id="errorId(`items.${index}.quantity`)" :class="fieldError">{{ form.errors[`items.${index}.quantity`] }}</p>
                                    </div>
                                    <div>
                                        <label :for="fieldIdPrefix + '-repeat-2-' + index" class="mb-1 block text-[11px] text-text-tertiary">{{ t('orders.show.unitPrice') }}</label>
                                        <input :id="fieldIdPrefix + '-repeat-2-' + index" :value="item.unit_price" @input="updateItemPrice(index, $event.target.value)" type="number" step="0.01" min="0" :class="fieldInput" v-bind="fieldErrorAttrs(`items.${index}.unit_price`)" />
                                    </div>
                                    <div>
                                        <label :for="fieldIdPrefix + '-repeat-3-' + index" class="mb-1 block text-[11px] text-text-tertiary">{{ t('tax.lineRate') }}</label>
                                        <input :id="fieldIdPrefix + '-repeat-3-' + index" v-model="item.tax_rate" type="number" step="0.001" min="0" max="100" :class="fieldInput" v-bind="fieldErrorAttrs(`items.${index}.tax_rate`)" />
                                    </div>
                                    <div class="text-end">
                                        <p class="mb-1 text-[11px] text-text-tertiary">{{ t('tax.net') }}</p>
                                        <p class="whitespace-nowrap font-semibold tabular-nums text-text-primary">{{ money(lineNetCents(item) / 100) }}</p>
                                    </div>
                                    <div class="text-end">
                                        <p class="mb-1 text-[11px] text-text-tertiary">{{ t('tax.lineTax') }}</p>
                                        <p class="whitespace-nowrap tabular-nums text-text-secondary">{{ money(totals.lineTaxes[index]) }}</p>
                                    </div>
                                    <div class="text-end">
                                        <p class="mb-1 text-[11px] text-text-tertiary">{{ t('tax.gross') }}</p>
                                        <p class="whitespace-nowrap font-semibold tabular-nums text-text-primary">{{ money(lineNetCents(item) / 100 + totals.lineTaxes[index]) }}</p>
                                    </div>
                                </div>
                                <div class="mt-3 @xl:w-1/2">
                                    <DiscountInput
                                        :id="`item_${index}_discount`"
                                        v-model:type="item.discount_type"
                                        v-model:value="item.discount_value"
                                        :label="t('discounts.lineDiscount')"
                                        :error="form.errors[`items.${index}.discount_value`] || form.errors[`items.${index}.discount_type`]"
                                        compact
                                    />
                                </div>
                                </div>
                            </div>
                            <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
                                <PackageOpen :size="22" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">{{ t('orders.create.noItemsAdded') }}</p>
                            </div>
                            <p v-if="form.errors.items" :class="fieldError" :id="errorId('items')">{{ form.errors.items }}</p>
                        </div>
                    </Card>
                </div>

                <!-- Right column -->
                <div class="space-y-4">
                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.create.orderDetails') }}</h3></div>
                        <div class="space-y-4 p-5">
                            <div>
                                <label for="order_date" :class="fieldLabel">{{ t('orders.create.orderDate') }}</label>
                                <input id="order_date" v-model="form.order_date" type="date" :class="fieldInput" required v-bind="fieldErrorAttrs('order_date')" />
                                <p v-if="form.errors.order_date" :class="fieldError" :id="errorId('order_date')">{{ form.errors.order_date }}</p>
                            </div>
                            <div>
                                <label for="currency" :class="fieldLabel">{{ t('orderCurrency.label') }}</label>
                                <select id="currency" v-model="form.currency" :class="fieldInput" @change="repriceLines" v-bind="fieldErrorAttrs('currency')">
                                    <option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }} - {{ currency.name }}</option>
                                </select>
                                <p class="mt-1 text-xs text-text-tertiary">{{ t('orderCurrency.hint') }}</p>
                                <p v-if="form.errors.currency" :class="fieldError" :id="errorId('currency')">{{ form.errors.currency }}</p>
                            </div>
                            <div>
                                <label for="status" :class="fieldLabel">{{ t('orders.create.statusLabel') }}</label>
                                <select id="status" v-model="form.status" :class="fieldInput" required v-bind="fieldErrorAttrs('status')">
                                    <option value="pending">{{ t('orders.status.pending') }}</option>
                                    <option value="processing">{{ t('orders.status.processing') }}</option>
                                    <option value="shipped" :disabled="ordersNeedApproval">{{ t('orders.status.shipped') }}</option>
                                    <option value="delivered" :disabled="ordersNeedApproval">{{ t('orders.status.delivered') }}</option>
                                    <option value="cancelled">{{ t('orders.status.cancelled') }}</option>
                                </select>
                                <p v-if="ordersNeedApproval" class="mt-1 text-xs text-text-tertiary">{{ t('orders.approval.statusBlocked') }}</p>
                                <p v-if="form.errors.status" :class="fieldError" :id="errorId('status')">{{ form.errors.status }}</p>
                            </div>
                            <div>
                                <label for="notes" :class="fieldLabel">{{ t('common.notes') }}</label>
                                <textarea id="notes" v-model="form.notes" rows="3" :class="fieldArea" :placeholder="t('orders.create.notesPlaceholder')" v-bind="fieldErrorAttrs('notes')"></textarea>
                                <p v-if="form.errors.notes" :class="fieldError" :id="errorId('notes')">{{ form.errors.notes }}</p>
                            </div>
                        </div>
                    </Card>

                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.create.orderSummary') }}</h3></div>
                        <div class="space-y-3 p-5">
                            <div class="flex justify-between text-sm">
                                <span class="text-text-secondary">{{ t('common.subtotal') }}</span>
                                <span class="font-medium tabular-nums text-text-primary">{{ money(totals.subtotal) }}</span>
                            </div>
                            <div v-if="totals.lineDiscounts > 0" class="flex justify-between text-sm">
                                <span class="text-text-secondary">{{ t('discounts.lineDiscounts') }}</span>
                                <span class="font-medium tabular-nums text-text-primary">-{{ money(totals.lineDiscounts) }}</span>
                            </div>
                            <div>
                                <DiscountInput
                                    id="order_discount"
                                    v-model:type="form.discount_type"
                                    v-model:value="form.discount_value"
                                    :label="t('discounts.orderDiscount')"
                                    :error="form.errors.discount_value || form.errors.discount_type"
                                />
                                <p class="mt-1 text-xs text-text-tertiary">{{ t('discounts.appliedBeforeTax') }}</p>
                            </div>
                            <div v-if="totals.orderDiscount > 0" class="flex justify-between text-sm">
                                <span class="text-text-secondary">{{ t('discounts.orderDiscount') }}</span>
                                <span class="font-medium tabular-nums text-text-primary">-{{ money(totals.orderDiscount) }}</span>
                            </div>
                            <template v-if="totals.lineRates">
                                <div v-for="entry in totals.taxByRate" :key="entry.rate" class="flex justify-between text-sm">
                                    <span class="text-text-secondary">{{ t('tax.byRate', { rate: formatTaxRate(entry.rate), amount: money(entry.taxable) }) }}</span>
                                    <span class="font-medium tabular-nums text-text-primary">{{ money(entry.tax) }}</span>
                                </div>
                                <p class="text-xs text-text-tertiary">{{ t('tax.fromLineRates') }}</p>
                                <p v-if="form.errors.tax" :class="fieldError" :id="errorId('tax')">{{ form.errors.tax }}</p>
                            </template>
                            <TaxInput v-else v-model="form.tax" :base="totals.taxable" :error="form.errors.tax" />
                            <div>
                                <label for="shipping" class="mb-1 block text-sm text-text-secondary">{{ t('common.shipping') }}</label>
                                <input id="shipping" v-model.number="form.shipping" type="number" step="0.01" min="0" :class="fieldInput" v-bind="fieldErrorAttrs('shipping')" />
                                <p v-if="form.errors.shipping" :class="fieldError" :id="errorId('shipping')">{{ form.errors.shipping }}</p>
                            </div>
                            <div class="flex items-center justify-between border-t border-border-subtle pt-3">
                                <span class="text-sm font-semibold text-text-primary">{{ t('common.total') }}</span>
                                <span class="text-xl font-bold text-brand">{{ money(totals.total) }}</span>
                            </div>
                            <p v-if="form.errors.total" :class="fieldError" :id="errorId('total')">{{ form.errors.total }}</p>
                            <p class="text-xs text-text-tertiary">{{ t('discounts.totalsComputedOnSave') }}</p>
                        </div>
                    </Card>


                </div>
            </div>
            <FormActions :label="t('orders.create.title')" :cancel-href="route('orders.index')" :processing="form.processing" :disabled="form.items.length === 0" />
        </form>

        <!-- Barcode Scanner Modal -->
        <BarcodeScannerModal
            :show="showScanner"
            @close="showScanner = false"
            @product-found="onScannedProduct"
        />
    </AppLayout>
</template>
