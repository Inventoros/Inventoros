<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Plus, Trash2 } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    purchaseOrder: Object,
    suppliers: Array,
    products: Array,
    pluginComponents: Object,
});

const form = useForm({
    supplier_id: props.purchaseOrder.supplier_id,
    order_date: props.purchaseOrder.order_date?.split('T')[0] || '',
    expected_date: props.purchaseOrder.expected_date?.split('T')[0] || '',
    currency: props.purchaseOrder.currency || 'USD',
    shipping: props.purchaseOrder.shipping || 0,
    tax: props.purchaseOrder.tax || 0,
    notes: props.purchaseOrder.notes || '',
    items: props.purchaseOrder.items?.map(item => ({
        id: item.id,
        product_id: item.product_id,
        product_name: item.product_name,
        sku: item.sku,
        quantity: item.quantity_ordered,
        unit_cost: item.unit_cost,
        supplier_sku: item.supplier_sku || '',
    })) || [],
});

const selectedProductId = ref('');
const quantity = ref(1);
const unitCost = ref(0);
const supplierSku = ref('');

const subtotal = computed(() => {
    return form.items.reduce((sum, item) => sum + (item.quantity * item.unit_cost), 0);
});

const total = computed(() => {
    return subtotal.value + (parseFloat(form.tax) || 0) + (parseFloat(form.shipping) || 0);
});

const addItem = () => {
    if (!selectedProductId.value || quantity.value < 1) return;

    const product = props.products.find(p => p.id == selectedProductId.value);
    if (!product) return;

    const existingIndex = form.items.findIndex(item => item.product_id == selectedProductId.value);
    if (existingIndex >= 0) {
        form.items[existingIndex].quantity += quantity.value;
        form.items[existingIndex].unit_cost = unitCost.value;
    } else {
        form.items.push({
            id: null,
            product_id: product.id,
            product_name: product.name,
            sku: product.sku,
            quantity: quantity.value,
            unit_cost: unitCost.value || product.purchase_price || product.price || 0,
            supplier_sku: supplierSku.value,
        });
    }

    selectedProductId.value = '';
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

const onProductSelected = () => {
    const product = props.products.find(p => p.id == selectedProductId.value);
    if (product) {
        unitCost.value = product.purchase_price || product.price || 0;
    }
};

const submit = () => {
    form.put(route('purchase-orders.update', props.purchaseOrder.id));
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: form.currency || 'USD',
    }).format(value || 0);
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
                <Link :href="route('purchase-orders.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('purchase-orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('purchaseOrders.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('purchaseOrders.edit.title') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ purchaseOrder.po_number }}</span>
            </div>
        </template>

        <PageHeader :title="t('purchaseOrders.edit.title')" :description="`PO #${purchaseOrder.po_number}`">
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
                            <select id="currency" v-model="form.currency" :class="fieldInput">
                                <option value="USD">{{ t('purchaseOrders.currencies.usd') }}</option>
                                <option value="EUR">{{ t('purchaseOrders.currencies.eur') }}</option>
                                <option value="GBP">{{ t('purchaseOrders.currencies.gbp') }}</option>
                                <option value="CAD">{{ t('purchaseOrders.currencies.cad') }}</option>
                                <option value="AUD">{{ t('purchaseOrders.currencies.aud') }}</option>
                            </select>
                            <p v-if="form.errors.currency" :class="fieldError">{{ form.errors.currency }}</p>
                        </div>

                        <div>
                            <label for="order_date" :class="fieldLabel">{{ t('purchaseOrders.create.orderDate') }}</label>
                            <input id="order_date" v-model="form.order_date" type="date" :class="fieldInput" required />
                            <p v-if="form.errors.order_date" :class="fieldError">{{ form.errors.order_date }}</p>
                        </div>

                        <div>
                            <label for="expected_date" :class="fieldLabel">Expected Delivery Date</label>
                            <input id="expected_date" v-model="form.expected_date" type="date" :class="fieldInput" />
                            <p v-if="form.errors.expected_date" :class="fieldError">{{ form.errors.expected_date }}</p>
                        </div>
                    </div>

                    <div>
                        <label for="notes" :class="fieldLabel">Notes</label>
                        <textarea id="notes" v-model="form.notes" rows="3" :class="fieldArea"></textarea>
                        <p v-if="form.errors.notes" :class="fieldError">{{ form.errors.notes }}</p>
                    </div>
                </div>
            </Card>

            <!-- Add Items -->
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">Add Items</h3>
                </div>
                <div class="space-y-4 p-5">
                    <div class="rounded-lg border border-border-subtle bg-surface-canvas p-4">
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-6">
                            <div class="md:col-span-2">
                                <label for="product" :class="fieldLabel">Product</label>
                                <select id="product" v-model="selectedProductId" @change="onProductSelected" :class="fieldInput">
                                    <option value="">Select a product</option>
                                    <option v-for="product in products" :key="product.id" :value="product.id">
                                        {{ product.name }} ({{ product.sku || 'No SKU' }})
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label for="quantity" :class="fieldLabel">Quantity</label>
                                <input id="quantity" v-model.number="quantity" type="number" min="1" :class="fieldInput" />
                            </div>

                            <div>
                                <label for="unit_cost" :class="fieldLabel">Unit Cost</label>
                                <input id="unit_cost" v-model.number="unitCost" type="number" step="0.01" min="0" :class="fieldInput" />
                            </div>

                            <div>
                                <label for="supplier_sku" :class="fieldLabel">Supplier SKU</label>
                                <input id="supplier_sku" v-model="supplierSku" type="text" :class="fieldInput" placeholder="Optional" />
                            </div>

                            <div class="flex items-end">
                                <Button type="button" variant="default" class="w-full" :disabled="!selectedProductId" @click="addItem">
                                    <Plus :size="14" />Add
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
                    <h3 class="text-sm font-semibold text-text-primary">Order Items ({{ form.items.length }})</h3>
                </div>
                <div class="space-y-3 p-5">
                    <div
                        v-for="(item, index) in form.items"
                        :key="index"
                        class="rounded-lg border border-border-subtle bg-surface-canvas p-4"
                    >
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-12">
                            <div class="md:col-span-4">
                                <p class="text-sm font-medium text-text-primary">{{ item.product_name }}</p>
                                <p class="text-xs text-text-tertiary">SKU: {{ item.sku || '-' }}</p>
                                <p class="text-xs text-text-tertiary">Supplier SKU: {{ item.supplier_sku || '-' }}</p>
                            </div>

                            <div class="md:col-span-2">
                                <label :for="`quantity-${index}`" :class="fieldLabel">Quantity</label>
                                <input
                                    :id="`quantity-${index}`"
                                    type="number"
                                    :value="item.quantity"
                                    @input="updateItemQuantity(index, parseInt($event.target.value))"
                                    min="1"
                                    :class="fieldInput"
                                />
                            </div>

                            <div class="md:col-span-2">
                                <label :for="`cost-${index}`" :class="fieldLabel">Unit Cost</label>
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

                            <div class="md:col-span-3">
                                <label :class="fieldLabel">Subtotal</label>
                                <div class="flex h-9 items-center rounded-md border border-border-subtle bg-surface-sunken px-3">
                                    <span class="text-sm font-semibold tabular-nums text-text-primary">
                                        {{ formatCurrency(item.quantity * item.unit_cost) }}
                                    </span>
                                </div>
                            </div>

                            <div class="flex items-end md:col-span-1">
                                <button
                                    type="button"
                                    @click="removeItem(index)"
                                    class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-danger"
                                    title="Remove item"
                                >
                                    <Trash2 :size="16" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Totals -->
                    <div class="flex justify-end pt-2">
                        <div class="w-full max-w-xs space-y-3 border-t border-border-subtle pt-3">
                            <div class="flex justify-between text-sm">
                                <span class="text-text-secondary">Subtotal</span>
                                <span class="font-medium tabular-nums text-text-primary">{{ formatCurrency(subtotal) }}</span>
                            </div>
                            <div>
                                <label for="tax" class="mb-1 block text-sm text-text-secondary">Tax</label>
                                <input id="tax" v-model.number="form.tax" type="number" step="0.01" min="0" :class="fieldInput" />
                            </div>
                            <div>
                                <label for="shipping" class="mb-1 block text-sm text-text-secondary">Shipping</label>
                                <input id="shipping" v-model.number="form.shipping" type="number" step="0.01" min="0" :class="fieldInput" />
                            </div>
                            <div class="flex items-center justify-between border-t border-border-subtle pt-3">
                                <span class="text-sm font-semibold text-text-primary">Total</span>
                                <span class="text-xl font-bold text-brand">{{ formatCurrency(total) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" size="lg" as="Link" :href="route('purchase-orders.show', purchaseOrder.id)">
                    Cancel
                </Button>
                <Button
                    type="submit"
                    variant="default"
                    size="lg"
                    :loading="form.processing"
                    :disabled="form.processing || form.items.length === 0"
                >
                    Update Purchase Order
                </Button>
            </div>
        </form>

        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
    </AppLayout>
</template>

