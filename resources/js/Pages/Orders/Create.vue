<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    products: Array,
});

const form = useForm({
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

// Product selection
const selectedProduct = ref(null);
const quantity = ref(1);

const addItem = () => {
    if (!selectedProduct.value || quantity.value < 1) return;

    const product = props.products.find(p => p.id === selectedProduct.value);
    if (!product) return;

    // Check if product already exists in items
    const existingIndex = form.items.findIndex(item => item.product_id === product.id);

    if (existingIndex >= 0) {
        // Update quantity
        form.items[existingIndex].quantity += quantity.value;
    } else {
        // Add new item
        form.items.push({
            product_id: product.id,
            product_name: product.name,
            sku: product.sku,
            quantity: quantity.value,
            unit_price: parseFloat(product.price),
        });
    }

    // Reset selection
    selectedProduct.value = null;
    quantity.value = 1;
};

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

// Calculations
const subtotal = computed(() => {
    return form.items.reduce((sum, item) => {
        return sum + (item.quantity * item.unit_price);
    }, 0);
});

const total = computed(() => {
    return subtotal.value + parseFloat(form.tax || 0) + parseFloat(form.shipping || 0);
});

const submit = () => {
    if (form.items.length === 0) {
        alert('Please add at least one product to the order.');
        return;
    }

    form.post(route('orders.store'), {
        preserveScroll: true,
    });
};

const availableProducts = computed(() => {
    return props.products.filter(p => p.stock > 0);
});
</script>

<template>
    <Head title="Create Order" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    Create Order
                </h2>
                <Link
                    :href="route('orders.index')"
                    class="inline-flex items-center px-4 py-2 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Orders
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <form @submit.prevent="submit">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Left Column: Customer & Order Info -->
                        <div class="lg:col-span-2 space-y-6">
                            <!-- Customer Information -->
                            <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                    Customer Information
                                </h3>

                                <div class="space-y-4">
                                    <div>
                                        <label for="customer_name" class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                            Customer Name <span class="text-red-500">*</span>
                                        </label>
                                        <input
                                            id="customer_name"
                                            v-model="form.customer_name"
                                            type="text"
                                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                            required
                                        />
                                        <p v-if="form.errors.customer_name" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.customer_name }}
                                        </p>
                                    </div>

                                    <div>
                                        <label for="customer_email" class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                            Customer Email
                                        </label>
                                        <input
                                            id="customer_email"
                                            v-model="form.customer_email"
                                            type="email"
                                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                        />
                                        <p v-if="form.errors.customer_email" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.customer_email }}
                                        </p>
                                    </div>

                                    <div>
                                        <label for="customer_address" class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                            Shipping Address
                                        </label>
                                        <textarea
                                            id="customer_address"
                                            v-model="form.customer_address"
                                            rows="3"
                                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                        ></textarea>
                                        <p v-if="form.errors.customer_address" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.customer_address }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Order Items -->
                            <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                    Order Items
                                </h3>

                                <!-- Add Item Form -->
                                <div class="mb-6 p-4 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-lg">
                                    <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
                                        <div class="md:col-span-7">
                                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                                                Select Product
                                            </label>
                                            <select
                                                v-model="selectedProduct"
                                                class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                            >
                                                <option :value="null">Choose a product...</option>
                                                <option v-for="product in availableProducts" :key="product.id" :value="product.id">
                                                    {{ product.name }} ({{ product.sku }}) - Stock: {{ product.stock }} - ${{ product.price }}
                                                </option>
                                            </select>
                                        </div>

                                        <div class="md:col-span-3">
                                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                                                Quantity
                                            </label>
                                            <input
                                                v-model.number="quantity"
                                                type="number"
                                                min="1"
                                                class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                            />
                                        </div>

                                        <div class="md:col-span-2 flex items-end">
                                            <button
                                                type="button"
                                                @click="addItem"
                                                class="w-full px-4 py-2 bg-primary-400 text-white rounded-md hover:bg-primary-500 transition"
                                            >
                                                Add
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Items List -->
                                <div v-if="form.items.length > 0" class="space-y-3">
                                    <div
                                        v-for="(item, index) in form.items"
                                        :key="index"
                                        class="flex items-center gap-4 p-4 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-lg"
                                    >
                                        <div class="flex-1">
                                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ item.product_name }}</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">SKU: {{ item.sku }}</p>
                                        </div>

                                        <div class="w-24">
                                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Qty</label>
                                            <input
                                                :value="item.quantity"
                                                @input="updateItemQuantity(index, parseInt($event.target.value))"
                                                type="number"
                                                min="1"
                                                class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm"
                                            />
                                        </div>

                                        <div class="w-32">
                                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Unit Price</label>
                                            <input
                                                :value="item.unit_price"
                                                @input="updateItemPrice(index, $event.target.value)"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm"
                                            />
                                        </div>

                                        <div class="w-32 text-right">
                                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Total</label>
                                            <p class="font-semibold text-gray-900 dark:text-gray-100">
                                                ${{ (item.quantity * item.unit_price).toFixed(2) }}
                                            </p>
                                        </div>

                                        <button
                                            type="button"
                                            @click="removeItem(index)"
                                            class="p-2 text-red-400 hover:text-red-300 hover:bg-red-900/20 rounded-md transition"
                                        >
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <div v-else class="text-center py-8 text-gray-500 dark:text-gray-400">
                                    <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                    </svg>
                                    <p>No items added yet. Select a product above to add it to the order.</p>
                                </div>

                                <p v-if="form.errors.items" class="mt-2 text-sm text-red-400">
                                    {{ form.errors.items }}
                                </p>
                            </div>
                        </div>

                        <!-- Right Column: Order Details & Summary -->
                        <div class="space-y-6">
                            <!-- Order Details -->
                            <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                    Order Details
                                </h3>

                                <div class="space-y-4">
                                    <div>
                                        <label for="order_date" class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                            Order Date <span class="text-red-500">*</span>
                                        </label>
                                        <input
                                            id="order_date"
                                            v-model="form.order_date"
                                            type="date"
                                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                            required
                                        />
                                        <p v-if="form.errors.order_date" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.order_date }}
                                        </p>
                                    </div>

                                    <div>
                                        <label for="status" class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                            Status <span class="text-red-500">*</span>
                                        </label>
                                        <select
                                            id="status"
                                            v-model="form.status"
                                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                            required
                                        >
                                            <option value="pending">Pending</option>
                                            <option value="processing">Processing</option>
                                            <option value="shipped">Shipped</option>
                                            <option value="delivered">Delivered</option>
                                            <option value="cancelled">Cancelled</option>
                                        </select>
                                        <p v-if="form.errors.status" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.status }}
                                        </p>
                                    </div>

                                    <div>
                                        <label for="notes" class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                            Notes
                                        </label>
                                        <textarea
                                            id="notes"
                                            v-model="form.notes"
                                            rows="3"
                                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                            placeholder="Internal notes..."
                                        ></textarea>
                                        <p v-if="form.errors.notes" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.notes }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Order Summary -->
                            <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                    Order Summary
                                </h3>

                                <div class="space-y-3">
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-600 dark:text-gray-300">Subtotal</span>
                                        <span class="font-medium text-gray-900 dark:text-gray-100">${{ subtotal.toFixed(2) }}</span>
                                    </div>

                                    <div>
                                        <div class="flex justify-between items-center text-sm mb-1">
                                            <label for="tax" class="text-gray-600 dark:text-gray-300">Tax</label>
                                        </div>
                                        <input
                                            id="tax"
                                            v-model.number="form.tax"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm"
                                        />
                                        <p v-if="form.errors.tax" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.tax }}
                                        </p>
                                    </div>

                                    <div>
                                        <div class="flex justify-between items-center text-sm mb-1">
                                            <label for="shipping" class="text-gray-600 dark:text-gray-300">Shipping</label>
                                        </div>
                                        <input
                                            id="shipping"
                                            v-model.number="form.shipping"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm"
                                        />
                                        <p v-if="form.errors.shipping" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.shipping }}
                                        </p>
                                    </div>

                                    <div class="pt-3 border-t border-gray-200 dark:border-dark-border">
                                        <div class="flex justify-between items-center">
                                            <span class="text-lg font-semibold text-gray-900 dark:text-gray-100">Total</span>
                                            <span class="text-xl font-bold text-primary-400">${{ total.toFixed(2) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                                <div class="flex flex-col gap-3">
                                    <button
                                        type="submit"
                                        class="w-full px-4 py-3 bg-primary-400 text-white rounded-lg font-semibold hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-400 focus:ring-offset-2 focus:ring-offset-dark-bg transition"
                                        :class="{ 'opacity-25': form.processing }"
                                        :disabled="form.processing || form.items.length === 0"
                                    >
                                        <span v-if="form.processing">Creating Order...</span>
                                        <span v-else>Create Order</span>
                                    </button>

                                    <Link
                                        :href="route('orders.index')"
                                        class="w-full px-4 py-3 bg-gray-100 dark:bg-dark-bg text-gray-700 dark:text-gray-300 rounded-lg font-semibold text-center hover:bg-gray-200 dark:hover:bg-dark-bg/80 border border-gray-200 dark:border-dark-border transition"
                                    >
                                        Cancel
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
