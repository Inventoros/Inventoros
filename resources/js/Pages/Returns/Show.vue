<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const { hasPermission } = usePermissions();

const props = defineProps({
    returnOrder: Object,
});

const processing = ref(false);
const showRejectModal = ref(false);
const rejectNotes = ref('');

const getStatusClass = (status) => {
    const classes = {
        pending: 'bg-yellow-900/30 text-yellow-400 border border-yellow-800',
        approved: 'bg-blue-900/30 text-blue-400 border border-blue-800',
        received: 'bg-purple-900/30 text-purple-400 border border-purple-800',
        completed: 'bg-green-900/30 text-green-400 border border-green-800',
        rejected: 'bg-red-900/30 text-red-400 border border-red-800',
    };
    return classes[status] || 'bg-gray-900/30 text-gray-400 border border-gray-800';
};

const getTypeClass = (type) => {
    return type === 'exchange'
        ? 'bg-indigo-900/30 text-indigo-400 border border-indigo-800'
        : 'bg-orange-900/30 text-orange-400 border border-orange-800';
};

const getConditionLabel = (condition) => {
    const labels = { new: 'New (Unopened)', used: 'Used (Opened)', damaged: 'Damaged' };
    return labels[condition] || condition;
};

const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const performAction = (action) => {
    processing.value = true;
    router.post(route(`returns.${action}`, props.returnOrder.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};

const submitReject = () => {
    processing.value = true;
    router.post(route('returns.reject', props.returnOrder.id), {
        notes: rejectNotes.value,
    }, {
        onFinish: () => {
            processing.value = false;
            showRejectModal.value = false;
        },
    });
};
</script>

<template>
    <Head :title="`Return ${returnOrder.return_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                            Return #{{ returnOrder.return_number }}
                        </h2>
                        <span :class="getTypeClass(returnOrder.type)" class="px-3 py-1 rounded-full text-xs font-semibold uppercase">
                            {{ returnOrder.type }}
                        </span>
                        <span :class="getStatusClass(returnOrder.status)" class="px-3 py-1 rounded-full text-xs font-semibold uppercase">
                            {{ returnOrder.status }}
                        </span>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        For Order
                        <Link v-if="returnOrder.order" :href="route('orders.show', returnOrder.order_id)" class="text-primary-400 hover:text-primary-300">
                            #{{ returnOrder.order.order_number }}
                        </Link>
                    </p>
                </div>
                <Link
                    :href="route('returns.index')"
                    class="inline-flex items-center px-4 py-2 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Returns
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Left Column: Items & Details -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Return Items -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Return Items</h3>

                            <div class="space-y-3">
                                <div
                                    v-for="item in returnOrder.items"
                                    :key="item.id"
                                    class="flex items-center gap-4 p-4 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-lg"
                                >
                                    <div class="flex-1">
                                        <p class="font-medium text-gray-900 dark:text-gray-100">
                                            {{ item.product?.name || item.order_item?.product_name || 'Unknown Product' }}
                                        </p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            SKU: {{ item.product?.sku || item.order_item?.sku || '-' }}
                                        </p>
                                    </div>

                                    <div class="text-center">
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Qty</p>
                                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ item.quantity }}</p>
                                    </div>

                                    <div class="text-center">
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Condition</p>
                                        <span class="text-sm font-medium" :class="{
                                            'text-green-400': item.condition === 'new',
                                            'text-yellow-400': item.condition === 'used',
                                            'text-red-400': item.condition === 'damaged',
                                        }">
                                            {{ getConditionLabel(item.condition) }}
                                        </span>
                                    </div>

                                    <div class="text-center">
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Restock</p>
                                        <span v-if="item.restock" class="text-green-400 text-sm font-medium">Yes</span>
                                        <span v-else class="text-gray-400 text-sm">No</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Reason & Notes -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Details</h3>

                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Reason</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ returnOrder.reason }}</dd>
                                </div>
                                <div v-if="returnOrder.notes">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Notes</dt>
                                    <dd class="mt-1 text-sm text-gray-600 dark:text-gray-300 whitespace-pre-line">{{ returnOrder.notes }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Right Column: Summary & Actions -->
                    <div class="space-y-6">
                        <!-- Summary -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Summary</h3>

                            <dl class="space-y-3">
                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-600 dark:text-gray-300">Return Number</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ returnOrder.return_number }}</dd>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-600 dark:text-gray-300">Type</dt>
                                    <dd>
                                        <span :class="getTypeClass(returnOrder.type)" class="px-2 py-0.5 rounded-full text-xs font-semibold uppercase">
                                            {{ returnOrder.type }}
                                        </span>
                                    </dd>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-600 dark:text-gray-300">Status</dt>
                                    <dd>
                                        <span :class="getStatusClass(returnOrder.status)" class="px-2 py-0.5 rounded-full text-xs font-semibold uppercase">
                                            {{ returnOrder.status }}
                                        </span>
                                    </dd>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-600 dark:text-gray-300">Created</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ formatDate(returnOrder.created_at) }}</dd>
                                </div>
                                <div v-if="returnOrder.completed_at" class="flex justify-between text-sm">
                                    <dt class="text-gray-600 dark:text-gray-300">Completed</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ formatDate(returnOrder.completed_at) }}</dd>
                                </div>
                                <div v-if="returnOrder.processor" class="flex justify-between text-sm">
                                    <dt class="text-gray-600 dark:text-gray-300">Processed By</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ returnOrder.processor.name }}</dd>
                                </div>
                                <div class="pt-3 border-t border-gray-200 dark:border-dark-border">
                                    <div class="flex justify-between items-center">
                                        <dt class="text-lg font-semibold text-gray-900 dark:text-gray-100">Refund Amount</dt>
                                        <dd class="text-xl font-bold text-primary-400">${{ parseFloat(returnOrder.refund_amount || 0).toFixed(2) }}</dd>
                                    </div>
                                </div>
                            </dl>
                        </div>

                        <!-- Actions -->
                        <div v-if="returnOrder.status !== 'completed' && returnOrder.status !== 'rejected'" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Actions</h3>

                            <div class="space-y-2">
                                <!-- Approve (when pending) -->
                                <button
                                    v-if="returnOrder.status === 'pending'"
                                    @click="performAction('approve')"
                                    :disabled="processing"
                                    class="w-full px-4 py-2 bg-blue-600 text-white rounded-md font-semibold text-sm hover:bg-blue-700 disabled:opacity-50 transition"
                                >
                                    Approve Return
                                </button>

                                <!-- Receive (when approved) -->
                                <button
                                    v-if="returnOrder.status === 'approved'"
                                    @click="performAction('receive')"
                                    :disabled="processing"
                                    class="w-full px-4 py-2 bg-purple-600 text-white rounded-md font-semibold text-sm hover:bg-purple-700 disabled:opacity-50 transition"
                                >
                                    Mark as Received
                                </button>

                                <!-- Complete (when received) -->
                                <button
                                    v-if="returnOrder.status === 'received'"
                                    @click="performAction('complete')"
                                    :disabled="processing"
                                    class="w-full px-4 py-2 bg-green-600 text-white rounded-md font-semibold text-sm hover:bg-green-700 disabled:opacity-50 transition"
                                >
                                    Complete Return
                                </button>

                                <!-- Reject (when pending) -->
                                <button
                                    v-if="returnOrder.status === 'pending'"
                                    @click="showRejectModal = true"
                                    :disabled="processing"
                                    class="w-full px-4 py-2 bg-red-900/30 text-red-400 border border-red-800 rounded-md font-semibold text-sm hover:bg-red-900/50 disabled:opacity-50 transition"
                                >
                                    Reject Return
                                </button>
                            </div>

                            <!-- Status flow hint -->
                            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                                <template v-if="returnOrder.status === 'pending'">
                                    Approve to proceed or reject the return.
                                </template>
                                <template v-else-if="returnOrder.status === 'approved'">
                                    Mark as received when items arrive. Items marked for restock will be added back to inventory.
                                </template>
                                <template v-else-if="returnOrder.status === 'received'">
                                    Complete the return to finalize the process.
                                </template>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reject Modal -->
        <div v-if="showRejectModal" class="fixed inset-0 z-50 overflow-y-auto" @click="showRejectModal = false">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>

                <div class="relative bg-white dark:bg-dark-card rounded-lg shadow-xl max-w-md w-full p-6" @click.stop>
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Reject Return</h3>
                        <button @click="showRejectModal = false" class="text-gray-500 dark:text-gray-400 hover:text-gray-200">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Reason for rejection</label>
                        <textarea
                            v-model="rejectNotes"
                            rows="3"
                            class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            placeholder="Why is this return being rejected?"
                        ></textarea>
                    </div>

                    <div class="flex gap-3 justify-end">
                        <button
                            @click="showRejectModal = false"
                            class="px-4 py-2 bg-gray-100 dark:bg-dark-bg text-gray-600 dark:text-gray-300 rounded-md hover:bg-gray-200 dark:hover:bg-dark-bg/50"
                            :disabled="processing"
                        >
                            Cancel
                        </button>
                        <button
                            @click="submitReject"
                            :disabled="processing"
                            class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50"
                        >
                            <span v-if="processing">Rejecting...</span>
                            <span v-else>Reject Return</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
