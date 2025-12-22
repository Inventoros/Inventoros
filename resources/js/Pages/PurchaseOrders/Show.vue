<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    purchaseOrder: Object,
    pluginComponents: Object,
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: props.purchaseOrder.currency || 'USD',
    }).format(value || 0);
};

const formatDate = (dateString) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};

const statusColors = {
    draft: 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400',
    sent: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
    partial: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
    received: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
};

const statusLabels = {
    draft: 'Draft',
    sent: 'Sent',
    partial: 'Partial',
    received: 'Received',
    cancelled: 'Cancelled',
};

const sendToSupplier = () => {
    if (confirm('Mark this purchase order as sent to supplier?')) {
        router.post(route('purchase-orders.send', props.purchaseOrder.id));
    }
};

const cancelPO = () => {
    if (confirm('Are you sure you want to cancel this purchase order?')) {
        router.post(route('purchase-orders.cancel', props.purchaseOrder.id));
    }
};

const deletePO = () => {
    if (confirm(`Are you sure you want to delete "${props.purchaseOrder.po_number}"?`)) {
        router.delete(route('purchase-orders.destroy', props.purchaseOrder.id));
    }
};
</script>

<template>
    <Head :title="purchaseOrder.po_number" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    {{ purchaseOrder.po_number }}
                </h2>
                <div class="flex items-center gap-2">
                    <Link
                        v-if="purchaseOrder.status === 'draft'"
                        :href="route('purchase-orders.edit', purchaseOrder.id)"
                        class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500"
                    >
                        Edit
                    </Link>
                    <Link
                        v-if="purchaseOrder.status === 'sent' || purchaseOrder.status === 'partial'"
                        :href="route('purchase-orders.receive', purchaseOrder.id)"
                        class="inline-flex items-center px-4 py-2 bg-green-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-600"
                    >
                        Receive Items
                    </Link>
                    <Link
                        :href="route('purchase-orders.index')"
                        class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                    >
                        Back
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Plugin Slot: Header -->
                <PluginSlot slot="header" :components="pluginComponents?.header" />

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Main Content -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Order Details -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Order Details</h3>
                            </div>
                            <div class="p-6">
                                <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Supplier</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                            <Link v-if="purchaseOrder.supplier" :href="route('suppliers.show', purchaseOrder.supplier.id)" class="text-primary-400 hover:underline">
                                                {{ purchaseOrder.supplier.name }}
                                            </Link>
                                            <span v-else>-</span>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Created By</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ purchaseOrder.creator?.name || '-' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Order Date</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ formatDate(purchaseOrder.order_date) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Expected Delivery</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ formatDate(purchaseOrder.expected_date) }}</dd>
                                    </div>
                                    <div v-if="purchaseOrder.received_date">
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Received Date</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ formatDate(purchaseOrder.received_date) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Currency</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ purchaseOrder.currency }}</dd>
                                    </div>
                                </dl>
                                <div v-if="purchaseOrder.notes" class="mt-6">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Notes</dt>
                                    <dd class="mt-1 text-sm text-gray-600 dark:text-gray-300 whitespace-pre-wrap">{{ purchaseOrder.notes }}</dd>
                                </div>
                            </div>
                        </div>

                        <!-- Items -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Items ({{ purchaseOrder.items?.length || 0 }})</h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                                    <thead class="bg-gray-50 dark:bg-dark-bg">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Product</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">SKU</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Ordered</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Received</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Unit Cost</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-dark-card divide-y divide-gray-200 dark:divide-dark-border">
                                        <tr v-for="item in purchaseOrder.items" :key="item.id">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                                <Link v-if="item.product" :href="route('products.show', item.product.id)" class="text-primary-400 hover:underline">
                                                    {{ item.product_name }}
                                                </Link>
                                                <span v-else>{{ item.product_name }}</span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ item.sku || '-' }}
                                                <span v-if="item.supplier_sku" class="block text-xs text-gray-400 dark:text-gray-500">
                                                    Supplier: {{ item.supplier_sku }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                                {{ item.quantity_ordered }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span :class="[
                                                    item.quantity_received >= item.quantity_ordered
                                                        ? 'text-green-600 dark:text-green-400'
                                                        : item.quantity_received > 0
                                                            ? 'text-yellow-600 dark:text-yellow-400'
                                                            : 'text-gray-500 dark:text-gray-400'
                                                ]">
                                                    {{ item.quantity_received }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ formatCurrency(item.unit_cost) }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100 font-medium">
                                                {{ formatCurrency(item.total) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                    <tfoot class="bg-gray-50 dark:bg-dark-bg">
                                        <tr>
                                            <td colspan="5" class="px-6 py-3 text-right text-sm font-medium text-gray-500 dark:text-gray-400">Subtotal:</td>
                                            <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100 font-medium">{{ formatCurrency(purchaseOrder.subtotal) }}</td>
                                        </tr>
                                        <tr v-if="purchaseOrder.tax > 0">
                                            <td colspan="5" class="px-6 py-3 text-right text-sm font-medium text-gray-500 dark:text-gray-400">Tax:</td>
                                            <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ formatCurrency(purchaseOrder.tax) }}</td>
                                        </tr>
                                        <tr v-if="purchaseOrder.shipping > 0">
                                            <td colspan="5" class="px-6 py-3 text-right text-sm font-medium text-gray-500 dark:text-gray-400">Shipping:</td>
                                            <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ formatCurrency(purchaseOrder.shipping) }}</td>
                                        </tr>
                                        <tr>
                                            <td colspan="5" class="px-6 py-3 text-right text-sm font-bold text-gray-900 dark:text-gray-100">Total:</td>
                                            <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100 font-bold">{{ formatCurrency(purchaseOrder.total) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6">
                        <!-- Status Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Status</h3>
                            </div>
                            <div class="p-6">
                                <span
                                    :class="[
                                        'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium',
                                        statusColors[purchaseOrder.status]
                                    ]"
                                >
                                    {{ statusLabels[purchaseOrder.status] || purchaseOrder.status }}
                                </span>
                            </div>
                        </div>

                        <!-- Plugin Slot: Sidebar -->
                        <PluginSlot slot="sidebar" :components="pluginComponents?.sidebar" />

                        <!-- Actions Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Actions</h3>
                            </div>
                            <div class="p-6 space-y-3">
                                <button
                                    v-if="purchaseOrder.status === 'draft'"
                                    @click="sendToSupplier"
                                    class="w-full inline-flex justify-center items-center px-4 py-2 bg-blue-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-600"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                    Send to Supplier
                                </button>
                                <Link
                                    v-if="purchaseOrder.status === 'sent' || purchaseOrder.status === 'partial'"
                                    :href="route('purchase-orders.receive', purchaseOrder.id)"
                                    class="w-full inline-flex justify-center items-center px-4 py-2 bg-green-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-600"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                    </svg>
                                    Receive Items
                                </Link>
                                <Link
                                    v-if="purchaseOrder.status === 'draft'"
                                    :href="route('purchase-orders.edit', purchaseOrder.id)"
                                    class="w-full inline-flex justify-center items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500"
                                >
                                    Edit Purchase Order
                                </Link>
                                <button
                                    v-if="purchaseOrder.status === 'draft' || purchaseOrder.status === 'sent'"
                                    @click="cancelPO"
                                    class="w-full inline-flex justify-center items-center px-4 py-2 bg-yellow-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-yellow-600"
                                >
                                    Cancel Order
                                </button>
                                <button
                                    v-if="purchaseOrder.status === 'draft'"
                                    @click="deletePO"
                                    class="w-full inline-flex justify-center items-center px-4 py-2 bg-red-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-600"
                                >
                                    Delete Order
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Plugin Slot: Footer -->
                <PluginSlot slot="footer" :components="pluginComponents?.footer" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
