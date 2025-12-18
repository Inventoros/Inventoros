<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

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
</script>

<template>
    <Head :title="`Edit ${purchaseOrder.po_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    Edit Purchase Order
                </h2>
                <Link
                    :href="route('purchase-orders.index')"
                    class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                >
                    Back to Purchase Orders
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
                <PluginSlot slot="header" :components="pluginComponents?.header" />

                <form @submit.prevent="submit" class="space-y-6">
                    <!-- Order Details -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Order Details - {{ purchaseOrder.po_number }}</h3>
                        </div>
                        <div class="p-6 space-y-6">
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel for="supplier_id" value="Supplier *" />
                                    <select
                                        id="supplier_id"
                                        v-model="form.supplier_id"
                                        required
                                        class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    >
                                        <option value="">Select a supplier</option>
                                        <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">
                                            {{ supplier.name }}
                                        </option>
                                    </select>
                                    <InputError :message="form.errors.supplier_id" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="currency" value="Currency" />
                                    <select
                                        id="currency"
                                        v-model="form.currency"
                                        class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    >
                                        <option value="USD">USD - US Dollar</option>
                                        <option value="EUR">EUR - Euro</option>
                                        <option value="GBP">GBP - British Pound</option>
                                        <option value="CAD">CAD - Canadian Dollar</option>
                                        <option value="AUD">AUD - Australian Dollar</option>
                                    </select>
                                    <InputError :message="form.errors.currency" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="order_date" value="Order Date *" />
                                    <TextInput
                                        id="order_date"
                                        v-model="form.order_date"
                                        type="date"
                                        class="mt-1 block w-full"
                                        required
                                    />
                                    <InputError :message="form.errors.order_date" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="expected_date" value="Expected Delivery Date" />
                                    <TextInput
                                        id="expected_date"
                                        v-model="form.expected_date"
                                        type="date"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.expected_date" class="mt-2" />
                                </div>
                            </div>

                            <div>
                                <InputLabel for="notes" value="Notes" />
                                <textarea
                                    id="notes"
                                    v-model="form.notes"
                                    rows="3"
                                    class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                ></textarea>
                                <InputError :message="form.errors.notes" class="mt-2" />
                            </div>
                        </div>
                    </div>

                    <!-- Add Items -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Add Items</h3>
                        </div>
                        <div class="p-6 space-y-4">
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
                                <div class="md:col-span-2">
                                    <InputLabel for="product" value="Product" />
                                    <select
                                        id="product"
                                        v-model="selectedProductId"
                                        @change="onProductSelected"
                                        class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    >
                                        <option value="">Select a product</option>
                                        <option v-for="product in products" :key="product.id" :value="product.id">
                                            {{ product.name }} ({{ product.sku || 'No SKU' }})
                                        </option>
                                    </select>
                                </div>

                                <div>
                                    <InputLabel for="quantity" value="Quantity" />
                                    <TextInput
                                        id="quantity"
                                        v-model.number="quantity"
                                        type="number"
                                        min="1"
                                        class="mt-1 block w-full"
                                    />
                                </div>

                                <div>
                                    <InputLabel for="unit_cost" value="Unit Cost" />
                                    <TextInput
                                        id="unit_cost"
                                        v-model.number="unitCost"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="mt-1 block w-full"
                                    />
                                </div>

                                <div>
                                    <InputLabel for="supplier_sku" value="Supplier SKU" />
                                    <TextInput
                                        id="supplier_sku"
                                        v-model="supplierSku"
                                        type="text"
                                        class="mt-1 block w-full"
                                        placeholder="Optional"
                                    />
                                </div>

                                <div class="flex items-end">
                                    <button
                                        type="button"
                                        @click="addItem"
                                        :disabled="!selectedProductId"
                                        class="w-full inline-flex justify-center items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 disabled:opacity-50"
                                    >
                                        Add
                                    </button>
                                </div>
                            </div>

                            <InputError :message="form.errors.items" class="mt-2" />
                        </div>
                    </div>

                    <!-- Items Table -->
                    <div v-if="form.items.length > 0" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Order Items ({{ form.items.length }})</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                                <thead class="bg-gray-50 dark:bg-dark-bg">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Product</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">SKU</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Supplier SKU</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Quantity</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Unit Cost</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Subtotal</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-dark-card divide-y divide-gray-200 dark:divide-dark-border">
                                    <tr v-for="(item, index) in form.items" :key="index">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                            {{ item.product_name }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ item.sku || '-' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ item.supplier_sku || '-' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input
                                                type="number"
                                                :value="item.quantity"
                                                @input="updateItemQuantity(index, parseInt($event.target.value))"
                                                min="1"
                                                class="w-20 rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm"
                                            />
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input
                                                type="number"
                                                :value="item.unit_cost"
                                                @input="updateItemCost(index, $event.target.value)"
                                                step="0.01"
                                                min="0"
                                                class="w-24 rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm"
                                            />
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100 font-medium">
                                            {{ formatCurrency(item.quantity * item.unit_cost) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right">
                                            <button
                                                type="button"
                                                @click="removeItem(index)"
                                                class="text-red-400 hover:text-red-500"
                                            >
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Totals -->
                        <div class="px-6 py-4 bg-gray-50 dark:bg-dark-bg border-t border-gray-200 dark:border-dark-border">
                            <div class="flex justify-end space-y-2">
                                <div class="w-64 space-y-2">
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-600 dark:text-gray-400">Subtotal:</span>
                                        <span class="text-gray-900 dark:text-gray-100 font-medium">{{ formatCurrency(subtotal) }}</span>
                                    </div>
                                    <div class="flex justify-between items-center text-sm">
                                        <span class="text-gray-600 dark:text-gray-400">Tax:</span>
                                        <input
                                            type="number"
                                            v-model.number="form.tax"
                                            step="0.01"
                                            min="0"
                                            class="w-24 rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm"
                                        />
                                    </div>
                                    <div class="flex justify-between items-center text-sm">
                                        <span class="text-gray-600 dark:text-gray-400">Shipping:</span>
                                        <input
                                            type="number"
                                            v-model.number="form.shipping"
                                            step="0.01"
                                            min="0"
                                            class="w-24 rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm"
                                        />
                                    </div>
                                    <div class="flex justify-between text-base font-medium border-t border-gray-200 dark:border-dark-border pt-2">
                                        <span class="text-gray-900 dark:text-gray-100">Total:</span>
                                        <span class="text-gray-900 dark:text-gray-100">{{ formatCurrency(total) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center justify-end gap-4">
                        <Link
                            :href="route('purchase-orders.show', purchaseOrder.id)"
                            class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                        >
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing || form.items.length === 0"
                            class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 focus:bg-primary-500 active:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-400 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50"
                        >
                            Update Purchase Order
                        </button>
                    </div>
                </form>

                <PluginSlot slot="footer" :components="pluginComponents?.footer" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
