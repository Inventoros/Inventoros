<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    summary: Object,
    byStatus: Array,
    topProducts: Array,
    dailySales: Array,
    filters: Object,
});

const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');

const applyFilters = () => {
    router.get(route('reports.sales-analysis'), {
        date_from: dateFrom.value,
        date_to: dateTo.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};

const getStatusBadgeClass = (status) => {
    const classes = {
        'pending': 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
        'processing': 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        'shipped': 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300',
        'delivered': 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
        'cancelled': 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
    };
    return classes[status] || 'bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-300';
};
</script>

<template>
    <Head title="Sales Analysis Report" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Sales Analysis Report</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Revenue and sales performance</p>
                </div>
                <Link
                    :href="route('reports.index')"
                    class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                >
                    Back to Reports
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Date Filter -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6 mb-6">
                    <form @submit.prevent="applyFilters" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Date From</label>
                            <input
                                v-model="dateFrom"
                                type="date"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Date To</label>
                            <input
                                v-model="dateTo"
                                type="date"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            />
                        </div>

                        <div class="flex items-end">
                            <button
                                type="submit"
                                class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                            >
                                Apply
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Total Orders</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ summary.total_orders }}</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Total Revenue</p>
                        <p class="text-3xl font-bold text-green-600 dark:text-green-400">{{ formatCurrency(summary.total_revenue) }}</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Items Sold</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ summary.total_items_sold }}</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Avg Order Value</p>
                        <p class="text-3xl font-bold text-primary-400">{{ formatCurrency(summary.average_order_value) }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <!-- Sales by Status -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Sales by Status</h3>
                        <div class="space-y-3">
                            <div v-for="item in byStatus" :key="item.status" class="flex items-center justify-between p-3 bg-gray-50 dark:bg-dark-bg rounded-lg">
                                <div class="flex items-center gap-3">
                                    <span class="px-3 py-1 text-xs font-semibold rounded-full capitalize" :class="getStatusBadgeClass(item.status)">
                                        {{ item.status }}
                                    </span>
                                    <span class="text-sm text-gray-600 dark:text-gray-300">{{ item.count }} orders</span>
                                </div>
                                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ formatCurrency(item.revenue) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Top Products -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Top Selling Products</h3>
                        <div class="space-y-3">
                            <div v-for="(product, index) in topProducts" :key="index" class="flex items-center justify-between p-3 bg-gray-50 dark:bg-dark-bg rounded-lg">
                                <div class="flex-1">
                                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ product.product_name }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ product.quantity_sold }} units sold</p>
                                </div>
                                <span class="font-semibold text-green-600 dark:text-green-400">{{ formatCurrency(product.revenue) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Daily Sales Trend -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Daily Sales Trend</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-dark-border">
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Orders</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Revenue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-dark-border">
                                <tr v-for="day in dailySales" :key="day.date">
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                        {{ new Date(day.date).toLocaleDateString() }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-600 dark:text-gray-300">
                                        {{ day.orders }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm font-semibold text-green-600 dark:text-green-400">
                                        {{ formatCurrency(day.revenue) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
