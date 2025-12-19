<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import BarcodeScannerModal from '@/Components/BarcodeScannerModal.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    purchaseOrder: Object,
    pluginComponents: Object,
});

const showScanner = ref(false);

const form = useForm({
    items: props.purchaseOrder.items?.map(item => ({
        id: item.id,
        product_id: item.product_id,
        product_name: item.product_name,
        sku: item.sku,
        quantity_ordered: item.quantity_ordered,
        quantity_received: item.quantity_received,
        remaining: item.quantity_ordered - item.quantity_received,
        quantity_to_receive: 0,
    })) || [],
});

const hasItemsToReceive = computed(() => {
    return form.items.some(item => item.quantity_to_receive > 0);
});

const totalItemsToReceive = computed(() => {
    return form.items.reduce((sum, item) => sum + item.quantity_to_receive, 0);
});

const receiveAll = (index) => {
    form.items[index].quantity_to_receive = form.items[index].remaining;
};

const receiveAllItems = () => {
    form.items.forEach(item => {
        item.quantity_to_receive = item.remaining;
    });
};

const clearAll = () => {
    form.items.forEach(item => {
        item.quantity_to_receive = 0;
    });
};

const onProductFound = (product) => {
    // Find the item in the list and increment its receive quantity
    const itemIndex = form.items.findIndex(item => item.product_id === product.id);
    if (itemIndex >= 0) {
        const item = form.items[itemIndex];
        if (item.quantity_to_receive < item.remaining) {
            item.quantity_to_receive++;
        }
    }
    showScanner.value = false;
};

const submit = () => {
    form.post(route('purchase-orders.process-receiving', props.purchaseOrder.id));
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: props.purchaseOrder.currency || 'USD',
    }).format(value || 0);
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
</script>

<template>
    <Head :title="`Receive - ${purchaseOrder.po_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    Receive Items - {{ purchaseOrder.po_number }}
                </h2>
                <div class="flex items-center gap-2">
                    <button
                        @click="showScanner = true"
                        class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                        </svg>
                        Scan Barcode
                    </button>
                    <Link
                        :href="route('purchase-orders.show', purchaseOrder.id)"
                        class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                    >
                        Back
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
                <!-- Plugin Slot: Header -->
                <PluginSlot slot="header" :components="pluginComponents?.header" />

                <!-- Order Summary -->
                <div class="mb-6 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                                    {{ purchaseOrder.supplier?.name }}
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    Order Total: {{ formatCurrency(purchaseOrder.total) }}
                                </p>
                            </div>
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
                </div>

                <!-- Receiving Form -->
                <form @submit.prevent="submit">
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border flex items-center justify-between">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Items to Receive</h3>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    @click="receiveAllItems"
                                    class="inline-flex items-center px-3 py-1.5 bg-green-500 border border-transparent rounded-md text-xs text-white font-semibold uppercase tracking-widest hover:bg-green-600"
                                >
                                    Receive All
                                </button>
                                <button
                                    type="button"
                                    @click="clearAll"
                                    class="inline-flex items-center px-3 py-1.5 bg-gray-100 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md text-xs text-gray-700 dark:text-gray-300 font-semibold uppercase tracking-widest hover:bg-gray-200 dark:hover:bg-dark-border"
                                >
                                    Clear
                                </button>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                                <thead class="bg-gray-50 dark:bg-dark-bg">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Product</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">SKU</th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Ordered</th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Already Received</th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Remaining</th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Receive Now</th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-dark-card divide-y divide-gray-200 dark:divide-dark-border">
                                    <tr v-for="(item, index) in form.items" :key="item.id" :class="{ 'bg-green-50 dark:bg-green-900/10': item.remaining === 0 }">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                            {{ item.product_name }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ item.sku || '-' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100 text-center">
                                            {{ item.quantity_ordered }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                            <span :class="[
                                                item.quantity_received >= item.quantity_ordered
                                                    ? 'text-green-600 dark:text-green-400 font-medium'
                                                    : item.quantity_received > 0
                                                        ? 'text-yellow-600 dark:text-yellow-400'
                                                        : 'text-gray-500 dark:text-gray-400'
                                            ]">
                                                {{ item.quantity_received }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                            <span :class="[
                                                item.remaining === 0
                                                    ? 'text-green-600 dark:text-green-400 font-medium'
                                                    : 'text-gray-900 dark:text-gray-100 font-medium'
                                            ]">
                                                {{ item.remaining }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <input
                                                v-if="item.remaining > 0"
                                                type="number"
                                                v-model.number="item.quantity_to_receive"
                                                :max="item.remaining"
                                                min="0"
                                                class="w-20 rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm text-center"
                                            />
                                            <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                                                Complete
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <button
                                                v-if="item.remaining > 0"
                                                type="button"
                                                @click="receiveAll(index)"
                                                class="text-primary-400 hover:text-primary-500 text-sm font-medium"
                                            >
                                                Receive All
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Summary & Submit -->
                        <div class="px-6 py-4 bg-gray-50 dark:bg-dark-bg border-t border-gray-200 dark:border-dark-border">
                            <div class="flex items-center justify-between">
                                <div class="text-sm text-gray-600 dark:text-gray-400">
                                    <span v-if="hasItemsToReceive">
                                        Ready to receive <strong class="text-gray-900 dark:text-gray-100">{{ totalItemsToReceive }}</strong> items
                                    </span>
                                    <span v-else>
                                        Select quantities to receive
                                    </span>
                                </div>
                                <div class="flex items-center gap-4">
                                    <Link
                                        :href="route('purchase-orders.show', purchaseOrder.id)"
                                        class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                                    >
                                        Cancel
                                    </Link>
                                    <button
                                        type="submit"
                                        :disabled="form.processing || !hasItemsToReceive"
                                        class="inline-flex items-center px-4 py-2 bg-green-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-600 focus:bg-green-600 active:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-400 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50"
                                    >
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                        </svg>
                                        Confirm Receiving
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Plugin Slot: Footer -->
                <PluginSlot slot="footer" :components="pluginComponents?.footer" />
            </div>
        </div>

        <!-- Barcode Scanner Modal -->
        <BarcodeScannerModal
            :show="showScanner"
            @close="showScanner = false"
            @product-found="onProductFound"
        />
    </AuthenticatedLayout>
</template>
