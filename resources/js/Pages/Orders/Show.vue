<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { usePermissions } from '@/composables/usePermissions';

const { hasPermission } = usePermissions();

const props = defineProps({
    order: Object,
});

const showDeleteModal = ref(false);
const deleting = ref(false);

const getStatusClass = (status) => {
    const classes = {
        pending: 'bg-yellow-900/30 text-yellow-400 border border-yellow-800',
        processing: 'bg-blue-900/30 text-blue-400 border border-blue-800',
        shipped: 'bg-purple-900/30 text-purple-400 border border-purple-800',
        delivered: 'bg-green-900/30 text-green-400 border border-green-800',
        cancelled: 'bg-red-900/30 text-red-400 border border-red-800',
    };
    return classes[status] || 'bg-gray-900/30 text-gray-400 border border-gray-800';
};

const deleteOrder = () => {
    deleting.value = true;
    router.delete(route('orders.destroy', props.order.id), {
        onFinish: () => {
            deleting.value = false;
            showDeleteModal.value = false;
        },
    });
};

const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

const formatDateShort = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
};
</script>

<template>
    <Head :title="`Order ${order.order_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                            Order #{{ order.order_number }}
                        </h2>
                        <span :class="getStatusClass(order.status)" class="px-3 py-1 rounded-full text-xs font-semibold uppercase">
                            {{ order.status }}
                        </span>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Created on {{ formatDateShort(order.order_date) }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <Link
                        v-if="hasPermission('edit_orders')"
                        :href="route('orders.edit', order.id)"
                        class="inline-flex items-center px-4 py-2 bg-primary-400 text-white rounded-md font-semibold text-xs uppercase tracking-widest hover:bg-primary-500 transition"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        Edit Order
                    </Link>
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
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Left Column: Order Items & Details -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Order Items -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                Order Items
                            </h3>

                            <div v-if="order.items && order.items.length > 0" class="space-y-3">
                                <div
                                    v-for="(item, index) in order.items"
                                    :key="index"
                                    class="flex items-center gap-4 p-4 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-lg"
                                >
                                    <div class="flex-1">
                                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ item.product_name }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">SKU: {{ item.sku }}</p>
                                        <Link
                                            v-if="item.product"
                                            :href="route('products.show', item.product_id)"
                                            class="text-xs text-primary-400 hover:text-primary-300 mt-1 inline-block"
                                        >
                                            View Product →
                                        </Link>
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

                            <div v-else class="text-center py-8 text-gray-500 dark:text-gray-400">
                                No items in this order.
                            </div>
                        </div>

                        <!-- Customer Information -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                Customer Information
                            </h3>

                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Customer Name</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ order.customer_name }}</dd>
                                </div>

                                <div v-if="order.customer_email">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Email</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                        <a :href="`mailto:${order.customer_email}`" class="text-primary-400 hover:text-primary-300">
                                            {{ order.customer_email }}
                                        </a>
                                    </dd>
                                </div>

                                <div v-if="order.customer_address">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Shipping Address</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 whitespace-pre-line">{{ order.customer_address }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Order Timeline -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                Order Timeline
                            </h3>

                            <div class="space-y-4">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-2 h-2 mt-2 rounded-full bg-green-400"></div>
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Order Created</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ formatDate(order.order_date) }}</p>
                                    </div>
                                </div>

                                <div v-if="order.shipped_at" class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-2 h-2 mt-2 rounded-full bg-purple-400"></div>
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Order Shipped</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ formatDate(order.shipped_at) }}</p>
                                    </div>
                                </div>

                                <div v-if="order.delivered_at" class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-2 h-2 mt-2 rounded-full bg-green-400"></div>
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Order Delivered</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ formatDate(order.delivered_at) }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div v-if="order.notes" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                Internal Notes
                            </h3>
                            <p class="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-line">{{ order.notes }}</p>
                        </div>
                    </div>

                    <!-- Right Column: Summary & Actions -->
                    <div class="space-y-6">
                        <!-- Order Summary -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                Order Summary
                            </h3>

                            <dl class="space-y-3">
                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-600 dark:text-gray-300">Subtotal</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">${{ parseFloat(order.subtotal).toFixed(2) }}</dd>
                                </div>

                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-600 dark:text-gray-300">Tax</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">${{ parseFloat(order.tax || 0).toFixed(2) }}</dd>
                                </div>

                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-600 dark:text-gray-300">Shipping</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">${{ parseFloat(order.shipping || 0).toFixed(2) }}</dd>
                                </div>

                                <div class="pt-3 border-t border-gray-200 dark:border-dark-border">
                                    <div class="flex justify-between items-center">
                                        <dt class="text-lg font-semibold text-gray-900 dark:text-gray-100">Total</dt>
                                        <dd class="text-xl font-bold text-primary-400">${{ parseFloat(order.total).toFixed(2) }}</dd>
                                    </div>
                                </div>
                            </dl>
                        </div>

                        <!-- Order Details -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                Order Details
                            </h3>

                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Order Number</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ order.order_number }}</dd>
                                </div>

                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Source</dt>
                                    <dd class="mt-1">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary-400/10 text-primary-400 capitalize">
                                            {{ order.source }}
                                        </span>
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Status</dt>
                                    <dd class="mt-1">
                                        <span :class="getStatusClass(order.status)" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize">
                                            {{ order.status }}
                                        </span>
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Order Date</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ formatDateShort(order.order_date) }}</dd>
                                </div>

                                <div v-if="order.currency">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Currency</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ order.currency }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Actions -->
                        <div v-if="hasPermission('delete_orders')" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                Danger Zone
                            </h3>

                            <button
                                @click="showDeleteModal = true"
                                class="w-full px-4 py-2 bg-red-900/30 text-red-400 border border-red-800 rounded-md font-semibold text-sm hover:bg-red-900/50 transition"
                            >
                                Delete Order
                            </button>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                Deleting this order will restore the inventory stock for all items.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div v-if="showDeleteModal" class="fixed inset-0 z-50 overflow-y-auto" @click="showDeleteModal = false">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>

                <div class="relative bg-white dark:bg-dark-card rounded-lg shadow-xl max-w-md w-full p-6" @click.stop>
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            Delete Order
                        </h3>
                        <button
                            @click="showDeleteModal = false"
                            class="text-gray-500 dark:text-gray-400 hover:text-gray-200"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="mb-6">
                        <p class="text-gray-600 dark:text-gray-300 mb-4">
                            Are you sure you want to delete order <strong>#{{ order.order_number }}</strong>?
                        </p>
                        <div class="bg-yellow-900/20 border border-yellow-800 rounded-lg p-4">
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 text-yellow-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <div class="text-sm text-yellow-300">
                                    <p class="font-semibold mb-1">This action cannot be undone</p>
                                    <p>The inventory stock for all items in this order will be restored.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-3 justify-end">
                        <button
                            type="button"
                            @click="showDeleteModal = false"
                            class="px-4 py-2 bg-gray-100 dark:bg-dark-bg text-gray-600 dark:text-gray-300 rounded-md hover:bg-gray-200 dark:hover:bg-dark-bg/50"
                            :disabled="deleting"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            @click="deleteOrder"
                            :disabled="deleting"
                            class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50"
                        >
                            <span v-if="deleting">Deleting...</span>
                            <span v-else>Delete Order</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
