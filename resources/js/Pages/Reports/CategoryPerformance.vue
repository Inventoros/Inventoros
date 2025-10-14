<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    categories: Array,
    summary: Object,
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};
</script>

<template>
    <Head title="Category Performance Report" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Category Performance Report</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Analysis by product category</p>
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
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Total Categories</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ summary.total_categories }}</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Total Products</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ summary.total_products }}</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Total Value</p>
                        <p class="text-3xl font-bold text-green-600 dark:text-green-400">{{ formatCurrency(summary.total_value) }}</p>
                    </div>
                </div>

                <!-- Categories Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div v-for="category in categories" :key="category.category_id" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6 hover:shadow-md transition">
                        <div class="flex items-start justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                {{ category.category_name }}
                            </h3>
                            <div v-if="category.low_stock_items > 0" class="px-2 py-1 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300 text-xs font-semibold rounded-full">
                                {{ category.low_stock_items }} low
                            </div>
                        </div>

                        <div class="space-y-3">
                            <div class="flex justify-between items-center p-3 bg-gray-50 dark:bg-dark-bg rounded-lg">
                                <span class="text-sm text-gray-600 dark:text-gray-400">Products</span>
                                <span class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ category.product_count }}</span>
                            </div>

                            <div class="flex justify-between items-center p-3 bg-gray-50 dark:bg-dark-bg rounded-lg">
                                <span class="text-sm text-gray-600 dark:text-gray-400">Total Stock</span>
                                <span class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ category.total_stock }}</span>
                            </div>

                            <div class="flex justify-between items-center p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                                <span class="text-sm text-gray-600 dark:text-gray-400">Total Value</span>
                                <span class="text-lg font-semibold text-green-600 dark:text-green-400">{{ formatCurrency(category.total_value) }}</span>
                            </div>
                        </div>

                        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-dark-border">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-500 dark:text-gray-400">Avg per product</span>
                                <span class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ formatCurrency(category.total_value / category.product_count) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-if="categories.length === 0" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-12 text-center">
                    <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                    </svg>
                    <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">No categories found</p>
                    <p class="text-gray-400 dark:text-gray-500 text-sm mt-1">Add products to categories to see performance data</p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
