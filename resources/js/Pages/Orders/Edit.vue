<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import TaxInput from '@/Components/TaxInput.vue';
import CustomerPicker from '@/Components/CustomerPicker.vue';
import ProductPicker from '@/Components/ui/ProductPicker.vue';
import DiscountInput from '@/Components/DiscountInput.vue';
import { useOrderTotals, lineNetCents } from '@/composables/useOrderTotals';
import { formatTaxRate, rateValue } from '@/lib/lineTax';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { displayCalendarDate, displayDateTime, toIsoDate } from '@/lib/dates';
import { formatMoney } from '@/lib/money';
import { ArrowLeft, Plus, Trash2, PackageOpen } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    order: Object,
    // The tax entered for the whole order (order.tax less its line taxes).
    orderLevelTax: { type: [String, Number], default: 0 },
    products: Array,
    // Waiting for approval: it cannot be shipped or delivered yet.
    awaitingApproval: { type: Boolean, default: false },
    // Y-m-d while preorder items hold the order from shipping.
    awaitingRelease: { type: String, default: null },
});

// Status only moves forward (the server enforces it): cancelled and delivered
// are final and a shipped order can only be delivered.
const reachableStatuses = {
    cancelled: ['cancelled'],
    delivered: ['delivered'],
    shipped: ['shipped', 'delivered'],
};
const canMoveTo = (status) => {
    const allowed = reachableStatuses[props.order.status];

    return !allowed || allowed.includes(status);
};

const form = useForm({
    customer_id: props.order.customer_id ?? null,
    customer_name: props.order.customer_name,
    customer_email: props.order.customer_email,
    customer_address: props.order.customer_address,
    status: props.order.status,
    order_date: toIsoDate(props.order.order_date),
    shipping: props.order.shipping || 0,
    tax: parseFloat(props.orderLevelTax) || 0,
    discount_type: props.order.discount_type ?? '',
    discount_value: props.order.discount_value != null ? parseFloat(props.order.discount_value) : null,
    notes: props.order.notes || '',
    items: props.order.items.map(item => ({
        id: item.id,
        product_id: item.product_id,
        product_variant_id: item.product_variant_id ?? null,
        quantity: item.quantity,
        unit_price: parseFloat(item.unit_price),
        discount_type: item.discount_type ?? '',
        discount_value: item.discount_value != null ? parseFloat(item.discount_value) : null,
        tax_rate: item.tax_rate != null ? parseFloat(item.tax_rate) : '',
        // A line without a rate keeps the tax amount it was saved with
        // (imported lines); a rated line's tax is recomputed on save.
        tax: item.tax_rate == null ? parseFloat(item.tax) || 0 : 0,
    })),
});

const selectedCustomer = ref(props.order.customer ?? null);

const selectCustomer = (customer) => {
    selectedCustomer.value = customer;
    form.customer_id = customer.id;
    form.customer_name = customer.name;
    form.customer_email = customer.email ?? '';
    form.customer_address = customer.shipping_address || customer.billing_address || '';
};

// Back to a one-off customer: unlink, but keep the typed details editable.
const clearCustomer = () => {
    selectedCustomer.value = null;
    form.customer_id = null;
};

const productFor = (productId) => props.products.find(p => p.id === productId) ?? null;

// The variants a line can pick from: the product's active variants, plus the
// one the line already holds (the backend keeps it in the list even if it has
// since been deactivated).
const variantsFor = (item) => {
    const product = productFor(item.product_id);
    if (!product?.has_variants) return [];
    return product.variants.filter(v => v.is_active || v.id === item.product_variant_id);
};

// Preview only: the server recomputes every total on save.
const totals = useOrderTotals(form);

const submit = () => {
    // Once a line has a tax rate the order's tax is the sum of the line
    // taxes, and the server refuses an order-level tax on top of it.
    form
        .transform((data) => (totals.value.lineRates ? { ...data, tax: 0 } : data))
        .put(route('orders.update', props.order.id), {
            preserveScroll: true,
        });
};

// A line's product's tax rate (variants share it); blank when it has none.
const productTaxRate = (product) => (product?.tax_rate != null && product.tax_rate !== '' ? parseFloat(product.tax_rate) : '');

const addItem = () => {
    form.items.push({
        id: null,
        product_id: '',
        product_variant_id: null,
        quantity: 1,
        unit_price: 0,
        discount_type: '',
        discount_value: null,
        tax_rate: '',
        tax: 0,
    });
};

const removeItem = (index) => {
    form.items.splice(index, 1);
};

// A new product choice resets the line's variant; the price defaults to the
// product's (or, once a variant is picked, the variant's) price.
const onProductChange = (index) => {
    const item = form.items[index];
    item.product_variant_id = null;
    const product = productFor(item.product_id);
    if (product && !product.has_variants) {
        item.unit_price = parseFloat(product.price) || 0;
    }
    // A different product starts at its own tax rate.
    item.tax_rate = productTaxRate(product);
    item.tax = 0;
};

const onVariantChange = (index) => {
    const item = form.items[index];
    const variant = productFor(item.product_id)?.variants.find(v => v.id === item.product_variant_id);
    if (variant) {
        item.unit_price = parseFloat(variant.price) || 0;
    }
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('orders.edit.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('orders.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('orders.edit.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('orders.edit.title')" :description="t('orders.edit.orderNumber', { number: order.order_number })">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('orders.index')">
                    <ArrowLeft :size="14" />
                    {{ t('orders.edit.backToOrders') }}
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6">
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                <!-- Left column -->
                <div class="space-y-4 xl:col-span-2">
                    <!-- Customer info -->
                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.create.customerInfo') }}</h3></div>
                        <div class="space-y-4 p-5">
                            <CustomerPicker :selected="selectedCustomer" @select="selectCustomer" @clear="clearCustomer" />
                            <p v-if="form.errors.customer_id" :class="fieldError">{{ form.errors.customer_id }}</p>
                            <div>
                                <label for="customer_name" :class="fieldLabel">{{ t('orders.create.customerName') }}</label>
                                <input id="customer_name" v-model="form.customer_name" type="text" :class="fieldInput" required />
                                <p v-if="form.errors.customer_name" :class="fieldError">{{ form.errors.customer_name }}</p>
                            </div>
                            <div>
                                <label for="customer_email" :class="fieldLabel">{{ t('orders.create.customerEmail') }}</label>
                                <input id="customer_email" v-model="form.customer_email" type="email" :class="fieldInput" />
                                <p v-if="form.errors.customer_email" :class="fieldError">{{ form.errors.customer_email }}</p>
                            </div>
                            <div>
                                <label for="customer_address" :class="fieldLabel">{{ t('orders.create.shippingAddress') }}</label>
                                <textarea id="customer_address" v-model="form.customer_address" rows="3" :class="fieldArea"></textarea>
                                <p v-if="form.errors.customer_address" :class="fieldError">{{ form.errors.customer_address }}</p>
                            </div>
                        </div>
                    </Card>

                    <!-- Order items (editable) -->
                    <Card :padded="false">
                        <div class="flex items-center justify-between px-5 pt-5">
                            <h3 class="text-sm font-semibold text-text-primary">{{ t('orders.create.orderItems') }}</h3>
                            <Button type="button" variant="default" size="sm" @click="addItem">
                                <Plus :size="14" />{{ t('orders.edit.addItem') }}
                            </Button>
                        </div>
                        <div class="p-5">
                            <p v-if="form.errors.items" :class="fieldError">{{ form.errors.items }}</p>

                            <div v-if="form.items.length === 0" class="flex flex-col items-center gap-3 py-8 text-center">
                                <PackageOpen :size="22" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">{{ t('orders.edit.noItems') }}</p>
                                <Button type="button" variant="default" size="sm" @click="addItem">
                                    <Plus :size="14" />{{ t('orders.edit.addFirstItem') }}
                                </Button>
                            </div>

                            <div v-else class="space-y-3">
                                <div
                                    v-for="(item, index) in form.items"
                                    :key="index"
                                    class="@container rounded-lg border border-border-subtle bg-surface-canvas p-4"
                                >
                                    <!-- Laid out by the card's width: the sidebar columns and
                                         app navigation leave far less room than the viewport. -->
                                    <div class="grid grid-cols-2 gap-3 @lg:grid-cols-[repeat(3,minmax(0,1fr))_auto] @3xl:grid-cols-12">
                                        <!-- Product selection -->
                                        <div class="col-span-full @3xl:col-span-5">
                                            <label :for="`product-${index}`" :class="fieldLabel">{{ t('common.product') }}</label>
                                            <ProductPicker
                                                :id="`product-${index}`"
                                                v-model="item.product_id"
                                                :products="products"
                                                :stock-label="t('components.productPicker.onHand')"
                                                :invalid="Boolean(form.errors[`items.${index}.product_id`])"
                                                required
                                                @change="onProductChange(index)"
                                            >
                                                <template #details="{ product }"><span v-if="!product.has_variants" class="block text-xs text-text-secondary">{{ formatMoney(product.price, product.currency) }}</span></template>
                                            </ProductPicker>
                                            <p v-if="form.errors[`items.${index}.product_id`]" :class="fieldError">
                                                {{ form.errors[`items.${index}.product_id`] }}
                                            </p>
                                            <template v-if="productFor(item.product_id)?.has_variants">
                                                <label :for="`variant-${index}`" class="mb-1 mt-3 block text-sm font-medium text-text-secondary">{{ t('orders.edit.variant') }}</label>
                                                <select
                                                    :id="`variant-${index}`"
                                                    v-model="item.product_variant_id"
                                                    @change="onVariantChange(index)"
                                                    :class="fieldInput"
                                                    required
                                                >
                                                    <option :value="null">{{ t('orders.create.chooseVariant') }}</option>
                                                    <option v-for="variant in variantsFor(item)" :key="variant.id" :value="variant.id">
                                                        {{ variant.title }}<template v-if="variant.sku"> ({{ variant.sku }})</template> - {{ t('orders.create.stockCount', { count: variant.stock }) }} - {{ formatMoney(variant.price, order.currency) }}
                                                    </option>
                                                </select>
                                            </template>
                                            <p v-if="form.errors[`items.${index}.product_variant_id`]" :class="fieldError">
                                                {{ form.errors[`items.${index}.product_variant_id`] }}
                                            </p>
                                        </div>

                                        <!-- Quantity -->
                                        <div class="@3xl:col-span-2">
                                            <label :for="`quantity-${index}`" :class="fieldLabel">{{ t('orders.edit.qty') }}</label>
                                            <input
                                                :id="`quantity-${index}`"
                                                v-model.number="item.quantity"
                                                type="number"
                                                min="1"
                                                step="1"
                                                :class="fieldInput"
                                                required
                                            />
                                            <p v-if="form.errors[`items.${index}.quantity`]" :class="fieldError">
                                                {{ form.errors[`items.${index}.quantity`] }}
                                            </p>
                                        </div>

                                        <!-- Unit price -->
                                        <div class="@3xl:col-span-2">
                                            <label :for="`price-${index}`" :class="fieldLabel">{{ t('orders.edit.unitPrice') }}</label>
                                            <input
                                                :id="`price-${index}`"
                                                v-model.number="item.unit_price"
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                :class="fieldInput"
                                                required
                                            />
                                            <p v-if="form.errors[`items.${index}.unit_price`]" :class="fieldError">
                                                {{ form.errors[`items.${index}.unit_price`] }}
                                            </p>
                                        </div>

                                        <!-- Net -->
                                        <div class="@3xl:col-span-2">
                                            <p :class="fieldLabel">{{ t('tax.net') }}</p>
                                            <div class="flex h-9 items-center rounded-md border border-border-subtle bg-surface-sunken px-3">
                                                <span class="whitespace-nowrap text-sm font-semibold tabular-nums text-text-primary">
                                                    {{ formatMoney(lineNetCents(item) / 100, order.currency) }}
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Remove button -->
                                        <div class="flex items-end @3xl:col-span-1">
                                            <button :aria-label="t('common.delete')"
                                                type="button"
                                                @click="removeItem(index)"
                                                class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-danger"
                                                :title="t('orders.edit.removeItem')"
                                            >
                                                <Trash2 :size="16" />
                                            </button>
                                        </div>
                                    </div>
                                    <div class="mt-3 grid grid-cols-2 gap-3 @sm:grid-cols-3 @3xl:grid-cols-12">
                                        <div class="col-span-full @3xl:col-span-6">
                                            <DiscountInput
                                                :id="`item_${index}_discount`"
                                                v-model:type="item.discount_type"
                                                v-model:value="item.discount_value"
                                                :label="t('discounts.lineDiscount')"
                                                :error="form.errors[`items.${index}.discount_value`] || form.errors[`items.${index}.discount_type`]"
                                                compact
                                            />
                                        </div>
                                        <!-- Line tax -->
                                        <div class="@3xl:col-span-2">
                                            <label :for="`tax-rate-${index}`" class="mb-1 block text-[11px] text-text-tertiary">{{ t('tax.lineRate') }}</label>
                                            <input
                                                :id="`tax-rate-${index}`"
                                                v-model="item.tax_rate"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.001"
                                                :class="fieldInput"
                                            />
                                            <p v-if="form.errors[`items.${index}.tax_rate`]" :class="fieldError">
                                                {{ form.errors[`items.${index}.tax_rate`] }}
                                            </p>
                                        </div>
                                        <div class="text-end @3xl:col-span-2">
                                            <p class="mb-1 text-[11px] text-text-tertiary">{{ t('tax.lineTax') }}</p>
                                            <p class="whitespace-nowrap tabular-nums text-text-secondary">{{ formatMoney(totals.lineTaxes[index], order.currency) }}</p>
                                            <p v-if="rateValue(item.tax_rate) === null && item.tax > 0" class="text-[11px] text-text-tertiary">{{ t('tax.enteredAmount') }}</p>
                                        </div>
                                        <div class="text-end @3xl:col-span-2">
                                            <p class="mb-1 text-[11px] text-text-tertiary">{{ t('tax.gross') }}</p>
                                            <p class="whitespace-nowrap font-semibold tabular-nums text-text-primary">{{ formatMoney(lineNetCents(item) / 100 + totals.lineTaxes[index], order.currency) }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Card>
                </div>

                <!-- Right column -->
                <div class="space-y-4">
                    <!-- Order details -->
                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.create.orderDetails') }}</h3></div>
                        <div class="space-y-4 p-5">
                            <div>
                                <label for="order_date" :class="fieldLabel">{{ t('orders.create.orderDate') }}</label>
                                <input id="order_date" v-model="form.order_date" type="date" :class="fieldInput" required />
                                <p v-if="form.errors.order_date" :class="fieldError">{{ form.errors.order_date }}</p>
                            </div>
                            <div>
                                <label for="status" :class="fieldLabel">{{ t('orders.create.statusLabel') }}</label>
                                <select id="status" v-model="form.status" :class="fieldInput" required>
                                    <option value="pending" :disabled="!canMoveTo('pending')">{{ t('orders.status.pending') }}</option>
                                    <option value="processing" :disabled="!canMoveTo('processing')">{{ t('orders.status.processing') }}</option>
                                    <option value="shipped" :disabled="awaitingApproval || !!awaitingRelease || !canMoveTo('shipped')">{{ t('orders.status.shipped') }}</option>
                                    <option value="delivered" :disabled="awaitingApproval || !!awaitingRelease || !canMoveTo('delivered')">{{ t('orders.status.delivered') }}</option>
                                    <option value="cancelled" :disabled="!canMoveTo('cancelled')">{{ t('orders.status.cancelled') }}</option>
                                </select>
                                <p v-if="awaitingApproval" class="mt-1 text-xs text-text-tertiary">{{ t('orders.approval.statusBlocked') }}</p>
                                <p v-if="awaitingRelease" class="mt-1 text-xs text-text-tertiary">{{ t('backorders.show.preorderBanner', { date: displayCalendarDate(awaitingRelease) }) }}</p>
                                <p v-if="form.errors.status" :class="fieldError">{{ form.errors.status }}</p>
                            </div>

                            <div v-if="order.shipped_at" class="text-sm">
                                <p class="text-text-tertiary">{{ t('orders.edit.shippedAt') }}</p>
                                <p class="font-medium text-text-primary">{{ displayDateTime(order.shipped_at) }}</p>
                            </div>

                            <div v-if="order.delivered_at" class="text-sm">
                                <p class="text-text-tertiary">{{ t('orders.edit.deliveredAt') }}</p>
                                <p class="font-medium text-text-primary">{{ displayDateTime(order.delivered_at) }}</p>
                            </div>

                            <div>
                                <label for="notes" :class="fieldLabel">{{ t('common.notes') }}</label>
                                <textarea id="notes" v-model="form.notes" rows="3" :class="fieldArea" :placeholder="t('orders.create.notesPlaceholder')"></textarea>
                                <p v-if="form.errors.notes" :class="fieldError">{{ form.errors.notes }}</p>
                            </div>
                        </div>
                    </Card>

                    <!-- Order summary -->
                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.create.orderSummary') }}</h3></div>
                        <div class="space-y-3 p-5">
                            <div class="flex justify-between text-sm">
                                <span class="text-text-secondary">{{ t('common.subtotal') }}</span>
                                <span class="font-medium tabular-nums text-text-primary">{{ formatMoney(totals.subtotal, order.currency) }}</span>
                            </div>
                            <div v-if="totals.lineDiscounts > 0" class="flex justify-between text-sm">
                                <span class="text-text-secondary">{{ t('discounts.lineDiscounts') }}</span>
                                <span class="font-medium tabular-nums text-text-primary">-{{ formatMoney(totals.lineDiscounts, order.currency) }}</span>
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
                                <span class="font-medium tabular-nums text-text-primary">-{{ formatMoney(totals.orderDiscount, order.currency) }}</span>
                            </div>
                            <template v-if="totals.lineRates">
                                <div v-for="entry in totals.taxByRate" :key="entry.rate" class="flex justify-between text-sm">
                                    <span class="text-text-secondary">{{ t('tax.byRate', { rate: formatTaxRate(entry.rate), amount: formatMoney(entry.taxable, order.currency) }) }}</span>
                                    <span class="font-medium tabular-nums text-text-primary">{{ formatMoney(entry.tax, order.currency) }}</span>
                                </div>
                                <p class="text-xs text-text-tertiary">{{ t('tax.fromLineRates') }}</p>
                                <p v-if="form.errors.tax" :class="fieldError">{{ form.errors.tax }}</p>
                            </template>
                            <TaxInput v-else v-model="form.tax" :base="totals.taxable" :error="form.errors.tax" />
                            <div>
                                <label for="shipping" class="mb-1 block text-sm text-text-secondary">{{ t('common.shipping') }}</label>
                                <input id="shipping" v-model.number="form.shipping" type="number" step="0.01" min="0" :class="fieldInput" />
                                <p v-if="form.errors.shipping" :class="fieldError">{{ form.errors.shipping }}</p>
                            </div>
                            <div class="flex items-center justify-between border-t border-border-subtle pt-3">
                                <span class="text-sm font-semibold text-text-primary">{{ t('common.total') }}</span>
                                <span class="text-xl font-bold text-brand">{{ formatMoney(totals.total, order.currency) }}</span>
                            </div>
                            <p v-if="form.errors.total" :class="fieldError">{{ form.errors.total }}</p>
                            <p class="text-xs text-text-tertiary">{{ t('discounts.totalsComputedOnSave') }}</p>
                        </div>
                    </Card>

                    <!-- Form actions -->
                    <div class="flex flex-col gap-2">
                        <Button type="submit" variant="default" size="lg" class="w-full" :loading="form.processing" :disabled="form.processing">
                            {{ form.processing ? t('orders.edit.updatingOrder') : t('orders.edit.updateOrder') }}
                        </Button>
                        <Button variant="secondary" size="lg" class="w-full" as="Link" :href="route('orders.show', order.id)">{{ t('orders.edit.viewDetails') }}</Button>
                        <Button variant="secondary" size="lg" class="w-full" as="Link" :href="route('orders.index')">{{ t('common.cancel') }}</Button>
                    </div>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
