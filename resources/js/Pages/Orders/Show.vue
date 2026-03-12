<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const { hasPermission } = usePermissions();

const props = defineProps({
    order: Object,
    canApprove: Boolean,
    pluginComponents: Object,
});

const showDeleteModal = ref(false);
const deleting = ref(false);

// Approval functionality
const showApprovalModal = ref(false);
const approvalAction = ref('approve');
const approvalNotes = ref('');
const processing = ref(false);

const openApprovalModal = (action) => {
    approvalAction.value = action;
    approvalNotes.value = '';
    showApprovalModal.value = true;
};

const submitApproval = () => {
    processing.value = true;
    const routeName = approvalAction.value === 'approve' ? 'orders.approve' : 'orders.reject';

    router.post(route(routeName, props.order.id), {
        notes: approvalNotes.value,
    }, {
        onFinish: () => {
            processing.value = false;
            showApprovalModal.value = false;
        },
    });
};

const getApprovalStatusClass = (status) => {
    const classes = {
        pending: 'bg-yellow-900/30 text-yellow-400 border border-yellow-800',
        approved: 'bg-green-900/30 text-green-400 border border-green-800',
        rejected: 'bg-red-900/30 text-red-400 border border-red-800',
    };
    return classes[status] || 'bg-gray-900/30 text-gray-400 border border-gray-800';
};

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
    <Head :title="t('orders.show.orderNumber', { number: order.order_number })" />

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
                        <span v-if="order.approval_status" :class="getApprovalStatusClass(order.approval_status)" class="px-3 py-1 rounded-full text-xs font-semibold uppercase">
                            {{ order.approval_status }}
                        </span>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Created on {{ formatDateShort(order.order_date) }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <Link
                        v-if="hasPermission('manage_returns')"
                        :href="route('returns.create', { order_id: order.id })"
                        class="inline-flex items-center px-4 py-2 bg-orange-500 text-white rounded-md font-semibold text-xs uppercase tracking-widest hover:bg-orange-600 transition"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a1 1 0 011 1v1a1 1 0 01-1 1H3m0 0l4-4m-4 4l4 4" />
                        </svg>
                        Create Return
                    </Link>
                    <Link
                        v-if="hasPermission('edit_orders')"
                        :href="route('orders.edit', order.id)"
                        class="inline-flex items-center px-4 py-2 bg-primary-400 text-white rounded-md font-semibold text-xs uppercase tracking-widest hover:bg-primary-500 transition"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        {{ t('orders.show.editOrder') }}
                    </Link>
                    <Link
                        :href="route('orders.index')"
                        class="inline-flex items-center px-4 py-2 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        {{ t('orders.show.backToOrders') }}
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Plugin Slot: Header -->
                <PluginSlot slot="header" :components="pluginComponents?.header" />

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Left Column: Order Items & Details -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Order Items -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                {{ t('orders.show.orderItems') }}
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
                                            {{ t('orders.show.viewProduct') }}
                                        </Link>
                                    </div>

                                    <div class="text-right">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('common.quantity') }}</p>
                                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ item.quantity }}</p>
                                    </div>

                                    <div class="text-right">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('orders.show.unitPrice') }}</p>
                                        <p class="font-medium text-gray-900 dark:text-gray-100">${{ parseFloat(item.unit_price).toFixed(2) }}</p>
                                    </div>

                                    <div class="text-right min-w-[100px]">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('common.total') }}</p>
                                        <p class="font-semibold text-gray-900 dark:text-gray-100">
                                            ${{ parseFloat(item.total || item.subtotal || (item.quantity * item.unit_price)).toFixed(2) }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div v-else class="text-center py-8 text-gray-500 dark:text-gray-400">
                                {{ t('orders.show.noItems') }}
                            </div>
                        </div>

                        <!-- Customer Information -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                {{ t('orders.show.customerInfo') }}
                            </h3>

                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('orders.show.customerName') }}</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ order.customer_name }}</dd>
                                </div>

                                <div v-if="order.customer_email">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('common.email') }}</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                        <a :href="`mailto:${order.customer_email}`" class="text-primary-400 hover:text-primary-300">
                                            {{ order.customer_email }}
                                        </a>
                                    </dd>
                                </div>

                                <div v-if="order.customer_address">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('orders.show.shippingAddress') }}</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 whitespace-pre-line">{{ order.customer_address }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Order Timeline -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                {{ t('orders.show.orderTimeline') }}
                            </h3>

                            <div class="space-y-4">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-2 h-2 mt-2 rounded-full bg-green-400"></div>
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ t('orders.show.orderCreated') }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ formatDate(order.order_date) }}</p>
                                    </div>
                                </div>

                                <div v-if="order.shipped_at" class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-2 h-2 mt-2 rounded-full bg-purple-400"></div>
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ t('orders.show.orderShipped') }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ formatDate(order.shipped_at) }}</p>
                                    </div>
                                </div>

                                <div v-if="order.delivered_at" class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-2 h-2 mt-2 rounded-full bg-green-400"></div>
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ t('orders.show.orderDelivered') }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ formatDate(order.delivered_at) }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div v-if="order.notes" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                {{ t('orders.show.internalNotes') }}
                            </h3>
                            <p class="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-line">{{ order.notes }}</p>
                        </div>
                    </div>

                    <!-- Right Column: Summary & Actions -->
                    <div class="space-y-6">
                        <!-- Plugin Slot: Sidebar -->
                        <PluginSlot slot="sidebar" :components="pluginComponents?.sidebar" />

                        <!-- Order Summary -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                {{ t('orders.show.orderSummary') }}
                            </h3>

                            <dl class="space-y-3">
                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-600 dark:text-gray-300">{{ t('common.subtotal') }}</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">${{ parseFloat(order.subtotal).toFixed(2) }}</dd>
                                </div>

                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-600 dark:text-gray-300">{{ t('common.tax') }}</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">${{ parseFloat(order.tax || 0).toFixed(2) }}</dd>
                                </div>

                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-600 dark:text-gray-300">{{ t('common.shipping') }}</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">${{ parseFloat(order.shipping || 0).toFixed(2) }}</dd>
                                </div>

                                <div class="pt-3 border-t border-gray-200 dark:border-dark-border">
                                    <div class="flex justify-between items-center">
                                        <dt class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ t('common.total') }}</dt>
                                        <dd class="text-xl font-bold text-primary-400">${{ parseFloat(order.total).toFixed(2) }}</dd>
                                    </div>
                                </div>
                            </dl>
                        </div>

                        <!-- Order Details -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                {{ t('orders.show.orderDetails') }}
                            </h3>

                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('orders.show.orderNumber2') }}</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ order.order_number }}</dd>
                                </div>

                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('orders.source') }}</dt>
                                    <dd class="mt-1">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary-400/10 text-primary-400 capitalize">
                                            {{ order.source }}
                                        </span>
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('common.status') }}</dt>
                                    <dd class="mt-1">
                                        <span :class="getStatusClass(order.status)" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize">
                                            {{ order.status }}
                                        </span>
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('purchaseOrders.orderDate') }}</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ formatDateShort(order.order_date) }}</dd>
                                </div>

                                <div v-if="order.currency">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('common.currency') }}</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ order.currency }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Approval Status -->
                        <div v-if="order.approval_status" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                {{ t('orders.show.approvalStatus') }}
                            </h3>

                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('common.status') }}</dt>
                                    <dd class="mt-1">
                                        <span :class="getApprovalStatusClass(order.approval_status)" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize">
                                            {{ order.approval_status }}
                                        </span>
                                    </dd>
                                </div>

                                <div v-if="order.creator">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('orders.show.createdBy') }}</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ order.creator.name }}</dd>
                                </div>

                                <div v-if="order.approver">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ order.approval_status === 'approved' ? t('orders.show.approved') : t('orders.show.rejected') }} {{ t('orders.show.by') }}</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ order.approver.name }}</dd>
                                </div>

                                <div v-if="order.approved_at">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('orders.show.decisionDate') }}</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ formatDate(order.approved_at) }}</dd>
                                </div>

                                <div v-if="order.approval_notes">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('common.notes') }}</dt>
                                    <dd class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ order.approval_notes }}</dd>
                                </div>
                            </dl>

                            <!-- Approval Actions -->
                            <div v-if="canApprove && order.approval_status === 'pending'" class="mt-4 pt-4 border-t border-gray-200 dark:border-dark-border space-y-2">
                                <button
                                    @click="openApprovalModal('approve')"
                                    class="w-full px-4 py-2 bg-green-600 text-white rounded-md font-semibold text-sm hover:bg-green-700 transition"
                                >
                                    {{ t('orders.show.approveOrder') }}
                                </button>
                                <button
                                    @click="openApprovalModal('reject')"
                                    class="w-full px-4 py-2 bg-red-600 text-white rounded-md font-semibold text-sm hover:bg-red-700 transition"
                                >
                                    {{ t('orders.show.rejectOrder') }}
                                </button>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div v-if="hasPermission('delete_orders')" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                {{ t('orders.show.dangerZone') }}
                            </h3>

                            <button
                                @click="showDeleteModal = true"
                                class="w-full px-4 py-2 bg-red-900/30 text-red-400 border border-red-800 rounded-md font-semibold text-sm hover:bg-red-900/50 transition"
                            >
                                {{ t('orders.show.deleteOrder') }}
                            </button>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ t('orders.show.deleteWarning') }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Plugin Slot: Footer -->
                <PluginSlot slot="footer" :components="pluginComponents?.footer" />
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div v-if="showDeleteModal" class="fixed inset-0 z-50 overflow-y-auto" @click="showDeleteModal = false">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>

                <div class="relative bg-white dark:bg-dark-card rounded-lg shadow-xl max-w-md w-full p-6" @click.stop>
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            {{ t('orders.show.deleteOrder') }}
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
                                    <p class="font-semibold mb-1">{{ t('orders.show.cannotUndo') }}</p>
                                    <p>{{ t('orders.show.stockRestored') }}</p>
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
                            {{ t('common.cancel') }}
                        </button>
                        <button
                            type="button"
                            @click="deleteOrder"
                            :disabled="deleting"
                            class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50"
                        >
                            <span v-if="deleting">{{ t('common.deleting') }}</span>
                            <span v-else>{{ t('orders.show.deleteOrder') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approval Modal -->
        <div v-if="showApprovalModal" class="fixed inset-0 z-50 overflow-y-auto" @click="showApprovalModal = false">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>

                <div class="relative bg-white dark:bg-dark-card rounded-lg shadow-xl max-w-md w-full p-6" @click.stop>
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            {{ approvalAction === 'approve' ? t('orders.show.approveOrder') : t('orders.show.rejectOrder') }}
                        </h3>
                        <button
                            @click="showApprovalModal = false"
                            class="text-gray-500 dark:text-gray-400 hover:text-gray-200"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="mb-6">
                        <p class="text-gray-600 dark:text-gray-300 mb-4">
                            {{ approvalAction === 'approve'
                                ? t('orders.show.confirmApprove', { number: order.order_number })
                                : t('orders.show.confirmReject', { number: order.order_number })
                            }}
                        </p>

                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                                {{ t('common.notes') }} {{ approvalAction === 'reject' ? t('orders.show.notesRequired') : t('orders.show.notesOptional') }}
                            </label>
                            <textarea
                                v-model="approvalNotes"
                                rows="3"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                :placeholder="approvalAction === 'approve' ? t('orders.show.approvalNotesPlaceholder') : t('orders.show.rejectionNotesPlaceholder')"
                                :required="approvalAction === 'reject'"
                            ></textarea>
                        </div>
                    </div>

                    <div class="flex gap-3 justify-end">
                        <button
                            type="button"
                            @click="showApprovalModal = false"
                            class="px-4 py-2 bg-gray-100 dark:bg-dark-bg text-gray-600 dark:text-gray-300 rounded-md hover:bg-gray-200 dark:hover:bg-dark-bg/50"
                            :disabled="processing"
                        >
                            {{ t('common.cancel') }}
                        </button>
                        <button
                            type="button"
                            @click="submitApproval"
                            :disabled="processing || (approvalAction === 'reject' && !approvalNotes)"
                            :class="[
                                'px-4 py-2 text-white rounded-md disabled:opacity-50',
                                approvalAction === 'approve'
                                    ? 'bg-green-600 hover:bg-green-700'
                                    : 'bg-red-600 hover:bg-red-700'
                            ]"
                        >
                            <span v-if="processing">{{ t('common.loading') }}</span>
                            <span v-else>{{ approvalAction === 'approve' ? t('orders.show.approve') : t('orders.show.reject') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
