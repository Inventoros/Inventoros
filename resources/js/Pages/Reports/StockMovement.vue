<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    adjustments: Object,
    summary: Object,
    products: Array,
    filters: Object,
});

const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');
const productId = ref(props.filters?.product_id || '');
const type = ref(props.filters?.type || '');

const applyFilters = () => {
    router.get(route('reports.stock-movement'), {
        date_from: dateFrom.value,
        date_to: dateTo.value,
        product_id: productId.value,
        type: type.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    dateFrom.value = '';
    dateTo.value = '';
    productId.value = '';
    type.value = '';
    applyFilters();
};

const getTypeBadgeClass = (adjustmentType) => {
    const classes = {
        'manual': 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        'recount': 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300',
        'damage': 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
        'loss': 'bg-orange-100 dark:bg-orange-900/30 text-orange-800 dark:text-orange-300',
        'return': 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
        'correction': 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
    };
    return classes[adjustmentType] || classes.manual;
};
</script>

<template>
    <Head :title="t('reports.stockMovement.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">{{ t('reports.stockMovement.title') }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ t('reports.stockMovement.description') }}</p>
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
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{ t('reports.stockMovement.totalAdjustments') }}</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ summary.total_adjustments }}</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{ t('reports.stockMovement.stockIncreases') }}</p>
                        <p class="text-3xl font-bold text-green-600 dark:text-green-400">+{{ summary.total_increases }}</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{ t('reports.stockMovement.stockDecreases') }}</p>
                        <p class="text-3xl font-bold text-red-600 dark:text-red-400">-{{ summary.total_decreases }}</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{ t('reports.stockMovement.netChange') }}</p>
                        <p class="text-3xl font-bold" :class="summary.net_change >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                            {{ summary.net_change >= 0 ? '+' : '' }}{{ summary.net_change }}
                        </p>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ t('reports.stockMovement.filters') }}</h3>
                    <form @submit.prevent="applyFilters" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">{{ t('reports.stockMovement.dateFrom') }}</label>
                            <input
                                v-model="dateFrom"
                                type="date"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">{{ t('reports.stockMovement.dateTo') }}</label>
                            <input
                                v-model="dateTo"
                                type="date"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">{{ t('common.product') }}</label>
                            <select
                                v-model="productId"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            >
                                <option value="">{{ t('reports.stockMovement.allProducts') }}</option>
                                <option v-for="product in products" :key="product.id" :value="product.id">
                                    {{ product.name }} ({{ product.sku }})
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">{{ t('common.type') }}</label>
                            <select
                                v-model="type"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            >
                                <option value="">{{ t('common.allTypes') }}</option>
                                <option value="manual">{{ t('reports.adjustmentTypes.manual') }}</option>
                                <option value="recount">{{ t('reports.adjustmentTypes.recount') }}</option>
                                <option value="damage">{{ t('reports.adjustmentTypes.damage') }}</option>
                                <option value="loss">{{ t('reports.adjustmentTypes.loss') }}</option>
                                <option value="return">{{ t('reports.adjustmentTypes.return') }}</option>
                                <option value="correction">{{ t('reports.adjustmentTypes.correction') }}</option>
                            </select>
                        </div>

                        <div class="md:col-span-4 flex gap-3">
                            <button
                                type="submit"
                                class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                            >
                                {{ t('common.applyFilters') }}
                            </button>
                            <button
                                type="button"
                                @click="clearFilters"
                                class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                            >
                                {{ t('common.clear') }}
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Adjustments Table -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                            <thead class="bg-gray-50 dark:bg-dark-bg/50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ t('common.date') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ t('common.product') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ t('common.type') }}</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ t('reports.stockMovement.before') }}</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ t('reports.stockMovement.change') }}</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ t('reports.stockMovement.after') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ t('common.user') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-dark-border">
                                <tr v-for="adj in adjustments.data" :key="adj.id" class="hover:bg-gray-50 dark:hover:bg-dark-bg/50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                        {{ new Date(adj.created_at).toLocaleDateString() }}
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ adj.product.name }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ adj.product.sku }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full" :class="getTypeBadgeClass(adj.type)">
                                            {{ adj.type }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-600 dark:text-gray-300">
                                        {{ adj.quantity_before }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold" :class="adj.adjustment_quantity >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                        {{ adj.adjustment_quantity >= 0 ? '+' : '' }}{{ adj.adjustment_quantity }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ adj.quantity_after }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                        {{ adj.user?.name || t('common.system') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="adjustments.links && adjustments.links.length > 3" class="px-6 py-4 border-t border-gray-200 dark:border-dark-border">
                        <nav class="flex justify-center gap-2">
                            <Link
                                v-for="(link, index) in adjustments.links"
                                :key="index"
                                :href="link.url"
                                :class="[
                                    'px-3 py-2 rounded-md text-sm font-medium transition',
                                    link.active
                                        ? 'bg-primary-500 text-white'
                                        : link.url
                                        ? 'bg-gray-100 dark:bg-dark-bg text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-dark-bg/80'
                                        : 'bg-gray-100 dark:bg-dark-bg/50 text-gray-400 cursor-not-allowed opacity-50'
                                ]"
                                :disabled="!link.url"
                                v-html="link.label"
                            />
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
