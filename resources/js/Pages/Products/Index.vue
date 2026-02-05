<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import BarcodeScannerModal from '@/Components/BarcodeScannerModal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    products: Object,
    filters: Object,
    categories: Array,
    locations: Array,
    pluginComponents: Object,
});

const search = ref(props.filters?.search || '');
const category = ref(props.filters?.category || '');
const location = ref(props.filters?.location || '');

// Bulk selection for barcode printing
const selectedProducts = ref([]);
const selectAll = ref(false);

// Barcode scanner state
const showScannerModal = ref(false);

const openScanner = () => {
    showScannerModal.value = true;
};

const closeScanner = () => {
    showScannerModal.value = false;
};

const handleProductFound = (product) => {
    router.visit(route('products.show', product.id));
};

// Keyboard shortcut: Ctrl+B to open scanner
const handleKeydown = (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
        e.preventDefault();
        showScannerModal.value = !showScannerModal.value;
    }
};

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
});

const toggleSelectAll = () => {
    if (selectAll.value) {
        selectedProducts.value = props.products.data
            .filter(p => p.barcode || p.sku)
            .map(p => p.id);
    } else {
        selectedProducts.value = [];
    }
};

const isSelected = (productId) => {
    return selectedProducts.value.includes(productId);
};

const toggleSelect = (productId) => {
    const idx = selectedProducts.value.indexOf(productId);
    if (idx > -1) {
        selectedProducts.value.splice(idx, 1);
    } else {
        selectedProducts.value.push(productId);
    }
    // Update selectAll state
    const selectableProducts = props.products.data.filter(p => p.barcode || p.sku);
    selectAll.value = selectableProducts.length > 0 && selectedProducts.value.length === selectableProducts.length;
};

const printSelectedBarcodes = () => {
    if (selectedProducts.value.length === 0) return;
    const ids = selectedProducts.value.join(',');
    window.open(route('products.barcode.bulk-print', { ids }), '_blank');
};

const printBarcode = (productId) => {
    window.open(route('products.barcode.print', productId), '_blank');
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};

const searchProducts = () => {
    router.get(route('products.index'), {
        search: search.value,
        category: category.value,
        location: location.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    category.value = '';
    location.value = '';
    searchProducts();
};

const deleteProduct = (product) => {
    if (confirm(`Are you sure you want to delete "${product.name}"?`)) {
        router.delete(route('products.destroy', product.id));
    }
};

const isLowStock = (product) => {
    return product.stock <= product.min_stock;
};
</script>

<template>
    <Head title="Inventory" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    Inventory
                </h2>
                <Link
                    :href="route('products.create')"
                    class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 focus:bg-primary-500 active:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-400 focus:ring-offset-2 focus:ring-offset-dark-bg transition ease-in-out duration-150"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Add Product
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Plugin Slot: Header -->
                <PluginSlot slot="header" :components="pluginComponents?.header" />

                <!-- Search and Filters -->
                <div class="mb-6 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <div class="p-6">
                        <form @submit.prevent="searchProducts" class="space-y-4">
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                                <!-- Search -->
                                <div class="md:col-span-2">
                                    <label for="search" class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                                        Search Products
                                    </label>
                                    <input
                                        id="search"
                                        v-model="search"
                                        type="text"
                                        placeholder="Search by name, SKU, or barcode..."
                                        class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    />
                                </div>

                                <!-- Category Filter -->
                                <div>
                                    <label for="category" class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                                        Category
                                    </label>
                                    <select
                                        id="category"
                                        v-model="category"
                                        class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    >
                                        <option value="">All Categories</option>
                                        <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                                            {{ cat.name }}
                                        </option>
                                    </select>
                                </div>

                                <!-- Location Filter -->
                                <div>
                                    <label for="location" class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                                        Location
                                    </label>
                                    <select
                                        id="location"
                                        v-model="location"
                                        class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    >
                                        <option value="">All Locations</option>
                                        <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                                            {{ loc.name }}
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <!-- Filter Actions -->
                            <div class="flex items-center gap-3">
                                <button
                                    type="submit"
                                    class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                    Search
                                </button>
                                <button
                                    type="button"
                                    @click="clearFilters"
                                    class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                                >
                                    Clear Filters
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Plugin Slot: Before Table -->
                <PluginSlot slot="before-table" :components="pluginComponents?.beforeTable" />

                <!-- Bulk Actions Bar -->
                <div v-if="selectedProducts.length > 0" class="mb-4 p-4 bg-primary-900/20 border border-primary-800 rounded-lg flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-medium text-gray-100">
                            {{ selectedProducts.length }} product{{ selectedProducts.length > 1 ? 's' : '' }} selected
                        </span>
                        <button
                            @click="selectedProducts = []; selectAll = false"
                            class="text-xs text-gray-400 hover:text-gray-200"
                        >
                            Clear selection
                        </button>
                    </div>
                    <button
                        @click="printSelectedBarcodes"
                        class="inline-flex items-center px-4 py-2 bg-primary-400 text-white rounded-lg text-sm font-medium hover:bg-primary-500 transition"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                        Print Barcodes
                    </button>
                </div>

                <!-- Products Table -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                            <thead class="bg-gray-50 dark:bg-dark-bg/50">
                                <tr>
                                    <th scope="col" class="px-3 py-3 w-10">
                                        <input
                                            type="checkbox"
                                            v-model="selectAll"
                                            @change="toggleSelectAll"
                                            class="rounded border-gray-300 dark:border-dark-border text-primary-400 focus:ring-primary-400 bg-gray-50 dark:bg-dark-bg"
                                        />
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Product
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        SKU / Barcode
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Category
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Location
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Stock
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Price
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-dark-card divide-y divide-gray-200 dark:divide-dark-border">
                                <tr v-if="products.data.length === 0">
                                    <td colspan="8" class="px-6 py-12 text-center">
                                        <svg class="w-12 h-12 text-gray-500 dark:text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                        </svg>
                                        <p class="text-gray-500 dark:text-gray-400 mb-3">No products found</p>
                                        <Link
                                            :href="route('products.create')"
                                            class="inline-flex items-center px-4 py-2 bg-primary-400 text-white text-sm font-semibold rounded-lg hover:bg-primary-500 transition"
                                        >
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                            </svg>
                                            Add Your First Product
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-for="product in products.data" :key="product.id" class="hover:bg-gray-100 dark:hover:bg-dark-bg/50">
                                    <td class="px-3 py-4">
                                        <input
                                            v-if="product.barcode || product.sku"
                                            type="checkbox"
                                            :checked="isSelected(product.id)"
                                            @change="toggleSelect(product.id)"
                                            class="rounded border-gray-300 dark:border-dark-border text-primary-400 focus:ring-primary-400 bg-gray-50 dark:bg-dark-bg"
                                        />
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div>
                                                <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                                    {{ product.name }}
                                                </div>
                                                <div v-if="product.description" class="text-sm text-gray-500 dark:text-gray-400 truncate max-w-xs">
                                                    {{ product.description }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-gray-100">{{ product.sku }}</div>
                                        <div v-if="product.barcode" class="text-sm text-gray-500 dark:text-gray-400">{{ product.barcode }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300">
                                            {{ product.category?.name || 'N/A' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-orange-100 dark:bg-orange-900/30 text-orange-800 dark:text-orange-300">
                                            {{ product.location?.name || 'N/A' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <span
                                                :class="[
                                                    'text-sm font-medium',
                                                    isLowStock(product)
                                                        ? 'text-red-400'
                                                        : 'text-gray-900 dark:text-gray-100'
                                                ]"
                                            >
                                                {{ product.stock }}
                                            </span>
                                            <svg
                                                v-if="isLowStock(product)"
                                                class="w-4 h-4 text-red-400 ml-1"
                                                fill="currentColor"
                                                viewBox="0 0 20 20"
                                            >
                                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            Min: {{ product.min_stock }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                        {{ formatCurrency(product.price) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex items-center justify-end gap-2">
                                            <Link
                                                :href="route('products.show', product.id)"
                                                class="text-primary-400 hover:text-primary-300"
                                                title="View"
                                            >
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </svg>
                                            </Link>
                                            <button
                                                v-if="product.barcode || product.sku"
                                                @click="printBarcode(product.id)"
                                                class="text-gray-400 hover:text-gray-300"
                                                title="Print Barcode"
                                            >
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                                                </svg>
                                            </button>
                                            <Link
                                                :href="route('products.edit', product.id)"
                                                class="text-green-400 hover:text-green-300"
                                                title="Edit"
                                            >
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </Link>
                                            <button
                                                @click="deleteProduct(product)"
                                                class="text-red-400 hover:text-red-300"
                                                title="Delete"
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
                    <div v-if="products.data.length > 0" class="bg-white dark:bg-dark-card px-4 py-3 border-t border-gray-200 dark:border-dark-border sm:px-6">
                        <div class="flex items-center justify-between">
                            <div class="flex-1 flex justify-between sm:hidden">
                                <Link
                                    v-if="products.prev_page_url"
                                    :href="products.prev_page_url"
                                    class="relative inline-flex items-center px-4 py-2 border border-primary-300 dark:border-dark-border text-sm font-semibold rounded-md text-primary-600 dark:text-gray-300 bg-white dark:bg-dark-card hover:bg-primary-50 dark:hover:bg-dark-bg/50 transition"
                                >
                                    Previous
                                </Link>
                                <Link
                                    v-if="products.next_page_url"
                                    :href="products.next_page_url"
                                    class="ml-3 relative inline-flex items-center px-4 py-2 border border-primary-300 dark:border-dark-border text-sm font-semibold rounded-md text-primary-600 dark:text-gray-300 bg-white dark:bg-dark-card hover:bg-primary-50 dark:hover:bg-dark-bg/50 transition"
                                >
                                    Next
                                </Link>
                            </div>
                            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm text-gray-600 dark:text-gray-300">
                                        Showing
                                        <span class="font-medium">{{ products.from }}</span>
                                        to
                                        <span class="font-medium">{{ products.to }}</span>
                                        of
                                        <span class="font-medium">{{ products.total }}</span>
                                        results
                                    </p>
                                </div>
                                <div>
                                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                        <template v-for="link in products.links" :key="link.label">
                                            <Link
                                                v-if="link.url"
                                                :href="link.url"
                                                :class="[
                                                    'relative inline-flex items-center px-4 py-2 border text-sm font-medium',
                                                    link.active
                                                        ? 'z-10 bg-primary-100 dark:bg-primary-900/30 border-primary-400 text-primary-600 dark:text-primary-400'
                                                        : 'bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-dark-bg/50'
                                                ]"
                                                v-html="link.label"
                                            />
                                            <span
                                                v-else
                                                :class="[
                                                    'relative inline-flex items-center px-4 py-2 border text-sm font-medium',
                                                    'bg-gray-100 dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-400 dark:text-gray-500 opacity-50 cursor-not-allowed'
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

                <!-- Plugin Slot: Footer -->
                <PluginSlot slot="footer" :components="pluginComponents?.footer" />
            </div>
        </div>

        <!-- Floating Barcode Scan Button -->
        <button
            v-if="$page.props.auth.permissions.includes('products.view')"
            @click="openScanner"
            class="fixed bottom-6 right-6 w-14 h-14 bg-primary-400 hover:bg-primary-500 text-white rounded-full shadow-lg hover:shadow-xl transition-all duration-200 hover:scale-110 flex items-center justify-center z-40"
            title="Scan Barcode (Ctrl+B)"
        >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
            </svg>
        </button>

        <!-- Barcode Scanner Modal -->
        <BarcodeScannerModal
            :show="showScannerModal"
            @close="closeScanner"
            @product-found="handleProductFound"
        />
    </AuthenticatedLayout>
</template>
