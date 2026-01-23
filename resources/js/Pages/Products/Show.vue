<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import ActivityTimeline from '@/Components/ActivityTimeline.vue';
import VariantsTable from '@/Components/VariantsTable.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, onMounted, computed } from 'vue';
import axios from 'axios';
import ImageGallery from '@/Components/ImageGallery.vue';

const props = defineProps({
    product: Object,
    activities: Array,
    pluginComponents: Object,
});

const barcodeImage = ref(null);
const barcodeLoading = ref(false);

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

// Load barcode on mount if product has barcode or SKU
onMounted(() => {
    if (props.product.barcode || props.product.sku) {
        loadBarcode();
    }
});

const loadBarcode = async () => {
    barcodeLoading.value = true;
    try {
        const response = await axios.get(route('products.barcode.generate', props.product.id));
        barcodeImage.value = response.data.barcode;
    } catch (error) {
        console.error('Failed to load barcode:', error);
    } finally {
        barcodeLoading.value = false;
    }
};

const printBarcode = () => {
    window.open(route('products.barcode.print', props.product.id), '_blank');
};

const generateRandomBarcode = async () => {
    if (!confirm('Generate a new random barcode for this product?')) return;

    try {
        await axios.post(route('products.barcode.generate-random', props.product.id));
        router.reload({ only: ['product'] });
        setTimeout(loadBarcode, 100);
    } catch (error) {
        console.error('Failed to generate barcode:', error);
        alert('Failed to generate barcode');
    }
};

const generateFromSKU = async () => {
    if (!confirm('Generate barcode from SKU?')) return;

    try {
        await axios.post(route('products.barcode.generate-from-sku', props.product.id));
        router.reload({ only: ['product'] });
        setTimeout(loadBarcode, 100);
    } catch (error) {
        console.error('Failed to generate barcode:', error);
        alert('Failed to generate barcode from SKU');
    }
};

// Prepare product images for gallery
const productImages = computed(() => {
    if (!props.product.images || props.product.images.length === 0) {
        return [];
    }
    return props.product.images.map(imagePath => `/storage/${imagePath}`);
});

// Variants
const variants = ref(props.product.variants || []);

const getCurrencySymbol = () => {
    const symbols = { USD: '$', EUR: '\u20AC', GBP: '\u00A3', JPY: '\u00A5' };
    return symbols[props.product.currency] || '$';
};

const onVariantUpdated = (updatedVariant) => {
    const index = variants.value.findIndex(v => v.id === updatedVariant.id);
    if (index !== -1) {
        variants.value[index] = updatedVariant;
    }
};

const totalVariantStock = computed(() => {
    return variants.value.reduce((sum, v) => sum + (v.stock || 0), 0);
});
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
                <!-- Plugin Slot: Header -->
                <PluginSlot slot="header" :components="pluginComponents?.header" />

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

                        <!-- Product Variants -->
                        <div v-if="product.has_variants && variants.length > 0" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        Product Variants
                                    </h3>
                                    <span class="px-2 py-1 text-xs bg-primary-400/20 text-primary-400 rounded-full">
                                        {{ variants.length }} variants
                                    </span>
                                </div>

                                <VariantsTable
                                    :variants="variants"
                                    :product-id="product.id"
                                    :currency-symbol="getCurrencySymbol()"
                                    :show-stock-adjust="true"
                                    @variant-updated="onVariantUpdated"
                                />

                                <!-- Variant Stock Note -->
                                <div class="mt-4 p-3 bg-blue-900/20 rounded-lg border border-blue-800">
                                    <p class="text-sm text-blue-300">
                                        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Stock is tracked per variant. Total variant stock: <span class="font-semibold">{{ totalVariantStock }}</span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6">
                        <!-- Plugin Slot: Sidebar -->
                        <PluginSlot slot="sidebar" :components="pluginComponents?.sidebar" />

                        <!-- Product Images -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                    Product Images
                                </h3>
                                <ImageGallery
                                    :images="productImages"
                                    :product-name="product.name"
                                />
                            </div>
                        </div>

                        <!-- Barcode -->
                        <div v-if="product.barcode || product.sku" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                    Barcode
                                </h3>

                                <div v-if="barcodeLoading" class="flex items-center justify-center py-8">
                                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-400"></div>
                                </div>

                                <div v-else-if="barcodeImage" class="space-y-4">
                                    <div class="flex justify-center p-4 bg-white rounded-lg border-2 border-gray-200 dark:border-dark-border">
                                        <img :src="barcodeImage" alt="Barcode" class="max-w-full h-auto" />
                                    </div>

                                    <div class="text-center">
                                        <p class="text-sm font-mono text-gray-600 dark:text-gray-400">
                                            {{ product.barcode || product.sku }}
                                        </p>
                                    </div>

                                    <div class="flex gap-2">
                                        <button
                                            @click="printBarcode"
                                            class="flex-1 px-3 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm rounded-lg font-medium transition"
                                        >
                                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                            </svg>
                                            Print
                                        </button>
                                    </div>

                                    <div class="pt-3 border-t border-gray-200 dark:border-dark-border space-y-2">
                                        <button
                                            @click="generateRandomBarcode"
                                            class="w-full px-3 py-2 bg-gray-100 dark:bg-dark-bg hover:bg-gray-200 dark:hover:bg-dark-bg/80 text-gray-700 dark:text-gray-300 text-sm rounded-lg font-medium border border-gray-200 dark:border-dark-border transition"
                                        >
                                            Generate New Random
                                        </button>
                                        <button
                                            @click="generateFromSKU"
                                            class="w-full px-3 py-2 bg-gray-100 dark:bg-dark-bg hover:bg-gray-200 dark:hover:bg-dark-bg/80 text-gray-700 dark:text-gray-300 text-sm rounded-lg font-medium border border-gray-200 dark:border-dark-border transition"
                                        >
                                            Generate from SKU
                                        </button>
                                    </div>
                                </div>

                                <div v-else class="text-center py-4">
                                    <p class="text-gray-500 dark:text-gray-400 text-sm mb-3">No barcode available</p>
                                    <button
                                        @click="generateRandomBarcode"
                                        class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm rounded-lg font-medium transition"
                                    >
                                        Generate Barcode
                                    </button>
                                </div>
                            </div>
                        </div>

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

                <!-- Activity Timeline -->
                <div class="mt-6 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                            Activity History
                        </h3>
                        <ActivityTimeline :activities="activities || []" />
                    </div>
                </div>

                <!-- Plugin Slot: Footer -->
                <PluginSlot slot="footer" :components="pluginComponents?.footer" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
