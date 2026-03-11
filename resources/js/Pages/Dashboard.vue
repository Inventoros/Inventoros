<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    stats: Object,
    recentProducts: Array,
    lowStockProducts: Array,
    recentOrders: Array,
    stockByCategory: Array,
    pluginComponents: Object,
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};

const formatNumber = (value) => {
    if (value >= 1000000) {
        return (value / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    }
    if (value >= 1000) {
        return (value / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
    }
    return value.toString();
};

const formatCompactCurrency = (value) => {
    const formatted = formatNumber(value);
    // If it ends with K or M, add $ prefix
    if (formatted.endsWith('K') || formatted.endsWith('M')) {
        return '$' + formatted;
    }
    return formatCurrency(value);
};
</script>

<template>
    <Head :title="t('dashboard.title')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ t('dashboard.title') }}
            </h2>
        </template>

        <div class="py-8 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <!-- Plugin Slot: Header -->
                <PluginSlot slot="header" :components="pluginComponents?.header" />

                <!-- Plugin Slot: Before Stats -->
                <PluginSlot slot="before-stats" :components="pluginComponents?.beforeStats" />

                <!-- Primary Stats Grid -->
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 mb-6">
                    <!-- Total Products -->
                    <div class="bg-white dark:bg-dark-card rounded-xl border border-gray-100 dark:border-dark-border shadow-card hover:shadow-card-hover transition-shadow">
                        <div class="p-5">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('dashboard.totalProducts') }}</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">
                                        {{ formatNumber(stats.totalProducts) }}
                                    </p>
                                </div>
                                <div class="w-11 h-11 bg-primary-50 dark:bg-primary-500/10 rounded-xl flex items-center justify-center">
                                    <svg class="w-6 h-6 text-primary-600 dark:text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total Value -->
                    <div class="bg-white dark:bg-dark-card rounded-xl border border-gray-100 dark:border-dark-border shadow-card hover:shadow-card-hover transition-shadow">
                        <div class="p-5">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('dashboard.totalValue') }}</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">
                                        {{ formatCompactCurrency(stats.totalValue) }}
                                    </p>
                                </div>
                                <div class="w-11 h-11 bg-emerald-50 dark:bg-emerald-500/10 rounded-xl flex items-center justify-center">
                                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Low Stock -->
                    <div class="bg-white dark:bg-dark-card rounded-xl border border-gray-100 dark:border-dark-border shadow-card hover:shadow-card-hover transition-shadow">
                        <div class="p-5">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('dashboard.lowStock') }}</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">
                                        {{ formatNumber(stats.lowStockProducts) }}
                                    </p>
                                </div>
                                <div class="w-11 h-11 bg-amber-50 dark:bg-amber-500/10 rounded-xl flex items-center justify-center">
                                    <svg class="w-6 h-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total Orders -->
                    <div class="bg-white dark:bg-dark-card rounded-xl border border-gray-100 dark:border-dark-border shadow-card hover:shadow-card-hover transition-shadow">
                        <div class="p-5">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ t('dashboard.totalOrders') }}</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">
                                        {{ formatNumber(stats.totalOrders) }}
                                    </p>
                                </div>
                                <div class="w-11 h-11 bg-violet-50 dark:bg-violet-500/10 rounded-xl flex items-center justify-center">
                                    <svg class="w-6 h-6 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Secondary Stats Row -->
                <div class="grid grid-cols-2 gap-4 mb-6 sm:grid-cols-3 lg:grid-cols-5">
                    <!-- Pending Orders -->
                    <Link :href="route('orders.index', { status: 'pending' })" class="bg-white dark:bg-dark-card rounded-xl border border-gray-100 dark:border-dark-border shadow-card hover:shadow-card-hover hover:border-primary-200 dark:hover:border-primary-800 transition-all p-4 block">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ t('dashboard.pendingOrders') }}</p>
                        <p class="text-xl font-bold text-amber-600 dark:text-amber-400 mt-1">
                            {{ formatNumber(stats.pendingOrders) }}
                        </p>
                    </Link>

                    <!-- Categories -->
                    <Link :href="route('categories.index')" class="bg-white dark:bg-dark-card rounded-xl border border-gray-100 dark:border-dark-border shadow-card hover:shadow-card-hover hover:border-primary-200 dark:hover:border-primary-800 transition-all p-4 block">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ t('dashboard.categories') }}</p>
                        <p class="text-xl font-bold text-primary-600 dark:text-primary-400 mt-1">
                            {{ stats.categories }}
                        </p>
                    </Link>

                    <!-- Locations -->
                    <Link :href="route('locations.index')" class="bg-white dark:bg-dark-card rounded-xl border border-gray-100 dark:border-dark-border shadow-card hover:shadow-card-hover hover:border-primary-200 dark:hover:border-primary-800 transition-all p-4 block">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ t('dashboard.locations') }}</p>
                        <p class="text-xl font-bold text-primary-600 dark:text-primary-400 mt-1">
                            {{ stats.locations }}
                        </p>
                    </Link>

                    <!-- Inventory Value -->
                    <div class="bg-white dark:bg-dark-card rounded-xl border border-gray-100 dark:border-dark-border shadow-card p-4">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ t('dashboard.inventoryValue') }}</p>
                        <p class="text-xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">
                            {{ formatCompactCurrency(stats.totalValue) }}
                        </p>
                    </div>

                    <!-- Revenue This Month -->
                    <div class="bg-white dark:bg-dark-card rounded-xl border border-gray-100 dark:border-dark-border shadow-card p-4">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ t('dashboard.revenueThisMonth') }}</p>
                        <p class="text-xl font-bold text-primary-600 dark:text-primary-400 mt-1">
                            {{ formatCompactCurrency(stats.revenueThisMonth) }}
                        </p>
                    </div>
                </div>

                <!-- Plugin Slot: After Stats -->
                <PluginSlot slot="after-stats" :components="pluginComponents?.afterStats" />

                <!-- Plugin Slot: Before Content Grid -->
                <PluginSlot slot="before-content" :components="pluginComponents?.beforeContent" />

                <!-- Three Column Layout -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 mb-6">
                    <!-- Recent Orders -->
                    <div class="bg-white dark:bg-dark-card rounded-xl border border-gray-100 dark:border-dark-border shadow-card">
                        <div class="px-5 py-4 border-b border-gray-100 dark:border-dark-border flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {{ t('dashboard.recentOrders') }}
                            </h3>
                            <Link
                                :href="route('orders.index')"
                                class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300"
                            >
                                {{ t('dashboard.viewAll') }}
                            </Link>
                        </div>

                        <div class="p-5">
                            <div v-if="recentOrders.length === 0" class="text-center py-6">
                                <div class="w-12 h-12 bg-gray-100 dark:bg-dark-border rounded-xl flex items-center justify-center mx-auto mb-3">
                                    <svg class="w-6 h-6 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                    </svg>
                                </div>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">{{ t('dashboard.noOrdersYet') }}</p>
                                <Link
                                    :href="route('orders.create')"
                                    class="inline-flex items-center px-3.5 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg transition"
                                >
                                    {{ t('dashboard.createFirstOrder') }}
                                </Link>
                            </div>

                            <div v-else class="space-y-2.5">
                                <div
                                    v-for="order in recentOrders"
                                    :key="order.id"
                                    class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-800/50 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-800 transition cursor-pointer"
                                    @click="$inertia.visit(route('orders.show', order.id))"
                                >
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                                {{ order.order_number }}
                                            </p>
                                            <span
                                                :class="[
                                                    'px-2 py-0.5 text-[11px] font-semibold rounded-full',
                                                    order.status === 'pending' ? 'bg-amber-100 dark:bg-amber-500/15 text-amber-700 dark:text-amber-400' :
                                                    order.status === 'processing' ? 'bg-blue-100 dark:bg-blue-500/15 text-blue-700 dark:text-blue-400' :
                                                    order.status === 'shipped' ? 'bg-purple-100 dark:bg-purple-500/15 text-purple-700 dark:text-purple-400' :
                                                    order.status === 'delivered' ? 'bg-emerald-100 dark:bg-emerald-500/15 text-emerald-700 dark:text-emerald-400' :
                                                    'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'
                                                ]"
                                            >
                                                {{ order.status }}
                                            </span>
                                        </div>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                            {{ order.customer_name }} · {{ order.items.length }} items
                                        </p>
                                    </div>
                                    <div class="text-right ml-3">
                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                            {{ formatCurrency(order.total) }}
                                        </p>
                                        <p class="text-[11px] text-gray-400 dark:text-gray-500">
                                            {{ new Date(order.order_date).toLocaleDateString() }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Low Stock Alert -->
                    <div class="bg-white dark:bg-dark-card rounded-xl border border-gray-100 dark:border-dark-border shadow-card">
                        <div class="px-5 py-4 border-b border-gray-100 dark:border-dark-border flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {{ t('dashboard.lowStockAlert') }}
                            </h3>
                            <Link
                                :href="route('products.index')"
                                class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300"
                            >
                                {{ t('dashboard.viewAll') }}
                            </Link>
                        </div>

                        <div class="p-5">
                            <div v-if="lowStockProducts.length === 0" class="text-center py-6">
                                <div class="w-12 h-12 bg-emerald-50 dark:bg-emerald-500/10 rounded-xl flex items-center justify-center mx-auto mb-3">
                                    <svg class="w-6 h-6 text-emerald-500 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('dashboard.allWellStocked') }}</p>
                            </div>

                            <div v-else class="space-y-2.5">
                                <div
                                    v-for="product in lowStockProducts"
                                    :key="product.id"
                                    class="flex items-center justify-between p-3 bg-red-50 dark:bg-red-500/5 rounded-lg border border-red-100 dark:border-red-500/10"
                                >
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                            {{ product.name }}
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                            {{ product.category?.name }} · {{ product.location?.name }}
                                        </p>
                                    </div>
                                    <div class="text-right ml-3">
                                        <p class="text-sm font-semibold text-red-600 dark:text-red-400">
                                            {{ t('dashboard.inStock', { count: product.stock }) }}
                                        </p>
                                        <p class="text-[11px] text-gray-400 dark:text-gray-500">
                                            {{ t('dashboard.reorderAt', { count: product.min_stock }) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Products -->
                    <div class="bg-white dark:bg-dark-card rounded-xl border border-gray-100 dark:border-dark-border shadow-card">
                        <div class="px-5 py-4 border-b border-gray-100 dark:border-dark-border flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {{ t('dashboard.recentProducts') }}
                            </h3>
                            <Link
                                :href="route('products.index')"
                                class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300"
                            >
                                {{ t('dashboard.viewAll') }}
                            </Link>
                        </div>

                        <div class="p-5">
                            <div v-if="recentProducts.length === 0" class="text-center py-6">
                                <div class="w-12 h-12 bg-gray-100 dark:bg-dark-border rounded-xl flex items-center justify-center mx-auto mb-3">
                                    <svg class="w-6 h-6 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                    </svg>
                                </div>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">{{ t('dashboard.noProductsYet') }}</p>
                                <Link
                                    :href="route('products.create')"
                                    class="inline-flex items-center px-3.5 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg transition"
                                >
                                    {{ t('dashboard.addFirstProduct') }}
                                </Link>
                            </div>

                            <div v-else class="space-y-2.5">
                                <div
                                    v-for="product in recentProducts"
                                    :key="product.id"
                                    class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-800/50 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-800 transition"
                                >
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                            {{ product.name }}
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                            {{ product.category?.name }} · {{ product.location?.name }}
                                        </p>
                                    </div>
                                    <div class="text-right ml-3">
                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                            {{ formatCurrency(product.price) }}
                                        </p>
                                        <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                            {{ t('dashboard.qty', { count: product.stock }) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Plugin Slot: After Content Grid -->
                <PluginSlot slot="after-content" :components="pluginComponents?.afterContent" />

                <!-- Stock by Category -->
                <div v-if="stockByCategory.length > 0" class="mb-6 bg-white dark:bg-dark-card rounded-xl border border-gray-100 dark:border-dark-border shadow-card">
                    <div class="px-5 py-4 border-b border-gray-100 dark:border-dark-border">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                            {{ t('dashboard.stockValueByCategory') }}
                        </h3>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            <div
                                v-for="category in stockByCategory"
                                :key="category.name"
                                class="p-4 bg-gray-50 dark:bg-slate-800/50 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-800 transition"
                            >
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">
                                    {{ category.name }}
                                </p>
                                <p class="text-xl font-bold text-gray-900 dark:text-white">
                                    {{ formatCompactCurrency(category.value) }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    {{ formatNumber(category.count) }} {{ t('common.products') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="bg-white dark:bg-dark-card rounded-xl border border-gray-100 dark:border-dark-border shadow-card">
                    <div class="px-5 py-4 border-b border-gray-100 dark:border-dark-border">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                            {{ t('dashboard.quickActions') }}
                        </h3>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <Link
                                :href="route('orders.create')"
                                class="flex items-center gap-3 p-4 bg-primary-50 dark:bg-primary-500/5 rounded-xl border border-primary-100 dark:border-primary-500/10 hover:border-primary-300 dark:hover:border-primary-500/30 transition group"
                            >
                                <div class="w-10 h-10 bg-primary-100 dark:bg-primary-500/15 rounded-lg flex items-center justify-center group-hover:bg-primary-200 dark:group-hover:bg-primary-500/25 transition">
                                    <svg class="w-5 h-5 text-primary-600 dark:text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ t('dashboard.createOrder') }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('dashboard.newOrder') }}</p>
                                </div>
                            </Link>

                            <Link
                                :href="route('products.create')"
                                class="flex items-center gap-3 p-4 bg-primary-50 dark:bg-primary-500/5 rounded-xl border border-primary-100 dark:border-primary-500/10 hover:border-primary-300 dark:hover:border-primary-500/30 transition group"
                            >
                                <div class="w-10 h-10 bg-primary-100 dark:bg-primary-500/15 rounded-lg flex items-center justify-center group-hover:bg-primary-200 dark:group-hover:bg-primary-500/25 transition">
                                    <svg class="w-5 h-5 text-primary-600 dark:text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ t('dashboard.addProduct') }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('dashboard.createNewItem') }}</p>
                                </div>
                            </Link>

                            <Link
                                :href="route('products.index')"
                                class="flex items-center gap-3 p-4 bg-gray-50 dark:bg-slate-800/30 rounded-xl border border-gray-100 dark:border-dark-border hover:border-gray-300 dark:hover:border-slate-600 transition group"
                            >
                                <div class="w-10 h-10 bg-gray-100 dark:bg-slate-700/50 rounded-lg flex items-center justify-center group-hover:bg-gray-200 dark:group-hover:bg-slate-700 transition">
                                    <svg class="w-5 h-5 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ t('dashboard.viewInventory') }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('dashboard.browseAllItems') }}</p>
                                </div>
                            </Link>

                            <Link
                                :href="route('orders.index')"
                                class="flex items-center gap-3 p-4 bg-gray-50 dark:bg-slate-800/30 rounded-xl border border-gray-100 dark:border-dark-border hover:border-gray-300 dark:hover:border-slate-600 transition group"
                            >
                                <div class="w-10 h-10 bg-gray-100 dark:bg-slate-700/50 rounded-lg flex items-center justify-center group-hover:bg-gray-200 dark:group-hover:bg-slate-700 transition">
                                    <svg class="w-5 h-5 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ t('dashboard.viewOrders') }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('dashboard.allOrders') }}</p>
                                </div>
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Plugin Slot: Footer -->
                <PluginSlot slot="footer" :components="pluginComponents?.footer" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
