<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Reports & Analytics" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Reports & Analytics</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Business intelligence and insights</p>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <!-- Inventory Valuation Report -->
                    <Link
                        :href="route('reports.inventory-valuation')"
                        class="group bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border hover:border-primary-400 dark:hover:border-primary-400 rounded-lg shadow-sm hover:shadow-md transition p-6"
                    >
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0 w-12 h-12 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center group-hover:bg-green-200 dark:group-hover:bg-green-900/50 transition">
                                <svg class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 group-hover:text-primary-400 transition">
                                    Inventory Valuation
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                    Total stock value, costs, and profit potential
                                </p>
                            </div>
                            <svg class="w-5 h-5 text-gray-400 group-hover:text-primary-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </Link>

                    <!-- Stock Movement Report -->
                    <Link
                        :href="route('reports.stock-movement')"
                        class="group bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border hover:border-primary-400 dark:hover:border-primary-400 rounded-lg shadow-sm hover:shadow-md transition p-6"
                    >
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0 w-12 h-12 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center group-hover:bg-blue-200 dark:group-hover:bg-blue-900/50 transition">
                                <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 group-hover:text-primary-400 transition">
                                    Stock Movement
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                    Track all stock adjustments and changes
                                </p>
                            </div>
                            <svg class="w-5 h-5 text-gray-400 group-hover:text-primary-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </Link>

                    <!-- Sales Analysis Report -->
                    <Link
                        :href="route('reports.sales-analysis')"
                        class="group bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border hover:border-primary-400 dark:hover:border-primary-400 rounded-lg shadow-sm hover:shadow-md transition p-6"
                    >
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0 w-12 h-12 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center group-hover:bg-purple-200 dark:group-hover:bg-purple-900/50 transition">
                                <svg class="w-6 h-6 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 group-hover:text-primary-400 transition">
                                    Sales Analysis
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                    Revenue, trends, and top selling products
                                </p>
                            </div>
                            <svg class="w-5 h-5 text-gray-400 group-hover:text-primary-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </Link>

                    <!-- Low Stock Report -->
                    <Link
                        :href="route('reports.low-stock')"
                        class="group bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border hover:border-primary-400 dark:hover:border-primary-400 rounded-lg shadow-sm hover:shadow-md transition p-6"
                    >
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0 w-12 h-12 bg-red-100 dark:bg-red-900/30 rounded-lg flex items-center justify-center group-hover:bg-red-200 dark:group-hover:bg-red-900/50 transition">
                                <svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 group-hover:text-primary-400 transition">
                                    Low Stock Alert
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                    Products running low or out of stock
                                </p>
                            </div>
                            <svg class="w-5 h-5 text-gray-400 group-hover:text-primary-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </Link>

                    <!-- Category Performance Report -->
                    <Link
                        :href="route('reports.category-performance')"
                        class="group bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border hover:border-primary-400 dark:hover:border-primary-400 rounded-lg shadow-sm hover:shadow-md transition p-6"
                    >
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0 w-12 h-12 bg-yellow-100 dark:bg-yellow-900/30 rounded-lg flex items-center justify-center group-hover:bg-yellow-200 dark:group-hover:bg-yellow-900/50 transition">
                                <svg class="w-6 h-6 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 group-hover:text-primary-400 transition">
                                    Category Performance
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                    Analysis by product category
                                </p>
                            </div>
                            <svg class="w-5 h-5 text-gray-400 group-hover:text-primary-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </Link>

                    <!-- Coming Soon Placeholder -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border border-dashed rounded-lg shadow-sm p-6 opacity-60">
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0 w-12 h-12 bg-gray-100 dark:bg-gray-900/30 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold text-gray-500 dark:text-gray-400">
                                    More Reports
                                </h3>
                                <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">
                                    Additional reports coming soon
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
