<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';
import ImageUploader from '@/Components/ImageUploader.vue';

const props = defineProps({
    product: Object,
    categories: Array,
    locations: Array,
    pluginComponents: Object,
});

const form = useForm({
    name: props.product.name,
    description: props.product.description,
    sku: props.product.sku,
    price: props.product.price,
    purchase_price: props.product.purchase_price || '',
    stock: props.product.stock,
    min_stock: props.product.min_stock,
    category_id: props.product.category_id,
    location_id: props.product.location_id,
    barcode: props.product.barcode || '',
    notes: props.product.notes || '',
    images: (props.product.images || []).map(url => ({
        url: `/storage/${url}`,
        preview: `/storage/${url}`,
        name: url.split('/').pop(),
        size: 0 // Existing images don't have size info
    })),
});

// Quick-add modals
const showCategoryModal = ref(false);
const showLocationModal = ref(false);
const categoryForm = ref({ name: '', description: '' });
const locationForm = ref({ name: '', code: '', description: '' });
const categoryLoading = ref(false);
const locationLoading = ref(false);

// SKU Generator
const showSKUGenerator = ref(false);
const skuPatterns = ref({ variables: [], presets: [] });
const selectedPattern = ref('');
const customPattern = ref('');
const skuPreview = ref('');
const skuGenerating = ref(false);

// Load SKU patterns
const loadSKUPatterns = async () => {
    try {
        const response = await axios.get(route('sku.patterns'));
        skuPatterns.value = response.data;
    } catch (error) {
        console.error('Error loading SKU patterns:', error);
    }
};

// Generate SKU preview
const generateSKUPreview = async (pattern) => {
    if (!pattern) {
        skuPreview.value = '';
        return;
    }

    skuGenerating.value = true;
    try {
        const response = await axios.post(route('sku.generate'), {
            pattern: pattern,
            product_name: form.name || null,
            category_id: form.category_id || null,
        });
        skuPreview.value = response.data.sku;
    } catch (error) {
        console.error('Error generating SKU:', error);
        skuPreview.value = 'Error generating preview';
    } finally {
        skuGenerating.value = false;
    }
};

// Apply generated SKU
const applySKU = () => {
    if (skuPreview.value) {
        form.sku = skuPreview.value;
        showSKUGenerator.value = false;
        selectedPattern.value = '';
        customPattern.value = '';
        skuPreview.value = '';
    }
};

const createCategory = async () => {
    categoryLoading.value = true;
    try {
        const response = await axios.post(route('categories.store'), categoryForm.value, {
            headers: { 'Accept': 'application/json' }
        });

        if (response.data.success) {
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
    form.put(route('products.update', props.product.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`Edit ${product.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    Edit Product
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
                                                @click="showSKUGenerator = true; loadSKUPatterns()"
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

                                    <!-- Purchase Price (Cost) -->
                                    <div>
                                        <label for="purchase_price" class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">
                                            Purchase Price (Cost)
                                        </label>
                                        <div class="relative rounded-md shadow-sm">
                                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                <span class="text-gray-500 dark:text-gray-400 sm:text-sm">$</span>
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
                                                <span class="text-gray-500 dark:text-gray-400 sm:text-sm">$</span>
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
                                                ${{ (parseFloat(form.price) - parseFloat(form.purchase_price)).toFixed(2) }}
                                            </span>
                                        </div>
                                        <div class="flex items-center justify-between mt-1">
                                            <span class="text-xs text-green-400">Margin:</span>
                                            <span class="text-sm font-semibold text-green-400">
                                                {{ ((parseFloat(form.price) - parseFloat(form.purchase_price)) / parseFloat(form.price) * 100).toFixed(1) }}%
                                            </span>
                                        </div>
                                    </div>

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
                                    Update Product
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
        <div v-if="showCategoryModal" class="fixed inset-0 z-50 overflow-y-auto" @click="showCategoryModal = false">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>

                <div class="relative bg-white dark:bg-dark-card rounded-lg shadow-xl max-w-md w-full p-6" @click.stop>
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            Quick Add Category
                        </h3>
                        <button
                            @click="showCategoryModal = false"
                            class="text-gray-500 dark:text-gray-400 hover:text-gray-200"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="space-y-4">
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

                        <div class="flex gap-3 justify-end mt-6">
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
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Location Quick-Add Modal -->
        <div v-if="showLocationModal" class="fixed inset-0 z-50 overflow-y-auto" @click="showLocationModal = false">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>

                <div class="relative bg-white dark:bg-dark-card rounded-lg shadow-xl max-w-md w-full p-6" @click.stop>
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            Quick Add Location
                        </h3>
                        <button
                            @click="showLocationModal = false"
                            class="text-gray-500 dark:text-gray-400 hover:text-gray-200"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="space-y-4">
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

                        <div class="flex gap-3 justify-end mt-6">
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
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SKU Generator Modal -->
        <div v-if="showSKUGenerator" class="fixed inset-0 z-50 overflow-y-auto" @click="showSKUGenerator = false">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>

                <div class="relative bg-white dark:bg-dark-card rounded-lg shadow-xl max-w-2xl w-full p-6" @click.stop>
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            SKU Generator
                        </h3>
                        <button
                            @click="showSKUGenerator = false"
                            class="text-gray-500 dark:text-gray-400 hover:text-gray-200"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="space-y-4">
                        <!-- Preset Patterns -->
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">
                                Choose a Preset Pattern
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    v-for="preset in skuPatterns.presets"
                                    :key="preset.pattern"
                                    type="button"
                                    @click="selectedPattern = preset.pattern; customPattern = ''; generateSKUPreview(preset.pattern)"
                                    :class="[
                                        'p-3 text-left rounded-lg border-2 transition',
                                        selectedPattern === preset.pattern
                                            ? 'border-primary-400 bg-primary-400/10'
                                            : 'border-gray-200 dark:border-dark-border hover:border-primary-400/50'
                                    ]"
                                >
                                    <div class="font-medium text-sm text-gray-900 dark:text-gray-100">{{ preset.name }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 font-mono mt-1">{{ preset.example }}</div>
                                </button>
                            </div>
                        </div>

                        <div class="relative">
                            <div class="absolute inset-0 flex items-center">
                                <div class="w-full border-t border-gray-200 dark:border-dark-border"></div>
                            </div>
                            <div class="relative flex justify-center text-sm">
                                <span class="px-2 bg-white dark:bg-dark-card text-gray-500 dark:text-gray-400">OR</span>
                            </div>
                        </div>

                        <!-- Custom Pattern -->
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">
                                Custom Pattern
                            </label>
                            <input
                                v-model="customPattern"
                                @input="selectedPattern = ''; generateSKUPreview(customPattern)"
                                type="text"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                placeholder="e.g., {category}-{year}-{number}"
                            />
                        </div>

                        <!-- Available Variables -->
                        <div class="p-4 bg-gray-50 dark:bg-dark-bg rounded-lg">
                            <p class="text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">Available Variables:</p>
                            <div class="grid grid-cols-2 gap-2">
                                <div v-for="variable in skuPatterns.variables" :key="variable.key" class="text-xs">
                                    <code class="px-1 py-0.5 bg-gray-200 dark:bg-dark-card rounded text-primary-400">{{ variable.key }}</code>
                                    <span class="text-gray-600 dark:text-gray-400 ml-1">{{ variable.description }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Preview -->
                        <div v-if="skuPreview || skuGenerating" class="p-4 bg-primary-900/20 rounded-lg border border-primary-800">
                            <p class="text-sm font-medium text-gray-300 mb-2">Preview:</p>
                            <div v-if="skuGenerating" class="flex items-center gap-2">
                                <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-primary-400"></div>
                                <span class="text-sm text-gray-400">Generating...</span>
                            </div>
                            <p v-else class="text-lg font-mono font-bold text-primary-400">{{ skuPreview }}</p>
                        </div>

                        <div class="flex gap-3 justify-end mt-6">
                            <button
                                type="button"
                                @click="showSKUGenerator = false"
                                class="px-4 py-2 bg-dark-bg text-gray-600 dark:text-gray-300 rounded-md hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                @click="applySKU"
                                :disabled="!skuPreview"
                                class="px-4 py-2 bg-primary-400 text-white rounded-md hover:bg-primary-500 disabled:opacity-50"
                            >
                                Apply SKU
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
