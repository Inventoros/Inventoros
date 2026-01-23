<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import ProductVariantManager from '@/Components/ProductVariantManager.vue';
import QuickAddModal from '@/Components/QuickAddModal.vue';
import SKUGeneratorModal from '@/Components/SKUGeneratorModal.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';
import ImageUploader from '@/Components/ImageUploader.vue';

const props = defineProps({
    categories: Array,
    locations: Array,
    currencies: Object,
    defaultCurrency: String,
    pluginComponents: Object,
});

const form = useForm({
    name: '',
    description: '',
    sku: '',
    price: '',
    purchase_price: '',
    currency: props.defaultCurrency || 'USD',
    price_in_currencies: [],
    stock: '',
    min_stock: '',
    category_id: '',
    location_id: '',
    barcode: '',
    notes: '',
    images: [],
    has_variants: false,
    options: [],
    variants: [],
});

// Variant management
const showVariantSection = ref(false);
const variantData = ref({ options: [], variants: [] });

const toggleVariants = () => {
    showVariantSection.value = !showVariantSection.value;
    form.has_variants = showVariantSection.value;
};

const updateVariantData = (data) => {
    variantData.value = data;
    form.options = data.options;
    form.variants = data.variants;
};

// Multi-currency management
const additionalCurrencies = ref([]);
const showCurrencySelect = ref(false);
const selectedNewCurrency = ref('');

const availableCurrencies = computed(() => {
    const used = [form.currency, ...additionalCurrencies.value.map(c => c.currency)];
    return Object.entries(props.currencies || {})
        .filter(([code]) => !used.includes(code))
        .map(([code, data]) => ({ code, ...data }));
});

const getCurrencySymbol = (code) => {
    return props.currencies[code]?.symbol || code;
};

const addCurrency = () => {
    if (selectedNewCurrency.value) {
        additionalCurrencies.value.push({
            currency: selectedNewCurrency.value,
            price: '',
        });
        selectedNewCurrency.value = '';
        showCurrencySelect.value = false;
    }
};

const removeCurrency = (index) => {
    additionalCurrencies.value.splice(index, 1);
};

// Quick-add modals
const showCategoryModal = ref(false);
const showLocationModal = ref(false);
const categoryForm = ref({ name: '', description: '' });
const locationForm = ref({ name: '', code: '', description: '' });
const categoryLoading = ref(false);
const locationLoading = ref(false);

// SKU Generator
const showSKUGenerator = ref(false);

const applySKUFromModal = (sku) => {
    form.sku = sku;
};

const createCategory = async () => {
    categoryLoading.value = true;
    try {
        const response = await axios.post(route('categories.store'), categoryForm.value, {
            headers: { 'Accept': 'application/json' }
        });

        if (response.data.success) {
            // Refresh the page to get updated categories
            router.reload({ only: ['categories'] });
            form.category_id = response.data.category.id;
            showCategoryModal.value = false;
            categoryForm.value = { name: '', description: '' };
        }
    } catch (error) {
        console.error('Error creating category:', error);
        alert('Failed to create category. Please try again.');
    } finally {
        categoryLoading.value = false;
    }
};

const createLocation = async () => {
    locationLoading.value = true;
    try {
        const response = await axios.post(route('locations.store'), locationForm.value, {
            headers: { 'Accept': 'application/json' }
        });

        if (response.data.success) {
            // Refresh the page to get updated locations
            router.reload({ only: ['locations'] });
            form.location_id = response.data.location.id;
            showLocationModal.value = false;
            locationForm.value = { name: '', code: '', description: '' };
        }
    } catch (error) {
        console.error('Error creating location:', error);
        alert('Failed to create location. Please try again.');
    } finally {
        locationLoading.value = false;
    }
};

const submit = () => {
    // Add additional currencies to form data
    form.price_in_currencies = additionalCurrencies.value.filter(c => c.price);

    form.post(route('products.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Add Product" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    Add Product
                </h2>
                <Link
                    :href="route('products.index')"
                    class="inline-flex items-center px-4 py-2 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Inventory
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Plugin Slot: Header -->
                <PluginSlot slot="header" :components="pluginComponents?.header" />

                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <div class="p-6">
                        <!-- Plugin Slot: Before Form -->
                        <PluginSlot slot="before-form" :components="pluginComponents?.beforeForm" />

                        <form @submit.prevent="submit">
                            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                                <!-- Basic Information -->
                                <div class="space-y-6">
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                            Basic Information
                                        </h3>
                                    </div>

                                    <!-- Product Name -->
                                    <div>
                                        <label for="name" class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                            Product Name <span class="text-red-500">*</span>
                                        </label>
                                        <input
                                            id="name"
                                            v-model="form.name"
                                            type="text"
                                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                            required
                                        />
                                        <p v-if="form.errors.name" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.name }}
                                        </p>
                                    </div>

                                    <!-- SKU -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <label for="sku" class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                                SKU <span class="text-red-500">*</span>
                                            </label>
                                            <button
                                                type="button"
                                                @click="showSKUGenerator = true"
                                                class="text-xs text-primary-400 hover:text-primary-300 font-medium flex items-center gap-1"
                                            >
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                                </svg>
                                                Generate SKU
                                            </button>
                                        </div>
                                        <input
                                            id="sku"
                                            v-model="form.sku"
                                            type="text"
                                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                            required
                                        />
                                        <p v-if="form.errors.sku" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.sku }}
                                        </p>
                                    </div>

                                    <!-- Barcode -->
                                    <div>
                                        <label for="barcode" class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                            Barcode
                                        </label>
                                        <input
                                            id="barcode"
                                            v-model="form.barcode"
                                            type="text"
                                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                        />
                                        <p v-if="form.errors.barcode" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.barcode }}
                                        </p>
                                    </div>

                                    <!-- Description -->
                                    <div>
                                        <label for="description" class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                            Description
                                        </label>
                                        <textarea
                                            id="description"
                                            v-model="form.description"
                                            rows="4"
                                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                        ></textarea>
                                        <p v-if="form.errors.description" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.description }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Pricing & Inventory -->
                                <div class="space-y-6">
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                            Pricing & Inventory
                                        </h3>
                                    </div>

                                    <!-- Currency Selection -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">
                                            Currency <span class="text-red-500">*</span>
                                        </label>
                                        <select
                                            v-model="form.currency"
                                            class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                            required
                                        >
                                            <option v-for="(data, code) in currencies" :key="code" :value="code">
                                                {{ code }} ({{ data.symbol }}) - {{ data.name }}
                                            </option>
                                        </select>
                                    </div>

                                    <!-- Purchase Price (Cost) -->
                                    <div>
                                        <label for="purchase_price" class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">
                                            Purchase Price (Cost)
                                        </label>
                                        <div class="relative rounded-md shadow-sm">
                                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                <span class="text-gray-500 dark:text-gray-400 sm:text-sm">{{ getCurrencySymbol(form.currency) }}</span>
                                            </div>
                                            <input
                                                id="purchase_price"
                                                v-model="form.purchase_price"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="pl-10 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                            />
                                        </div>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            What you paid for this item
                                        </p>
                                        <p v-if="form.errors.purchase_price" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.purchase_price }}
                                        </p>
                                    </div>

                                    <!-- Selling Price -->
                                    <div>
                                        <label for="price" class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">
                                            Selling Price <span class="text-red-500">*</span>
                                        </label>
                                        <div class="relative rounded-md shadow-sm">
                                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                <span class="text-gray-500 dark:text-gray-400 sm:text-sm">{{ getCurrencySymbol(form.currency) }}</span>
                                            </div>
                                            <input
                                                id="price"
                                                v-model="form.price"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="pl-10 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                                required
                                            />
                                        </div>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            What you sell this item for
                                        </p>
                                        <p v-if="form.errors.price" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.price }}
                                        </p>
                                    </div>

                                    <!-- Profit Indicator -->
                                    <div v-if="form.price && form.purchase_price" class="p-4 bg-green-900/20 rounded-lg border border-green-800">
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm font-medium text-green-300">Profit per unit:</span>
                                            <span class="text-lg font-bold text-green-400">
                                                {{ getCurrencySymbol(form.currency) }}{{ (parseFloat(form.price) - parseFloat(form.purchase_price)).toFixed(2) }}
                                            </span>
                                        </div>
                                        <div class="flex items-center justify-between mt-1">
                                            <span class="text-xs text-green-400">Margin:</span>
                                            <span class="text-sm font-semibold text-green-400">
                                                {{ ((parseFloat(form.price) - parseFloat(form.purchase_price)) / parseFloat(form.price) * 100).toFixed(1) }}%
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Additional Currencies -->
                                    <div v-if="additionalCurrencies.length > 0 || showCurrencySelect" class="space-y-2">
                                        <label class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                            Additional Currencies
                                        </label>

                                        <div v-for="(currencyPrice, index) in additionalCurrencies" :key="index" class="grid grid-cols-3 gap-2">
                                            <select
                                                v-model="currencyPrice.currency"
                                                class="col-span-1 rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                                disabled
                                            >
                                                <option :value="currencyPrice.currency">{{ currencyPrice.currency }} ({{ getCurrencySymbol(currencyPrice.currency) }})</option>
                                            </select>

                                            <div class="col-span-2 flex gap-2">
                                                <div class="flex-1 relative rounded-md shadow-sm">
                                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                        <span class="text-gray-500 dark:text-gray-400 sm:text-sm">{{ getCurrencySymbol(currencyPrice.currency) }}</span>
                                                    </div>
                                                    <input
                                                        v-model="currencyPrice.price"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        class="pl-10 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                                    />
                                                </div>
                                                <button
                                                    type="button"
                                                    @click="removeCurrency(index)"
                                                    class="px-3 py-2 bg-red-900/30 text-red-400 rounded-md hover:bg-red-900/50"
                                                >
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </div>

                                        <div v-if="showCurrencySelect" class="flex gap-2">
                                            <select
                                                v-model="selectedNewCurrency"
                                                class="flex-1 rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                            >
                                                <option value="">Select currency...</option>
                                                <option v-for="curr in availableCurrencies" :key="curr.code" :value="curr.code">
                                                    {{ curr.code }} ({{ curr.symbol }}) - {{ curr.name }}
                                                </option>
                                            </select>
                                            <button
                                                type="button"
                                                @click="addCurrency"
                                                class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700"
                                            >
                                                Add
                                            </button>
                                            <button
                                                type="button"
                                                @click="showCurrencySelect = false"
                                                class="px-4 py-2 bg-dark-bg text-gray-600 dark:text-gray-300 rounded-md hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </div>

                                    <button
                                        v-if="!showCurrencySelect && availableCurrencies.length > 0"
                                        type="button"
                                        @click="showCurrencySelect = true"
                                        class="text-sm text-primary-400 hover:text-primary-300 font-medium"
                                    >
                                        + Add price in another currency
                                    </button>

                                    <!-- Stock Quantity -->
                                    <div>
                                        <label for="stock" class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                            Current Stock <span class="text-red-500">*</span>
                                        </label>
                                        <input
                                            id="stock"
                                            v-model="form.stock"
                                            type="number"
                                            min="0"
                                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                            required
                                        />
                                        <p v-if="form.errors.stock" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.stock }}
                                        </p>
                                    </div>

                                    <!-- Minimum Stock -->
                                    <div>
                                        <label for="min_stock" class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                            Minimum Stock Level <span class="text-red-500">*</span>
                                        </label>
                                        <input
                                            id="min_stock"
                                            v-model="form.min_stock"
                                            type="number"
                                            min="0"
                                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                            required
                                        />
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                            Alert when stock falls below this level
                                        </p>
                                        <p v-if="form.errors.min_stock" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.min_stock }}
                                        </p>
                                    </div>

                                    <!-- Category -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <label for="category" class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                                Category <span class="text-red-500">*</span>
                                            </label>
                                            <button
                                                type="button"
                                                @click="showCategoryModal = true"
                                                class="text-xs text-primary-400 hover:text-primary-300 font-medium"
                                            >
                                                + Quick Add
                                            </button>
                                        </div>
                                        <select
                                            id="category"
                                            v-model="form.category_id"
                                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                            required
                                        >
                                            <option value="">Select a category</option>
                                            <option v-for="category in categories" :key="category.id" :value="category.id">
                                                {{ category.name }}
                                            </option>
                                        </select>
                                        <p v-if="form.errors.category_id" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.category_id }}
                                        </p>
                                    </div>

                                    <!-- Location -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <label for="location" class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                                Location <span class="text-red-500">*</span>
                                            </label>
                                            <button
                                                type="button"
                                                @click="showLocationModal = true"
                                                class="text-xs text-primary-400 hover:text-primary-300 font-medium"
                                            >
                                                + Quick Add
                                            </button>
                                        </div>
                                        <select
                                            id="location"
                                            v-model="form.location_id"
                                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                            required
                                        >
                                            <option value="">Select a location</option>
                                            <option v-for="location in locations" :key="location.id" :value="location.id">
                                                {{ location.name }}
                                            </option>
                                        </select>
                                        <p v-if="form.errors.location_id" class="mt-1 text-sm text-red-400">
                                            {{ form.errors.location_id }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Product Images (Full Width) -->
                                <div class="lg:col-span-2">
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                        Product Images
                                    </h3>
                                    <ImageUploader
                                        v-model="form.images"
                                        :max-images="5"
                                        :max-size-in-m-b="5"
                                    />
                                    <p v-if="form.errors.images" class="mt-2 text-sm text-red-400">
                                        {{ form.errors.images }}
                                    </p>
                                </div>

                                <!-- Product Variants Section (Full Width) -->
                                <div class="lg:col-span-2">
                                    <div class="border border-gray-200 dark:border-dark-border rounded-lg overflow-hidden">
                                        <button
                                            type="button"
                                            @click="toggleVariants"
                                            class="w-full flex items-center justify-between p-4 bg-gray-50 dark:bg-dark-bg hover:bg-gray-100 dark:hover:bg-dark-border/50 transition"
                                        >
                                            <div class="flex items-center gap-3">
                                                <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                                </svg>
                                                <span class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                                    Product Variants
                                                </span>
                                                <span v-if="form.variants.length > 0" class="px-2 py-1 text-xs bg-primary-400/20 text-primary-400 rounded-full">
                                                    {{ form.variants.length }} variants
                                                </span>
                                            </div>
                                            <svg
                                                :class="['w-5 h-5 text-gray-500 transition-transform', showVariantSection ? 'rotate-180' : '']"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </button>

                                        <div v-if="showVariantSection" class="p-4 border-t border-gray-200 dark:border-dark-border">
                                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                                                Add options like Size or Color to sell different versions of this product.
                                                Each combination creates a variant with its own price and stock.
                                            </p>
                                            <ProductVariantManager
                                                :model-value="variantData"
                                                @update:model-value="updateVariantData"
                                                :product-price="form.price"
                                                :product-purchase-price="form.purchase_price"
                                                :currency-symbol="getCurrencySymbol(form.currency)"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <!-- Notes (Full Width) -->
                                <div class="lg:col-span-2">
                                    <label for="notes" class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                        Notes
                                    </label>
                                    <textarea
                                        id="notes"
                                        v-model="form.notes"
                                        rows="3"
                                        class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                        placeholder="Additional notes about this product..."
                                    ></textarea>
                                    <p v-if="form.errors.notes" class="mt-1 text-sm text-red-400">
                                        {{ form.errors.notes }}
                                    </p>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="mt-6 flex items-center justify-end gap-4">
                                <Link
                                    :href="route('products.index')"
                                    class="inline-flex items-center px-4 py-2 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                                >
                                    Cancel
                                </Link>
                                <button
                                    type="submit"
                                    class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 focus:bg-primary-500 active:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-400 focus:ring-offset-2 focus:ring-offset-dark-bg transition ease-in-out duration-150"
                                    :class="{ 'opacity-25': form.processing }"
                                    :disabled="form.processing"
                                >
                                    <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Create Product
                                </button>
                            </div>
                        </form>

                        <!-- Plugin Slot: After Form -->
                        <PluginSlot slot="after-form" :components="pluginComponents?.afterForm" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Category Quick-Add Modal -->
        <QuickAddModal
            :show="showCategoryModal"
            title="Quick Add Category"
            :loading="categoryLoading"
            @close="showCategoryModal = false"
        >
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                    Category Name <span class="text-red-500">*</span>
                </label>
                <input
                    v-model="categoryForm.name"
                    type="text"
                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                    placeholder="e.g., Electronics"
                    required
                />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                    Description
                </label>
                <textarea
                    v-model="categoryForm.description"
                    rows="3"
                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                    placeholder="Optional description..."
                ></textarea>
            </div>
            <template #actions>
                <button
                    type="button"
                    @click="showCategoryModal = false"
                    class="px-4 py-2 bg-dark-bg text-gray-600 dark:text-gray-300 rounded-md hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    @click="createCategory"
                    :disabled="categoryLoading || !categoryForm.name"
                    class="px-4 py-2 bg-primary-400 text-white rounded-md hover:bg-primary-500 disabled:opacity-50"
                >
                    <span v-if="categoryLoading">Creating...</span>
                    <span v-else>Create Category</span>
                </button>
            </template>
        </QuickAddModal>

        <!-- Location Quick-Add Modal -->
        <QuickAddModal
            :show="showLocationModal"
            title="Quick Add Location"
            :loading="locationLoading"
            @close="showLocationModal = false"
        >
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                    Location Name <span class="text-red-500">*</span>
                </label>
                <input
                    v-model="locationForm.name"
                    type="text"
                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                    placeholder="e.g., Warehouse A"
                    required
                />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                    Location Code <span class="text-red-500">*</span>
                </label>
                <input
                    v-model="locationForm.code"
                    type="text"
                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                    placeholder="e.g., WH-A"
                    required
                />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                    Description
                </label>
                <textarea
                    v-model="locationForm.description"
                    rows="3"
                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                    placeholder="Optional description..."
                ></textarea>
            </div>
            <template #actions>
                <button
                    type="button"
                    @click="showLocationModal = false"
                    class="px-4 py-2 bg-dark-bg text-gray-600 dark:text-gray-300 rounded-md hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    @click="createLocation"
                    :disabled="locationLoading || !locationForm.name || !locationForm.code"
                    class="px-4 py-2 bg-primary-400 text-white rounded-md hover:bg-primary-500 disabled:opacity-50"
                >
                    <span v-if="locationLoading">Creating...</span>
                    <span v-else>Create Location</span>
                </button>
            </template>
        </QuickAddModal>

        <!-- SKU Generator Modal -->
        <SKUGeneratorModal
            :show="showSKUGenerator"
            :product-name="form.name"
            :category-id="form.category_id"
            @apply="applySKUFromModal"
            @close="showSKUGenerator = false"
        />
    </AuthenticatedLayout>
</template>
