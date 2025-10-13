<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
    categories: Array,
    locations: Array,
    currencies: Object,
    defaultCurrency: String,
});

const form = useForm({
    name: '',
    description: '',
    sku: '',
    price: '',
    currency: props.defaultCurrency || 'USD',
    price_in_currencies: [],
    stock: '',
    min_stock: '',
    category_id: '',
    location_id: '',
    barcode: '',
    notes: '',
});

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
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Add Product
                </h2>
                <Link
                    :href="route('products.index')"
                    class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-500 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Inventory
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
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
                                        <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Product Name <span class="text-red-500">*</span>
                                        </label>
                                        <input
                                            id="name"
                                            v-model="form.name"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                            required
                                        />
                                        <p v-if="form.errors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.name }}
                                        </p>
                                    </div>

                                    <!-- SKU -->
                                    <div>
                                        <label for="sku" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            SKU <span class="text-red-500">*</span>
                                        </label>
                                        <input
                                            id="sku"
                                            v-model="form.sku"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                            required
                                        />
                                        <p v-if="form.errors.sku" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.sku }}
                                        </p>
                                    </div>

                                    <!-- Barcode -->
                                    <div>
                                        <label for="barcode" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Barcode
                                        </label>
                                        <input
                                            id="barcode"
                                            v-model="form.barcode"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                        />
                                        <p v-if="form.errors.barcode" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.barcode }}
                                        </p>
                                    </div>

                                    <!-- Description -->
                                    <div>
                                        <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Description
                                        </label>
                                        <textarea
                                            id="description"
                                            v-model="form.description"
                                            rows="4"
                                            class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                        ></textarea>
                                        <p v-if="form.errors.description" class="mt-1 text-sm text-red-600 dark:text-red-400">
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

                                    <!-- Primary Currency & Price -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                            Price <span class="text-red-500">*</span>
                                        </label>

                                        <div class="grid grid-cols-3 gap-2">
                                            <select
                                                v-model="form.currency"
                                                class="col-span-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                required
                                            >
                                                <option v-for="(data, code) in currencies" :key="code" :value="code">
                                                    {{ code }} ({{ data.symbol }})
                                                </option>
                                            </select>

                                            <div class="col-span-2 relative rounded-md shadow-sm">
                                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                    <span class="text-gray-500 dark:text-gray-400 sm:text-sm">{{ getCurrencySymbol(form.currency) }}</span>
                                                </div>
                                                <input
                                                    v-model="form.price"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    class="pl-10 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                    required
                                                />
                                            </div>
                                        </div>
                                        <p v-if="form.errors.price" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.price }}
                                        </p>
                                    </div>

                                    <!-- Additional Currencies -->
                                    <div v-if="additionalCurrencies.length > 0 || showCurrencySelect" class="space-y-2">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Additional Currencies
                                        </label>

                                        <div v-for="(currencyPrice, index) in additionalCurrencies" :key="index" class="grid grid-cols-3 gap-2">
                                            <select
                                                v-model="currencyPrice.currency"
                                                class="col-span-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
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
                                                        class="pl-10 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                    />
                                                </div>
                                                <button
                                                    type="button"
                                                    @click="removeCurrency(index)"
                                                    class="px-3 py-2 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 rounded-md hover:bg-red-200 dark:hover:bg-red-900/50"
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
                                                class="flex-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
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
                                                class="px-4 py-2 bg-gray-300 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-400 dark:hover:bg-gray-600"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </div>

                                    <button
                                        v-if="!showCurrencySelect && availableCurrencies.length > 0"
                                        type="button"
                                        @click="showCurrencySelect = true"
                                        class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-medium"
                                    >
                                        + Add price in another currency
                                    </button>

                                    <!-- Stock Quantity -->
                                    <div>
                                        <label for="stock" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Current Stock <span class="text-red-500">*</span>
                                        </label>
                                        <input
                                            id="stock"
                                            v-model="form.stock"
                                            type="number"
                                            min="0"
                                            class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                            required
                                        />
                                        <p v-if="form.errors.stock" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.stock }}
                                        </p>
                                    </div>

                                    <!-- Minimum Stock -->
                                    <div>
                                        <label for="min_stock" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Minimum Stock Level <span class="text-red-500">*</span>
                                        </label>
                                        <input
                                            id="min_stock"
                                            v-model="form.min_stock"
                                            type="number"
                                            min="0"
                                            class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                            required
                                        />
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                            Alert when stock falls below this level
                                        </p>
                                        <p v-if="form.errors.min_stock" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.min_stock }}
                                        </p>
                                    </div>

                                    <!-- Category -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <label for="category" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Category <span class="text-red-500">*</span>
                                            </label>
                                            <button
                                                type="button"
                                                @click="showCategoryModal = true"
                                                class="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-medium"
                                            >
                                                + Quick Add
                                            </button>
                                        </div>
                                        <select
                                            id="category"
                                            v-model="form.category_id"
                                            class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                            required
                                        >
                                            <option value="">Select a category</option>
                                            <option v-for="category in categories" :key="category.id" :value="category.id">
                                                {{ category.name }}
                                            </option>
                                        </select>
                                        <p v-if="form.errors.category_id" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.category_id }}
                                        </p>
                                    </div>

                                    <!-- Location -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <label for="location" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Location <span class="text-red-500">*</span>
                                            </label>
                                            <button
                                                type="button"
                                                @click="showLocationModal = true"
                                                class="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-medium"
                                            >
                                                + Quick Add
                                            </button>
                                        </div>
                                        <select
                                            id="location"
                                            v-model="form.location_id"
                                            class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                            required
                                        >
                                            <option value="">Select a location</option>
                                            <option v-for="location in locations" :key="location.id" :value="location.id">
                                                {{ location.name }}
                                            </option>
                                        </select>
                                        <p v-if="form.errors.location_id" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.location_id }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Notes (Full Width) -->
                                <div class="lg:col-span-2">
                                    <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Notes
                                    </label>
                                    <textarea
                                        id="notes"
                                        v-model="form.notes"
                                        rows="3"
                                        class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                        placeholder="Additional notes about this product..."
                                    ></textarea>
                                    <p v-if="form.errors.notes" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.notes }}
                                    </p>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="mt-6 flex items-center justify-end gap-4">
                                <Link
                                    :href="route('products.index')"
                                    class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-500 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700"
                                >
                                    Cancel
                                </Link>
                                <button
                                    type="submit"
                                    class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150"
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
                    </div>
                </div>
            </div>
        </div>

        <!-- Category Quick-Add Modal -->
        <div v-if="showCategoryModal" class="fixed inset-0 z-50 overflow-y-auto" @click="showCategoryModal = false">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>

                <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full p-6" @click.stop>
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            Quick Add Category
                        </h3>
                        <button
                            @click="showCategoryModal = false"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Category Name <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="categoryForm.name"
                                type="text"
                                class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="e.g., Electronics"
                                required
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Description
                            </label>
                            <textarea
                                v-model="categoryForm.description"
                                rows="3"
                                class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="Optional description..."
                            ></textarea>
                        </div>

                        <div class="flex gap-3 justify-end mt-6">
                            <button
                                type="button"
                                @click="showCategoryModal = false"
                                class="px-4 py-2 bg-gray-300 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-400 dark:hover:bg-gray-600"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                @click="createCategory"
                                :disabled="categoryLoading || !categoryForm.name"
                                class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
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

                <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full p-6" @click.stop>
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            Quick Add Location
                        </h3>
                        <button
                            @click="showLocationModal = false"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Location Name <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="locationForm.name"
                                type="text"
                                class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="e.g., Warehouse A"
                                required
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Location Code <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="locationForm.code"
                                type="text"
                                class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="e.g., WH-A"
                                required
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Description
                            </label>
                            <textarea
                                v-model="locationForm.description"
                                rows="3"
                                class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="Optional description..."
                            ></textarea>
                        </div>

                        <div class="flex gap-3 justify-end mt-6">
                            <button
                                type="button"
                                @click="showLocationModal = false"
                                class="px-4 py-2 bg-gray-300 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-400 dark:hover:bg-gray-600"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                @click="createLocation"
                                :disabled="locationLoading || !locationForm.name || !locationForm.code"
                                class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
                            >
                                <span v-if="locationLoading">Creating...</span>
                                <span v-else>Create Location</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
