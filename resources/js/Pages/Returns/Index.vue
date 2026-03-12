<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    returns: Object,
    filters: Object,
    statuses: Array,
    types: Array,
});

const search = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || '');
const typeFilter = ref(props.filters?.type || '');

let searchTimeout = null;

const applyFilters = () => {
    router.get(route('returns.index'), {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        type: typeFilter.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
};

watch(search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 300);
});

watch([statusFilter, typeFilter], applyFilters);

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

const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const totalItems = (returnOrder) => {
    if (!returnOrder.items) return 0;
    return returnOrder.items.reduce((sum, item) => sum + item.quantity, 0);
};
</script>

<template>
    <Head title="Returns & Exchanges" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    Returns & Exchanges
                </h2>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Filters -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-4 mb-6">
                    <div class="flex flex-col sm:flex-row gap-4">
                        <div class="flex-1">
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Search by return number, order number, or customer..."
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400 sm:text-sm"
                            />
                        </div>
                        <div class="flex gap-4">
                            <select
                                v-model="statusFilter"
                                class="rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 sm:text-sm"
                            >
                                <option value="">All Statuses</option>
                                <option v-for="status in statuses" :key="status" :value="status" class="capitalize">
                                    {{ status.charAt(0).toUpperCase() + status.slice(1) }}
                                </option>
                            </select>
                            <select
                                v-model="typeFilter"
                                class="rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 sm:text-sm"
                            >
                                <option value="">All Types</option>
                                <option v-for="type in types" :key="type" :value="type" class="capitalize">
                                    {{ type.charAt(0).toUpperCase() + type.slice(1) }}
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg overflow-hidden">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                        <thead class="bg-gray-50 dark:bg-dark-bg">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Return #</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Order #</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Items</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Refund</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-dark-border">
                            <tr v-for="returnOrder in returns.data" :key="returnOrder.id" class="hover:bg-gray-50 dark:hover:bg-dark-bg/50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {{ returnOrder.return_number }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    <Link v-if="returnOrder.order" :href="route('orders.show', returnOrder.order_id)" class="text-primary-400 hover:text-primary-300">
                                        {{ returnOrder.order.order_number }}
                                    </Link>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span :class="getTypeClass(returnOrder.type)" class="px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase">
                                        {{ returnOrder.type }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span :class="getStatusClass(returnOrder.status)" class="px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase">
                                        {{ returnOrder.status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    {{ totalItems(returnOrder) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    ${{ parseFloat(returnOrder.refund_amount || 0).toFixed(2) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    {{ formatDate(returnOrder.created_at) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <Link :href="route('returns.show', returnOrder.id)" class="text-primary-400 hover:text-primary-300 font-medium">
                                        View
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!returns.data || returns.data.length === 0">
                                <td colspan="8" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                                    No returns found.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <div v-if="returns.links && returns.links.length > 3" class="px-6 py-3 border-t border-gray-200 dark:border-dark-border flex justify-between items-center">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Showing {{ returns.from }} to {{ returns.to }} of {{ returns.total }} results
                        </p>
                        <div class="flex gap-1">
                            <template v-for="link in returns.links" :key="link.label">
                                <Link
                                    v-if="link.url"
                                    :href="link.url"
                                    class="px-3 py-1 rounded text-sm"
                                    :class="link.active ? 'bg-primary-400 text-white' : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-dark-bg'"
                                    v-html="link.label"
                                    preserve-state
                                />
                                <span
                                    v-else
                                    class="px-3 py-1 rounded text-sm text-gray-400 dark:text-gray-600"
                                    v-html="link.label"
                                />
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
