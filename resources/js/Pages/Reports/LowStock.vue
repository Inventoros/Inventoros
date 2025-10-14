<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    products: Array,
    summary: Object,
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};

const getStatusBadgeClass = (status) => {
    return status === 'out_of_stock'
        ? 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300'
        : 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300';
};
</script>

<template>
    <Head title="Low Stock Report" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Low Stock Report</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Products that need restocking</p>
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
                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Total Low Stock</p>
                        <p class="text-3xl font-bold text-yellow-600 dark:text-yellow-400">{{ summary.total_low_stock }}</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Products</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Out of Stock</p>
                        <p class="text-3xl font-bold text-red-600 dark:text-red-400">{{ summary.out_of_stock }}</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Critical</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Low Stock</p>
                        <p class="text-3xl font-bold text-yellow-600 dark:text-yellow-400">{{ summary.low_stock }}</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Warning</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Reorder Cost</p>
                        <p class="text-3xl font-bold text-primary-400">{{ formatCurrency(summary.total_reorder_cost) }}</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Estimated</p>
                    </div>
                </div>

                <!-- Low Stock Products Table -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm overflow-hidden">
                    <div class="p-6 border-b border-gray-200 dark:border-dark-border">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Products Requiring Attention</h3>
                    </div>

                    <div v-if="products.length > 0" class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                            <thead class="bg-gray-50 dark:bg-dark-bg/50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Product</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Category</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Current</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Min</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Max</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Deficit</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Reorder Cost</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-dark-border">
                                <tr v-for="product in products" :key="product.id" class="hover:bg-gray-50 dark:hover:bg-dark-bg/50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full capitalize" :class="getStatusBadgeClass(product.status)">
                                            {{ product.status.replace('_', ' ') }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ product.name }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">SKU: {{ product.sku }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                        {{ product.category || 'Uncategorized' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold" :class="product.current_stock === 0 ? 'text-red-600 dark:text-red-400' : 'text-yellow-600 dark:text-yellow-400'">
                                        {{ product.current_stock }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-600 dark:text-gray-300">
                                        {{ product.min_stock }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-600 dark:text-gray-300">
                                        {{ product.max_stock || '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold text-red-600 dark:text-red-400">
                                        -{{ product.deficit }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold text-gray-900 dark:text-gray-100">
                                        {{ formatCurrency(product.reorder_cost) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="p-12 text-center">
                        <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">All products are well stocked!</p>
                        <p class="text-gray-400 dark:text-gray-500 text-sm mt-1">No products are below minimum stock levels</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
