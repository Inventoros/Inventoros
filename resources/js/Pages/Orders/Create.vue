<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import CustomerPicker from '@/Components/CustomerPicker.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, defineAsyncComponent } from 'vue';
import { useBarcodeWedge, useBarcodeLookup } from '@/composables/useBarcodeWedge';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Plus, Trash2, PackageOpen, ScanLine } from 'lucide-vue-next';

const BarcodeScannerModal = defineAsyncComponent(() => import('@/Components/BarcodeScannerModal.vue'));

const { t } = useI18n();

const props = defineProps({
    products: Array,
});

const form = useForm({
    customer_id: null,
    customer_name: '',
    customer_email: '',
    customer_address: '',
    status: 'pending',
    order_date: new Date().toISOString().split('T')[0],
    shipping: 0,
    tax: 0,
    notes: '',
    items: [],
});

const selectedCustomer = ref(null);

const selectCustomer = (customer) => {
    selectedCustomer.value = customer;
    form.customer_id = customer.id;
    form.customer_name = customer.name;
    form.customer_email = customer.email ?? '';
    form.customer_address = customer.shipping_address || customer.billing_address || '';
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
            // Default to the variant's own price when it has one.
            unit_price: parseFloat(variant?.price ?? product.price) || 0,
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
            unit_price: parseFloat(variant?.price ?? product.price) || 0,
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

const subtotal = computed(() => form.items.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0));
const total = computed(() => subtotal.value + parseFloat(form.tax || 0) + parseFloat(form.shipping || 0));

const submit = () => {
    if (form.items.length === 0) {
        alert('Please add at least one product to the order.');
        return;
    }
    form.post(route('orders.store'), { preserveScroll: true });
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
                <Link :href="route('orders.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('orders.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('orders.create.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('orders.create.title')" description="Add a customer order and its line items.">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('orders.index')">
                    <ArrowLeft :size="14" />
                    {{ t('orders.create.backToOrders') }}
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6">
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <!-- Left column -->
                <div class="space-y-4 lg:col-span-2">
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
                            <div class="mb-5 rounded-lg border border-border-subtle bg-surface-canvas p-4">
                                <div class="grid grid-cols-1 gap-3 md:grid-cols-12">
                                    <div :class="chosenProduct?.has_variants ? 'md:col-span-4' : 'md:col-span-7'">
                                        <label for="add_product" :class="fieldLabel">{{ t('orders.create.selectProduct') }}</label>
                                        <select id="add_product" v-model="selectedProduct" :class="fieldInput" @change="onProductChange">
                                            <option :value="null">{{ t('orders.create.chooseProduct') }}</option>
                                            <option v-for="product in availableProducts" :key="product.id" :value="product.id">
                                                <template v-if="product.has_variants">{{ product.name }} ({{ product.sku }})</template>
                                                <template v-else>{{ product.name }} ({{ product.sku }}) - {{ t('orders.create.stockCount', { count: product.stock }) }} - ${{ product.price }}</template>
                                            </option>
                                        </select>
                                    </div>
                                    <div v-if="chosenProduct?.has_variants" class="md:col-span-3">
                                        <label for="add_variant" :class="fieldLabel">{{ t('orders.create.variant') }}</label>
                                        <select id="add_variant" v-model="selectedVariant" :class="fieldInput" @change="variantError = ''">
                                            <option :value="null">{{ t('orders.create.chooseVariant') }}</option>
                                            <option v-for="variant in availableVariants" :key="variant.id" :value="variant.id">
                                                {{ variant.title }}<template v-if="variant.sku"> ({{ variant.sku }})</template> - {{ t('orders.create.stockCount', { count: variant.stock }) }} - ${{ variant.price }}
                                            </option>
                                        </select>
                                        <p v-if="variantError" :class="fieldError">{{ variantError }}</p>
                                    </div>
                                    <div class="md:col-span-3">
                                        <label :class="fieldLabel">{{ t('common.quantity') }}</label>
                                        <input v-model.number="quantity" type="number" min="1" :class="fieldInput" />
                                    </div>
                                    <div class="flex items-end md:col-span-2">
                                        <Button type="button" variant="default" class="w-full" @click="addItem">
                                            <Plus :size="14" />{{ t('orders.create.add') }}
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            <!-- Items list -->
                            <div v-if="form.items.length > 0" class="space-y-3">
                                <div v-for="(item, index) in form.items" :key="index" class="flex flex-wrap items-center gap-4 rounded-lg border border-border-subtle bg-surface-canvas p-4">
                                    <div class="min-w-0 flex-1 basis-full sm:basis-0">
                                        <p class="font-medium text-text-primary">{{ item.product_name }}</p>
                                        <p v-if="item.variant_title" class="text-xs text-text-secondary">{{ t('orders.create.variant') }}: {{ item.variant_title }}</p>
                                        <p class="text-xs text-text-tertiary">SKU: {{ item.sku }}</p>
                                        <p v-if="form.errors[`items.${index}.product_variant_id`]" :class="fieldError">{{ form.errors[`items.${index}.product_variant_id`] }}</p>
                                    </div>
                                    <div class="w-20">
                                        <label class="mb-1 block text-[11px] text-text-tertiary">{{ t('orders.edit.qty') }}</label>
                                        <input :value="item.quantity" @input="updateItemQuantity(index, parseInt($event.target.value))" type="number" min="1" :class="fieldInput" />
                                    </div>
                                    <div class="w-28">
                                        <label class="mb-1 block text-[11px] text-text-tertiary">{{ t('orders.show.unitPrice') }}</label>
                                        <input :value="item.unit_price" @input="updateItemPrice(index, $event.target.value)" type="number" step="0.01" min="0" :class="fieldInput" />
                                    </div>
                                    <div class="w-24 text-right">
                                        <label class="mb-1 block text-[11px] text-text-tertiary">{{ t('common.total') }}</label>
                                        <p class="font-semibold tabular-nums text-text-primary">${{ (item.quantity * item.unit_price).toFixed(2) }}</p>
                                    </div>
                                    <button type="button" @click="removeItem(index)" class="mt-4 rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-danger"><Trash2 :size="16" /></button>
                                </div>
                            </div>
                            <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
                                <PackageOpen :size="22" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">{{ t('orders.create.noItemsAdded') }}</p>
                            </div>
                            <p v-if="form.errors.items" :class="fieldError">{{ form.errors.items }}</p>
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
                                <input id="order_date" v-model="form.order_date" type="date" :class="fieldInput" required />
                                <p v-if="form.errors.order_date" :class="fieldError">{{ form.errors.order_date }}</p>
                            </div>
                            <div>
                                <label for="status" :class="fieldLabel">{{ t('orders.create.statusLabel') }}</label>
                                <select id="status" v-model="form.status" :class="fieldInput" required>
                                    <option value="pending">{{ t('orders.status.pending') }}</option>
                                    <option value="processing">{{ t('orders.status.processing') }}</option>
                                    <option value="shipped">{{ t('orders.status.shipped') }}</option>
                                    <option value="delivered">{{ t('orders.status.delivered') }}</option>
                                    <option value="cancelled">{{ t('orders.status.cancelled') }}</option>
                                </select>
                                <p v-if="form.errors.status" :class="fieldError">{{ form.errors.status }}</p>
                            </div>
                            <div>
                                <label for="notes" :class="fieldLabel">{{ t('common.notes') }}</label>
                                <textarea id="notes" v-model="form.notes" rows="3" :class="fieldArea" :placeholder="t('orders.create.notesPlaceholder')"></textarea>
                                <p v-if="form.errors.notes" :class="fieldError">{{ form.errors.notes }}</p>
                            </div>
                        </div>
                    </Card>

                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.create.orderSummary') }}</h3></div>
                        <div class="space-y-3 p-5">
                            <div class="flex justify-between text-sm">
                                <span class="text-text-secondary">{{ t('common.subtotal') }}</span>
                                <span class="font-medium tabular-nums text-text-primary">${{ subtotal.toFixed(2) }}</span>
                            </div>
                            <div>
                                <label for="tax" class="mb-1 block text-sm text-text-secondary">{{ t('common.tax') }}</label>
                                <input id="tax" v-model.number="form.tax" type="number" step="0.01" min="0" :class="fieldInput" />
                                <p v-if="form.errors.tax" :class="fieldError">{{ form.errors.tax }}</p>
                            </div>
                            <div>
                                <label for="shipping" class="mb-1 block text-sm text-text-secondary">{{ t('common.shipping') }}</label>
                                <input id="shipping" v-model.number="form.shipping" type="number" step="0.01" min="0" :class="fieldInput" />
                                <p v-if="form.errors.shipping" :class="fieldError">{{ form.errors.shipping }}</p>
                            </div>
                            <div class="flex items-center justify-between border-t border-border-subtle pt-3">
                                <span class="text-sm font-semibold text-text-primary">{{ t('common.total') }}</span>
                                <span class="text-xl font-bold text-brand">${{ total.toFixed(2) }}</span>
                            </div>
                        </div>
                    </Card>

                    <div class="flex flex-col gap-2">
                        <Button type="submit" variant="default" size="lg" class="w-full" :loading="form.processing" :disabled="form.processing || form.items.length === 0">
                            {{ form.processing ? t('orders.create.creatingOrder') : t('orders.create.title') }}
                        </Button>
                        <Button variant="secondary" size="lg" class="w-full" as="Link" :href="route('orders.index')">{{ t('common.cancel') }}</Button>
                    </div>
                </div>
            </div>
        </form>

        <!-- Barcode Scanner Modal -->
        <BarcodeScannerModal
            :show="showScanner"
            @close="showScanner = false"
            @product-found="onScannedProduct"
        />
    </AppLayout>
</template>
