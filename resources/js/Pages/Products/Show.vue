<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    product: Object,
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};

const getStockStatus = () => {
    if (props.product.stock <= 0) {
        return { text: 'Out of Stock', class: 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300' };
    }
    if (props.product.stock <= props.product.min_stock) {
        return { text: 'Low Stock', class: 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300' };
    }
    return { text: 'In Stock', class: 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300' };
};

const stockStatus = getStockStatus();
</script>

<template>
    <Head :title="product.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    Product Details
                </h2>
                <div class="flex gap-3">
                    <Link
                        :href="route('products.edit', product.id)"
                        class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 transition"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        Edit
                    </Link>
                    <Link
                        :href="route('products.index')"
                        class="inline-flex items-center px-4 py-2 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-100 dark:hover:bg-dark-bg transition"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Back to Inventory
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Main Info -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Basic Information -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="p-6">
                                <div class="flex items-start justify-between mb-4">
                                    <div>
                                        <h3 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-2">
                                            {{ product.name }}
                                        </h3>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            SKU: {{ product.sku }}
                                        </p>
                                        <p v-if="product.barcode" class="text-sm text-gray-500 dark:text-gray-400">
                                            Barcode: {{ product.barcode }}
                                        </p>
                                    </div>
                                    <span :class="['px-3 py-1 text-sm font-semibold rounded-full', stockStatus.class]">
                                        {{ stockStatus.text }}
                                    </span>
                                </div>

                                <div v-if="product.description" class="mb-6">
                                    <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Description</h4>
                                    <p class="text-gray-900 dark:text-gray-100">{{ product.description }}</p>
                                </div>

                                <div v-if="product.notes" class="mb-6 p-4 bg-yellow-900/20 rounded-lg border border-yellow-800">
                                    <h4 class="text-sm font-medium text-yellow-300 mb-2">Notes</h4>
                                    <p class="text-sm text-yellow-400">{{ product.notes }}</p>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Category</h4>
                                        <p class="text-gray-900 dark:text-gray-100">
                                            {{ product.category?.name || 'Uncategorized' }}
                                        </p>
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Location</h4>
                                        <p class="text-gray-900 dark:text-gray-100">
                                            {{ product.location?.name || 'No location' }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pricing -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                    Pricing Information
                                </h3>
                                <div class="grid grid-cols-2 gap-4 mb-6">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Selling Price</h4>
                                        <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                            {{ formatCurrency(product.price) }}
                                        </p>
                                        <p v-if="product.currency" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                            Currency: {{ product.currency }}
                                        </p>
                                    </div>
                                    <div v-if="product.purchase_price">
                                        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Purchase Price</h4>
                                        <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                            {{ formatCurrency(product.purchase_price) }}
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                            What you paid
                                        </p>
                                    </div>
                                </div>

                                <!-- Profit Information -->
                                <div v-if="product.purchase_price && product.price" class="grid grid-cols-3 gap-4 p-4 bg-green-900/20 rounded-lg border border-green-800">
                                    <div>
                                        <h4 class="text-xs font-medium text-green-400 mb-1">Profit per Unit</h4>
                                        <p class="text-lg font-bold text-green-400">
                                            {{ formatCurrency(product.price - product.purchase_price) }}
                                        </p>
                                    </div>
                                    <div>
                                        <h4 class="text-xs font-medium text-green-400 mb-1">Profit Margin</h4>
                                        <p class="text-lg font-bold text-green-400">
                                            {{ ((product.price - product.purchase_price) / product.price * 100).toFixed(1) }}%
                                        </p>
                                    </div>
                                    <div>
                                        <h4 class="text-xs font-medium text-green-400 mb-1">Total Profit in Stock</h4>
                                        <p class="text-lg font-bold text-green-400">
                                            {{ formatCurrency((product.price - product.purchase_price) * product.stock) }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Additional Currencies -->
                                <div v-if="product.price_in_currencies && Object.keys(product.price_in_currencies).length > 0" class="mt-6">
                                    <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">Alternative Currencies</h4>
                                    <div class="grid grid-cols-3 gap-3">
                                        <div
                                            v-for="(price, currency) in product.price_in_currencies"
                                            :key="currency"
                                            class="p-3 bg-gray-50 dark:bg-dark-bg/50 rounded-lg border border-gray-200 dark:border-dark-border"
                                        >
                                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ currency }}</p>
                                            <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                                {{ new Intl.NumberFormat('en-US', { style: 'currency', currency: currency }).format(price) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6">
                        <!-- Stock Information -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                    Stock Information
                                </h3>
                                <div class="space-y-4">
                                    <div class="p-4 bg-primary-900/20 rounded-lg border border-primary-800">
                                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Current Stock</p>
                                        <p class="text-3xl font-bold text-primary-400">
                                            {{ product.stock }}
                                        </p>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="p-3 bg-gray-50 dark:bg-dark-bg/50 rounded-lg border border-gray-200 dark:border-dark-border">
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Min Stock</p>
                                            <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                                {{ product.min_stock }}
                                            </p>
                                        </div>
                                        <div v-if="product.max_stock" class="p-3 bg-gray-50 dark:bg-dark-bg/50 rounded-lg border border-gray-200 dark:border-dark-border">
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Max Stock</p>
                                            <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                                {{ product.max_stock }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="p-3 bg-green-900/20 rounded-lg border border-green-800">
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Total Value</p>
                                        <p class="text-xl font-bold text-green-400">
                                            {{ formatCurrency(product.price * product.stock) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Status -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                    Status
                                </h3>
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">Active</span>
                                        <span :class="[
                                            'px-2 py-1 text-xs font-semibold rounded-full',
                                            product.is_active
                                                ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300'
                                                : 'bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-300'
                                        ]">
                                            {{ product.is_active ? 'Yes' : 'No' }}
                                        </span>
                                    </div>
                                    <div class="pt-3 border-t border-gray-200 dark:border-dark-border">
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Created</p>
                                        <p class="text-sm text-gray-900 dark:text-gray-100">
                                            {{ new Date(product.created_at).toLocaleString() }}
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Last Updated</p>
                                        <p class="text-sm text-gray-900 dark:text-gray-100">
                                            {{ new Date(product.updated_at).toLocaleString() }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
