<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    adjustments: Object,
    filters: Object,
    products: Array,
    users: Array,
    types: Object,
});

const search = ref(props.filters?.search || '');
const selectedType = ref(props.filters?.type || '');
const selectedProduct = ref(props.filters?.product_id || '');
const selectedUser = ref(props.filters?.user_id || '');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');

const applyFilters = () => {
    router.get(route('stock-adjustments.index'), {
        search: search.value,
        type: selectedType.value,
        product_id: selectedProduct.value,
        user_id: selectedUser.value,
        date_from: dateFrom.value,
        date_to: dateTo.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    selectedType.value = '';
    selectedProduct.value = '';
    selectedUser.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    applyFilters();
};

const getAdjustmentColor = (quantity) => {
    if (quantity > 0) return 'text-green-600 dark:text-green-400';
    if (quantity < 0) return 'text-red-600 dark:text-red-400';
    return 'text-gray-600 dark:text-gray-400';
};

const getTypeBadgeClass = (type) => {
    const classes = {
        'manual': 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        'recount': 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300',
        'damage': 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
        'loss': 'bg-orange-100 dark:bg-orange-900/30 text-orange-800 dark:text-orange-300',
        'return': 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
        'correction': 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
        'order': 'bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-300',
    };
    return classes[type] || classes.manual;
};
</script>

<template>
    <Head title="Stock Adjustments" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Stock Adjustments</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Track all stock changes and adjustments</p>
                </div>
                <Link
                    :href="route('stock-adjustments.create')"
                    class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                >
                    New Adjustment
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Filters -->
                <div class="mb-6 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Filters</h3>

                    <form @submit.prevent="applyFilters" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Search</label>
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Search by product name or SKU..."
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Type</label>
                            <select
                                v-model="selectedType"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            >
                                <option value="">All Types</option>
                                <option v-for="(label, value) in types" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Product</label>
                            <select
                                v-model="selectedProduct"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            >
                                <option value="">All Products</option>
                                <option v-for="product in products" :key="product.id" :value="product.id">
                                    {{ product.name }} ({{ product.sku }})
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">User</label>
                            <select
                                v-model="selectedUser"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            >
                                <option value="">All Users</option>
                                <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                            </select>
                        </div>

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

                        <div class="md:col-span-2 lg:col-span-3 flex gap-3">
                            <button
                                type="submit"
                                class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                            >
                                Apply Filters
                            </button>
                            <button
                                type="button"
                                @click="clearFilters"
                                class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                            >
                                Clear Filters
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Adjustments Table -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                            <thead class="bg-gray-50 dark:bg-dark-bg/50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Product</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Before</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Change</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">After</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">User</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-dark-border">
                                <tr v-if="adjustments.data && adjustments.data.length > 0" v-for="adjustment in adjustments.data" :key="adjustment.id" class="hover:bg-gray-50 dark:hover:bg-dark-bg/50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                        {{ new Date(adjustment.created_at).toLocaleDateString() }}
                                        <br>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ new Date(adjustment.created_at).toLocaleTimeString() }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                        <div class="font-medium">{{ adjustment.product.name }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">SKU: {{ adjustment.product.sku }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full" :class="getTypeBadgeClass(adjustment.type)">
                                            {{ types[adjustment.type] || adjustment.type }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-600 dark:text-gray-300">
                                        {{ adjustment.quantity_before }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold" :class="getAdjustmentColor(adjustment.adjustment_quantity)">
                                        {{ adjustment.adjustment_quantity > 0 ? '+' : '' }}{{ adjustment.adjustment_quantity }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ adjustment.quantity_after }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                        {{ adjustment.user?.name || 'System' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <Link :href="route('stock-adjustments.show', adjustment.id)" class="text-primary-400 hover:text-primary-300">
                                            View
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-else>
                                    <td colspan="8" class="px-6 py-12 text-center">
                                        <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                        </svg>
                                        <p class="text-gray-500 dark:text-gray-400 mb-4">No stock adjustments found</p>
                                        <Link
                                            :href="route('stock-adjustments.create')"
                                            class="inline-flex items-center px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                                        >
                                            Create First Adjustment
                                        </Link>
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
