<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    orders: Object,
    filters: Object,
    statuses: Array,
    sources: Array,
});

const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');
const source = ref(props.filters?.source || '');

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};

const searchOrders = () => {
    router.get(route('orders.index'), {
        search: search.value,
        status: status.value,
        source: source.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    status.value = '';
    source.value = '';
    searchOrders();
};

const deleteOrder = (order) => {
    if (confirm(`Are you sure you want to delete order ${order.order_number}?`)) {
        router.delete(route('orders.destroy', order.id));
    }
};

const getStatusBadgeClass = (orderStatus) => {
    const classes = {
        pending: 'bg-amber-900/30 text-amber-300',
        processing: 'bg-blue-900/30 text-blue-300',
        shipped: 'bg-purple-900/30 text-purple-300',
        delivered: 'bg-green-900/30 text-green-300',
        cancelled: 'bg-red-900/30 text-red-300',
    };
    return classes[orderStatus] || classes.pending;
};

const getSourceBadgeClass = (orderSource) => {
    const classes = {
        manual: 'bg-gray-900/30 text-gray-300',
        ebay: 'bg-yellow-900/30 text-yellow-300',
        shopify: 'bg-green-900/30 text-green-300',
        amazon: 'bg-orange-900/30 text-orange-300',
    };
    return classes[orderSource] || classes.manual;
};
</script>

<template>
    <Head title="Orders" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-100 leading-tight">
                    Orders
                </h2>
                <Link
                    :href="route('orders.create')"
                    class="inline-flex items-center px-4 py-2 bg-primary-400 hover:bg-primary-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-primary-400 focus:ring-offset-2 focus:ring-offset-gray-900 transition ease-in-out duration-150"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Create Order
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Search and Filters -->
                <div class="mb-6 bg-dark-card border border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <div class="p-6">
                        <form @submit.prevent="searchOrders" class="space-y-4">
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                                <!-- Search -->
                                <div class="md:col-span-2">
                                    <label for="search" class="block text-sm font-medium text-gray-300 mb-1">
                                        Search Orders
                                    </label>
                                    <input
                                        id="search"
                                        v-model="search"
                                        type="text"
                                        placeholder="Search by order number, customer name or email..."
                                        class="block w-full rounded-md bg-dark-bg border-dark-border text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    />
                                </div>

                                <!-- Status Filter -->
                                <div>
                                    <label for="status" class="block text-sm font-medium text-gray-300 mb-1">
                                        Status
                                    </label>
                                    <select
                                        id="status"
                                        v-model="status"
                                        class="block w-full rounded-md bg-dark-bg border-dark-border text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    >
                                        <option value="">All Statuses</option>
                                        <option v-for="stat in statuses" :key="stat" :value="stat">
                                            {{ stat.charAt(0).toUpperCase() + stat.slice(1) }}
                                        </option>
                                    </select>
                                </div>

                                <!-- Source Filter -->
                                <div>
                                    <label for="source" class="block text-sm font-medium text-gray-300 mb-1">
                                        Source
                                    </label>
                                    <select
                                        id="source"
                                        v-model="source"
                                        class="block w-full rounded-md bg-dark-bg border-dark-border text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    >
                                        <option value="">All Sources</option>
                                        <option v-for="src in sources" :key="src" :value="src">
                                            {{ src.charAt(0).toUpperCase() + src.slice(1) }}
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <!-- Filter Actions -->
                            <div class="flex items-center gap-3">
                                <button
                                    type="submit"
                                    class="inline-flex items-center px-4 py-2 bg-primary-400 hover:bg-primary-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                    Search
                                </button>
                                <button
                                    type="button"
                                    @click="clearFilters"
                                    class="inline-flex items-center px-4 py-2 bg-dark-bg border border-dark-border rounded-md font-semibold text-xs text-gray-300 uppercase tracking-widest hover:bg-dark-bg/50"
                                >
                                    Clear Filters
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Orders Table -->
                <div class="bg-dark-card border border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-dark-border">
                            <thead class="bg-dark-bg/50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">
                                        Order
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">
                                        Customer
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">
                                        Items
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">
                                        Total
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">
                                        Source
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">
                                        Date
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-dark-card divide-y divide-dark-border">
                                <tr v-if="orders.data.length === 0">
                                    <td colspan="8" class="px-6 py-12 text-center">
                                        <svg class="w-12 h-12 text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                        </svg>
                                        <p class="text-gray-400 mb-3">No orders found</p>
                                        <Link
                                            :href="route('orders.create')"
                                            class="inline-flex items-center px-4 py-2 bg-primary-400 hover:bg-primary-500 text-white text-sm font-semibold rounded-lg transition"
                                        >
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                            </svg>
                                            Create Your First Order
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-for="order in orders.data" :key="order.id" class="hover:bg-dark-bg/30">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-100">
                                            {{ order.order_number }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-100">{{ order.customer_name }}</div>
                                        <div v-if="order.customer_email" class="text-sm text-gray-400">{{ order.customer_email }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-100">
                                        {{ order.items.length }} items
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-100">
                                        {{ formatCurrency(order.total) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="['px-2 inline-flex text-xs leading-5 font-semibold rounded-full', getStatusBadgeClass(order.status)]">
                                            {{ order.status }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="['px-2 inline-flex text-xs leading-5 font-semibold rounded-full', getSourceBadgeClass(order.source)]">
                                            {{ order.source }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-100">
                                        {{ new Date(order.order_date).toLocaleDateString() }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex items-center justify-end gap-2">
                                            <Link
                                                :href="route('orders.show', order.id)"
                                                class="text-primary-400 hover:text-primary-300"
                                            >
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </svg>
                                            </Link>
                                            <Link
                                                :href="route('orders.edit', order.id)"
                                                class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300"
                                            >
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </Link>
                                            <button
                                                @click="deleteOrder(order)"
                                                class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
                                            >
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="orders.data.length > 0" class="bg-dark-card px-4 py-3 border-t border-dark-border sm:px-6">
                        <div class="flex items-center justify-between">
                            <div class="flex-1 flex justify-between sm:hidden">
                                <Link
                                    v-if="orders.prev_page_url"
                                    :href="orders.prev_page_url"
                                    class="relative inline-flex items-center px-4 py-2 border border-dark-border text-sm font-medium rounded-md text-gray-300 bg-dark-card hover:bg-dark-bg/50"
                                >
                                    Previous
                                </Link>
                                <Link
                                    v-if="orders.next_page_url"
                                    :href="orders.next_page_url"
                                    class="ml-3 relative inline-flex items-center px-4 py-2 border border-dark-border text-sm font-medium rounded-md text-gray-300 bg-dark-card hover:bg-dark-bg/50"
                                >
                                    Next
                                </Link>
                            </div>
                            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm text-gray-300">
                                        Showing
                                        <span class="font-medium">{{ orders.from }}</span>
                                        to
                                        <span class="font-medium">{{ orders.to }}</span>
                                        of
                                        <span class="font-medium">{{ orders.total }}</span>
                                        results
                                    </p>
                                </div>
                                <div>
                                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                        <template v-for="link in orders.links" :key="link.label">
                                            <Link
                                                v-if="link.url"
                                                :href="link.url"
                                                :class="[
                                                    'relative inline-flex items-center px-4 py-2 border text-sm font-medium',
                                                    link.active
                                                        ? 'z-10 bg-primary-400/20 border-primary-400 text-primary-400'
                                                        : 'bg-dark-card border-dark-border text-gray-400 hover:bg-dark-bg/50'
                                                ]"
                                                v-html="link.label"
                                            />
                                            <span
                                                v-else
                                                :class="[
                                                    'relative inline-flex items-center px-4 py-2 border text-sm font-medium',
                                                    'bg-dark-card border-dark-border text-gray-400 opacity-50 cursor-not-allowed'
                                                ]"
                                                v-html="link.label"
                                            />
                                        </template>
                                    </nav>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
