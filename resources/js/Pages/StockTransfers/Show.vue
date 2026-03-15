<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    transfer: Object,
});

const processing = ref(false);

const getStatusBadgeClass = (status) => {
    const classes = {
        'pending': 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
        'in_transit': 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        'completed': 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
        'cancelled': 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
    };
    return classes[status] || classes.pending;
};

const getStatusLabel = (status) => {
    const labels = {
        'pending': 'Pending',
        'in_transit': 'In Transit',
        'completed': 'Completed',
        'cancelled': 'Cancelled',
    };
    return labels[status] || status;
};

const formatDate = (dateStr) => {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const canComplete = ['pending', 'in_transit'].includes(props.transfer.status);
const canCancel = ['pending', 'in_transit'].includes(props.transfer.status);

const completeTransfer = () => {
    if (!confirm('Are you sure you want to complete this transfer? Stock levels will be adjusted.')) return;
    processing.value = true;
    router.post(route('stock-transfers.complete', props.transfer.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};

const cancelTransfer = () => {
    if (!confirm('Are you sure you want to cancel this transfer?')) return;
    processing.value = true;
    router.post(route('stock-transfers.cancel', props.transfer.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};
</script>

<template>
    <Head :title="`Transfer ${transfer.transfer_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">{{ transfer.transfer_number }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Stock transfer details</p>
                </div>
                <div class="flex gap-2">
                    <Link
                        :href="route('stock-transfers.index')"
                        class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                    >
                        Back to List
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Flash Messages -->
                <div v-if="$page.props.flash?.success" class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                    <p class="text-sm text-green-800 dark:text-green-300">{{ $page.props.flash.success }}</p>
                </div>
                <div v-if="$page.props.flash?.error" class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                    <p class="text-sm text-red-800 dark:text-red-300">{{ $page.props.flash.error }}</p>
                </div>

                <!-- Transfer Info -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                    <div class="flex justify-between items-start mb-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Transfer Details</h3>
                        <span
                            :class="getStatusBadgeClass(transfer.status)"
                            class="px-3 py-1 rounded-full text-sm font-medium"
                        >
                            {{ getStatusLabel(transfer.status) }}
                        </span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Transfer Number</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ transfer.transfer_number }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Transferred By</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ transfer.transferred_by_user?.name || '-' }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">From Location</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ transfer.from_location?.name }}
                                <span v-if="transfer.from_location?.code" class="text-gray-500 dark:text-gray-400">({{ transfer.from_location.code }})</span>
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">To Location</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ transfer.to_location?.name }}
                                <span v-if="transfer.to_location?.code" class="text-gray-500 dark:text-gray-400">({{ transfer.to_location.code }})</span>
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Created</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ formatDate(transfer.created_at) }}</p>
                        </div>
                        <div v-if="transfer.completed_at">
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Completed</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ formatDate(transfer.completed_at) }}</p>
                        </div>
                    </div>

                    <div v-if="transfer.notes" class="mt-6 pt-6 border-t border-gray-200 dark:border-dark-border">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Notes</p>
                        <p class="text-sm text-gray-900 dark:text-gray-100">{{ transfer.notes }}</p>
                    </div>
                </div>

                <!-- Transfer Items -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Transfer Items</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                            <thead class="bg-gray-50 dark:bg-dark-bg">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Product</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">SKU</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Quantity</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Notes</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-dark-card divide-y divide-gray-200 dark:divide-dark-border">
                                <tr v-for="item in transfer.items" :key="item.id">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ item.product?.name || '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                        {{ item.product?.sku || '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-900 dark:text-gray-100">
                                        {{ item.quantity }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                                        {{ item.notes || '-' }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-gray-50 dark:bg-dark-bg">
                                <tr>
                                    <td colspan="2" class="px-6 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">Total</td>
                                    <td class="px-6 py-3 text-sm text-right font-bold text-gray-900 dark:text-gray-100">
                                        {{ transfer.items?.reduce((sum, item) => sum + item.quantity, 0) || 0 }}
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Actions -->
                <div v-if="canComplete || canCancel" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Actions</h3>
                    <div class="flex gap-3">
                        <button
                            v-if="canComplete"
                            @click="completeTransfer"
                            :disabled="processing"
                            class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {{ processing ? 'Processing...' : 'Complete Transfer' }}
                        </button>
                        <button
                            v-if="canCancel"
                            @click="cancelTransfer"
                            :disabled="processing"
                            class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {{ processing ? 'Processing...' : 'Cancel Transfer' }}
                        </button>
                    </div>
                    <p v-if="canComplete" class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        Completing the transfer will deduct stock from the source location and add it to the destination location.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
