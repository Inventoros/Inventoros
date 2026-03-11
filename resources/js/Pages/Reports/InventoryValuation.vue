<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    products: Array,
    summary: Object,
    byCategory: Array,
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};
</script>

<template>
    <Head :title="t('reports.inventoryValuation.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">{{ t('reports.inventoryValuation.title') }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ t('reports.inventoryValuation.description') }}</p>
                </div>
                <Link
                    :href="route('reports.index')"
                    class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                >
                    {{ t('reports.backToReports') }}
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{ t('reports.inventoryValuation.totalItems') }}</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ summary.total_items }}</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ t('reports.inventoryValuation.productSkus') }}</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{ t('reports.inventoryValuation.totalQuantity') }}</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ summary.total_quantity }}</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ t('reports.inventoryValuation.unitsInStock') }}</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{ t('reports.inventoryValuation.stockValue') }}</p>
                        <p class="text-3xl font-bold text-green-600 dark:text-green-400">{{ formatCurrency(summary.total_stock_value) }}</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ t('reports.inventoryValuation.atSellingPrice') }}</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{ t('reports.inventoryValuation.profitPotential') }}</p>
                        <p class="text-3xl font-bold text-primary-400">{{ formatCurrency(summary.total_profit_potential) }}</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ t('reports.inventoryValuation.ifAllSold') }}</p>
                    </div>
                </div>

                <!-- By Category -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ t('reports.inventoryValuation.valueByCategory') }}</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div v-for="cat in byCategory" :key="cat.category" class="p-4 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-lg">
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ cat.category }}</p>
                            <div class="mt-2 space-y-1">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500 dark:text-gray-400">Items:</span>
                                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ cat.items }}</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500 dark:text-gray-400">Quantity:</span>
                                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ cat.quantity }}</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500 dark:text-gray-400">Value:</span>
                                    <span class="font-semibold text-green-600 dark:text-green-400">{{ formatCurrency(cat.value) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Products Table -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm overflow-hidden">
                    <div class="p-6 border-b border-gray-200 dark:border-dark-border">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ t('reports.inventoryValuation.productDetails') }}</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                            <thead class="bg-gray-50 dark:bg-dark-bg/50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ t('common.product') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ t('products.category') }}</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ t('products.stock') }}</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ t('common.price') }}</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ t('reports.inventoryValuation.stockValue') }}</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ t('reports.inventoryValuation.profitPotential') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-dark-border">
                                <tr v-for="product in products" :key="product.id" class="hover:bg-gray-50 dark:hover:bg-dark-bg/50">
                                    <td class="px-6 py-4">
                                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ product.name }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">SKU: {{ product.sku }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                        {{ product.category || t('reports.inventoryValuation.uncategorized') }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ product.stock }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm text-gray-600 dark:text-gray-300">
                                        {{ formatCurrency(product.price) }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm font-semibold text-green-600 dark:text-green-400">
                                        {{ formatCurrency(product.stock_value) }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm font-semibold text-primary-400">
                                        {{ formatCurrency(product.profit_potential) }}
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
