<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    order: Object,
    products: Array,
});

const form = useForm({
    customer_name: props.order.customer_name,
    customer_email: props.order.customer_email,
    customer_address: props.order.customer_address,
    status: props.order.status,
    order_date: props.order.order_date ? props.order.order_date.split('T')[0] : '',
    shipping: props.order.shipping || 0,
    tax: props.order.tax || 0,
    notes: props.order.notes || '',
});

const submit = () => {
    form.put(route('orders.update', props.order.id), {
        preserveScroll: true,
    });
};

const getStatusClass = (status) => {
    const classes = {
        pending: 'bg-yellow-900/30 text-yellow-400 border-yellow-800',
        processing: 'bg-blue-900/30 text-blue-400 border-blue-800',
        shipped: 'bg-purple-900/30 text-purple-400 border-purple-800',
        delivered: 'bg-green-900/30 text-green-400 border-green-800',
        cancelled: 'bg-red-900/30 text-red-400 border-red-800',
    };
    return classes[status] || 'bg-gray-900/30 text-gray-400 border-gray-800';
};

const subtotal = computed(() => {
    return parseFloat(props.order.subtotal || 0);
});

const total = computed(() => {
    return subtotal.value + parseFloat(form.tax || 0) + parseFloat(form.shipping || 0);
});
</script>

<template>
    <Head title="Edit Order" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                        Edit Order
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Order #{{ order.order_number }}
                    </p>
                </div>
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
                        <!-- Left Column: Customer Info & Items -->
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

                            <!-- Order Items (Read-only) -->
                            <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                    Order Items
                                </h3>

                                <div class="bg-blue-900/10 border border-blue-800/30 rounded-lg p-4 mb-4">
                                    <div class="flex items-start gap-3">
                                        <svg class="w-5 h-5 text-blue-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <div class="text-sm text-blue-300">
                                            <p class="font-semibold mb-1">Order items cannot be modified</p>
                                            <p>Once an order is created, items are locked to maintain inventory accuracy. Create a new order or cancel this one if changes are needed.</p>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="order.items && order.items.length > 0" class="space-y-3">
                                    <div
                                        v-for="(item, index) in order.items"
                                        :key="index"
                                        class="flex items-center gap-4 p-4 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-lg"
                                    >
                                        <div class="flex-1">
                                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ item.product_name }}</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">SKU: {{ item.sku }}</p>
                                        </div>

                                        <div class="text-right">
                                            <p class="text-sm text-gray-500 dark:text-gray-400">Quantity</p>
                                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ item.quantity }}</p>
                                        </div>

                                        <div class="text-right">
                                            <p class="text-sm text-gray-500 dark:text-gray-400">Unit Price</p>
                                            <p class="font-medium text-gray-900 dark:text-gray-100">${{ parseFloat(item.unit_price).toFixed(2) }}</p>
                                        </div>

                                        <div class="text-right min-w-[100px]">
                                            <p class="text-sm text-gray-500 dark:text-gray-400">Total</p>
                                            <p class="font-semibold text-gray-900 dark:text-gray-100">
                                                ${{ parseFloat(item.total || item.subtotal || (item.quantity * item.unit_price)).toFixed(2) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
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

                                    <div v-if="order.shipped_at" class="text-sm">
                                        <p class="text-gray-500 dark:text-gray-400">Shipped at:</p>
                                        <p class="text-gray-900 dark:text-gray-100 font-medium">
                                            {{ new Date(order.shipped_at).toLocaleString() }}
                                        </p>
                                    </div>

                                    <div v-if="order.delivered_at" class="text-sm">
                                        <p class="text-gray-500 dark:text-gray-400">Delivered at:</p>
                                        <p class="text-gray-900 dark:text-gray-100 font-medium">
                                            {{ new Date(order.delivered_at).toLocaleString() }}
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
                                        :disabled="form.processing"
                                    >
                                        <span v-if="form.processing">Updating Order...</span>
                                        <span v-else>Update Order</span>
                                    </button>

                                    <Link
                                        :href="route('orders.show', order.id)"
                                        class="w-full px-4 py-3 bg-gray-100 dark:bg-dark-bg text-gray-700 dark:text-gray-300 rounded-lg font-semibold text-center hover:bg-gray-200 dark:hover:bg-dark-bg/80 border border-gray-200 dark:border-dark-border transition"
                                    >
                                        View Order Details
                                    </Link>

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
