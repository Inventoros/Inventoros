<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import ActivityTimeline from '@/Components/ActivityTimeline.vue';
import VariantsTable from '@/Components/VariantsTable.vue';
import BatchList from '@/Components/BatchList.vue';
import SerialList from '@/Components/SerialList.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, onMounted, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import ImageGallery from '@/Components/ImageGallery.vue';
import { usePermissions } from '@/composables/usePermissions';

const { t } = useI18n();
const { hasPermission } = usePermissions();

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
        return { text: t('products.show.outOfStock'), class: 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300' };
    }
    if (props.product.stock <= props.product.min_stock) {
        return { text: t('products.show.lowStock'), class: 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300' };
    }
    return { text: t('products.show.inStock'), class: 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300' };
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

// Components (BOM) management for kits/assemblies
const isKitOrAssembly = computed(() => ['kit', 'assembly'].includes(props.product.type));
const components = ref(props.product.components || []);
const showAddComponent = ref(false);
const componentSearch = ref('');
const componentSearchResults = ref([]);
const componentSearching = ref(false);
const newComponentQty = ref(1);
const selectedComponent = ref(null);
const editingComponentId = ref(null);
const editingComponentQty = ref(1);

const availableKitStock = computed(() => {
    if (props.product.type !== 'kit' || components.value.length === 0) return 0;
    return Math.min(...components.value.map(c => Math.floor((c.component?.stock || 0) / c.quantity)));
});

const searchComponents = async () => {
    if (componentSearch.value.length < 2) {
        componentSearchResults.value = [];
        return;
    }
    componentSearching.value = true;
    try {
        const response = await axios.get(route('products.index'), {
            params: { search: componentSearch.value, per_page: 10, format: 'json' },
            headers: { 'Accept': 'application/json' },
        });
        const data = response.data?.data || response.data?.products?.data || [];
        // Exclude self and kits to prevent circular refs
        componentSearchResults.value = data.filter(
            p => p.id !== props.product.id && p.type !== 'kit'
        );
    } catch (e) {
        componentSearchResults.value = [];
    } finally {
        componentSearching.value = false;
    }
};

const selectComponent = (product) => {
    selectedComponent.value = product;
    componentSearch.value = product.name;
    componentSearchResults.value = [];
};

const addComponent = async () => {
    if (!selectedComponent.value || newComponentQty.value < 1) return;
    try {
        await axios.post(route('products.components.store', props.product.id), {
            component_product_id: selectedComponent.value.id,
            quantity: newComponentQty.value,
        });
        router.reload({ only: ['product'] });
        showAddComponent.value = false;
        componentSearch.value = '';
        selectedComponent.value = null;
        newComponentQty.value = 1;
    } catch (error) {
        alert(error.response?.data?.message || 'Failed to add component');
    }
};

const startEditComponent = (component) => {
    editingComponentId.value = component.id;
    editingComponentQty.value = component.quantity;
};

const saveComponentQty = async (component) => {
    try {
        await axios.put(route('products.components.update', [props.product.id, component.id]), {
            quantity: editingComponentQty.value,
        });
        router.reload({ only: ['product'] });
        editingComponentId.value = null;
    } catch (error) {
        alert('Failed to update quantity');
    }
};

const removeComponent = async (component) => {
    if (!confirm('Remove this component from the bill of materials?')) return;
    try {
        await axios.delete(route('products.components.destroy', [props.product.id, component.id]));
        router.reload({ only: ['product'] });
    } catch (error) {
        alert('Failed to remove component');
    }
};

// Watch for product updates to refresh components
watch(() => props.product.components, (val) => {
    components.value = val || [];
});

const duplicateProduct = () => {
    router.post(route('products.duplicate', props.product.id));
};
</script>

<template>
    <Head :title="product.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    {{ t('products.show.title') }}
                </h2>
                <div class="flex gap-3">
                    <button
                        @click="duplicateProduct"
                        class="inline-flex items-center px-4 py-2 bg-amber-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-amber-600 transition"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        Duplicate
                    </button>
                    <Link
                        :href="route('products.edit', product.id)"
                        class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 transition"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        {{ t('common.edit') }}
                    </Link>
                    <Link
                        :href="route('products.index')"
                        class="inline-flex items-center px-4 py-2 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-100 dark:hover:bg-dark-bg transition"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        {{ t('products.show.backToInventory') }}
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
                                        <div v-if="product.type && product.type !== 'standard'" class="mb-1">
                                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full" :class="product.type === 'kit' ? 'bg-blue-900/30 text-blue-300' : 'bg-purple-900/30 text-purple-300'">
                                                {{ product.type === 'kit' ? 'Kit' : 'Assembly' }}
                                            </span>
                                        </div>
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
                                    <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">{{ t('common.description') }}</h4>
                                    <p class="text-gray-900 dark:text-gray-100">{{ product.description }}</p>
                                </div>

                                <div v-if="product.notes" class="mb-6 p-4 bg-yellow-900/20 rounded-lg border border-yellow-800">
                                    <h4 class="text-sm font-medium text-yellow-300 mb-2">{{ t('common.notes') }}</h4>
                                    <p class="text-sm text-yellow-400">{{ product.notes }}</p>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">{{ t('products.category') }}</h4>
                                        <p class="text-gray-900 dark:text-gray-100">
                                            {{ product.category?.name || t('products.show.uncategorized') }}
                                        </p>
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">{{ t('products.location') }}</h4>
                                        <p class="text-gray-900 dark:text-gray-100">
                                            {{ product.location?.name || t('products.show.noLocation') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pricing -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                    {{ t('products.show.pricingInfo') }}
                                </h3>
                                <div class="grid grid-cols-2 gap-4 mb-6">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">{{ t('products.show.sellingPrice') }}</h4>
                                        <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                            {{ formatCurrency(product.price) }}
                                        </p>
                                        <p v-if="product.currency" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                            Currency: {{ product.currency }}
                                        </p>
                                    </div>
                                    <div v-if="product.purchase_price">
                                        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">{{ t('products.show.purchasePrice') }}</h4>
                                        <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                            {{ formatCurrency(product.purchase_price) }}
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                            {{ t('products.show.whatYouPaid') }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Profit Information -->
                                <div v-if="product.purchase_price && product.price" class="grid grid-cols-3 gap-4 p-4 bg-green-900/20 rounded-lg border border-green-800">
                                    <div>
                                        <h4 class="text-xs font-medium text-green-400 mb-1">{{ t('products.show.profitPerUnit') }}</h4>
                                        <p class="text-lg font-bold text-green-400">
                                            {{ formatCurrency(product.price - product.purchase_price) }}
                                        </p>
                                    </div>
                                    <div>
                                        <h4 class="text-xs font-medium text-green-400 mb-1">{{ t('products.show.profitMargin') }}</h4>
                                        <p class="text-lg font-bold text-green-400">
                                            {{ ((product.price - product.purchase_price) / product.price * 100).toFixed(1) }}%
                                        </p>
                                    </div>
                                    <div>
                                        <h4 class="text-xs font-medium text-green-400 mb-1">{{ t('products.show.totalProfitInStock') }}</h4>
                                        <p class="text-lg font-bold text-green-400">
                                            {{ formatCurrency((product.price - product.purchase_price) * product.stock) }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Additional Currencies -->
                                <div v-if="product.price_in_currencies && Object.keys(product.price_in_currencies).length > 0" class="mt-6">
                                    <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">{{ t('products.show.altCurrencies') }}</h4>
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
                                        {{ t('products.show.variants') }}
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

                        <!-- Components (BOM) for Kits/Assemblies -->
                        <div v-if="isKitOrAssembly" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-3">
                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                            Bill of Materials
                                        </h3>
                                        <span class="px-2 py-1 text-xs rounded-full" :class="product.type === 'kit' ? 'bg-blue-900/30 text-blue-300' : 'bg-purple-900/30 text-purple-300'">
                                            {{ product.type === 'kit' ? 'Kit' : 'Assembly' }}
                                        </span>
                                    </div>
                                    <button
                                        @click="showAddComponent = !showAddComponent"
                                        class="px-3 py-1.5 bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium rounded-lg transition"
                                    >
                                        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                        </svg>
                                        Add Component
                                    </button>
                                </div>

                                <!-- Kit Available Stock -->
                                <div v-if="product.type === 'kit' && components.length > 0" class="mb-4 p-3 bg-blue-900/20 rounded-lg border border-blue-800">
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-blue-300">
                                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            Available Kit Stock (limited by lowest component)
                                        </span>
                                        <span class="text-lg font-bold text-blue-300">{{ availableKitStock }}</span>
                                    </div>
                                </div>

                                <!-- Assembly: Create Work Order button -->
                                <div v-if="product.type === 'assembly' && components.length > 0" class="mb-4">
                                    <Link
                                        :href="route('work-orders.create', { product_id: product.id })"
                                        class="inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-lg transition"
                                    >
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                        Create Work Order
                                    </Link>
                                </div>

                                <!-- Add Component Form -->
                                <div v-if="showAddComponent" class="mb-4 p-4 bg-gray-50 dark:bg-dark-bg/50 rounded-lg border border-gray-200 dark:border-dark-border">
                                    <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-3">Add Component</h4>
                                    <div class="flex gap-3 items-end">
                                        <div class="flex-1 relative">
                                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Search Product</label>
                                            <input
                                                v-model="componentSearch"
                                                type="text"
                                                placeholder="Search by name or SKU..."
                                                class="block w-full rounded-md bg-white dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm"
                                                @input="searchComponents"
                                            />
                                            <!-- Search Results Dropdown -->
                                            <div v-if="componentSearchResults.length > 0" class="absolute z-10 mt-1 w-full bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                <button
                                                    v-for="result in componentSearchResults"
                                                    :key="result.id"
                                                    type="button"
                                                    @click="selectComponent(result)"
                                                    class="w-full text-left px-3 py-2 hover:bg-gray-50 dark:hover:bg-dark-bg/50 transition text-sm"
                                                >
                                                    <span class="text-gray-900 dark:text-gray-100">{{ result.name }}</span>
                                                    <span class="text-gray-500 dark:text-gray-400 ml-2">{{ result.sku }}</span>
                                                    <span class="text-gray-400 dark:text-gray-500 ml-2">(Stock: {{ result.stock }})</span>
                                                </button>
                                            </div>
                                            <div v-if="componentSearching" class="absolute z-10 mt-1 w-full bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-lg p-3 text-center">
                                                <span class="text-sm text-gray-500 dark:text-gray-400">Searching...</span>
                                            </div>
                                        </div>
                                        <div class="w-28">
                                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Quantity</label>
                                            <input
                                                v-model.number="newComponentQty"
                                                type="number"
                                                min="1"
                                                class="block w-full rounded-md bg-white dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm"
                                            />
                                        </div>
                                        <button
                                            type="button"
                                            @click="addComponent"
                                            :disabled="!selectedComponent"
                                            class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            Add
                                        </button>
                                        <button
                                            type="button"
                                            @click="showAddComponent = false"
                                            class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg transition"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </div>

                                <!-- Components Table -->
                                <div v-if="components.length > 0" class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                                        <thead class="bg-gray-50 dark:bg-dark-bg">
                                            <tr>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Product</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">SKU</th>
                                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Qty Required</th>
                                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Available Stock</th>
                                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white dark:bg-dark-card divide-y divide-gray-200 dark:divide-dark-border">
                                            <tr v-for="comp in components" :key="comp.id" class="hover:bg-gray-50 dark:hover:bg-dark-bg/50 transition">
                                                <td class="px-4 py-3">
                                                    <Link :href="route('products.show', comp.component?.id || comp.component_product_id)" class="text-primary-400 hover:text-primary-300 font-medium text-sm">
                                                        {{ comp.component?.name || 'Unknown' }}
                                                    </Link>
                                                </td>
                                                <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                                    {{ comp.component?.sku || '-' }}
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <template v-if="editingComponentId === comp.id">
                                                        <input
                                                            v-model.number="editingComponentQty"
                                                            type="number"
                                                            min="1"
                                                            class="w-20 text-center rounded-md bg-white dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm"
                                                        />
                                                    </template>
                                                    <template v-else>
                                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ comp.quantity }}</span>
                                                    </template>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <span
                                                        class="text-sm font-medium"
                                                        :class="(comp.component?.stock || 0) >= comp.quantity ? 'text-green-400' : 'text-red-400'"
                                                    >
                                                        {{ comp.component?.stock || 0 }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-right">
                                                    <div class="flex justify-end gap-2">
                                                        <template v-if="editingComponentId === comp.id">
                                                            <button @click="saveComponentQty(comp)" class="text-green-400 hover:text-green-300 text-sm">Save</button>
                                                            <button @click="editingComponentId = null" class="text-gray-400 hover:text-gray-300 text-sm">Cancel</button>
                                                        </template>
                                                        <template v-else>
                                                            <button @click="startEditComponent(comp)" class="text-primary-400 hover:text-primary-300 text-sm">Edit</button>
                                                            <button @click="removeComponent(comp)" class="text-red-400 hover:text-red-300 text-sm">Remove</button>
                                                        </template>
                                                    </div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Empty State -->
                                <div v-else class="text-center py-8">
                                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                    </svg>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No components added yet.</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-500">Click "Add Component" to build the bill of materials.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Batch Tracking -->
                        <div v-if="product.tracking_type === 'batch'" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="p-6">
                                <BatchList
                                    :product-id="product.id"
                                    :batches="product.batches || []"
                                />
                            </div>
                        </div>

                        <!-- Serial Tracking -->
                        <div v-if="product.tracking_type === 'serial'" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="p-6">
                                <SerialList
                                    :product-id="product.id"
                                    :serials="product.serials || []"
                                />
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
                                    {{ t('products.create.productImages') }}
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
                                    {{ t('products.show.barcode') }}
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
                                            {{ t('products.printBarcodes') }}
                                        </button>
                                    </div>

                                    <div class="pt-3 border-t border-gray-200 dark:border-dark-border space-y-2">
                                        <button
                                            @click="generateRandomBarcode"
                                            class="w-full px-3 py-2 bg-gray-100 dark:bg-dark-bg hover:bg-gray-200 dark:hover:bg-dark-bg/80 text-gray-700 dark:text-gray-300 text-sm rounded-lg font-medium border border-gray-200 dark:border-dark-border transition"
                                        >
                                            {{ t('products.show.generateNewRandom') }}
                                        </button>
                                        <button
                                            @click="generateFromSKU"
                                            class="w-full px-3 py-2 bg-gray-100 dark:bg-dark-bg hover:bg-gray-200 dark:hover:bg-dark-bg/80 text-gray-700 dark:text-gray-300 text-sm rounded-lg font-medium border border-gray-200 dark:border-dark-border transition"
                                        >
                                            {{ t('products.show.generateFromSku') }}
                                        </button>
                                    </div>
                                </div>

                                <div v-else class="text-center py-4">
                                    <p class="text-gray-500 dark:text-gray-400 text-sm mb-3">{{ t('products.show.noBarcodeAvailable') }}</p>
                                    <button
                                        @click="generateRandomBarcode"
                                        class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm rounded-lg font-medium transition"
                                    >
                                        {{ t('products.show.generateBarcode') }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Stock Information -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                    {{ t('products.show.stockInfo') }}
                                </h3>
                                <div class="space-y-4">
                                    <div class="p-4 bg-primary-900/20 rounded-lg border border-primary-800">
                                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">{{ t('products.show.currentStock') }}</p>
                                        <p class="text-3xl font-bold text-primary-400">
                                            {{ product.stock }}
                                        </p>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="p-3 bg-gray-50 dark:bg-dark-bg/50 rounded-lg border border-gray-200 dark:border-dark-border">
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ t('products.show.minStock') }}</p>
                                            <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                                {{ product.min_stock }}
                                            </p>
                                        </div>
                                        <div v-if="product.max_stock" class="p-3 bg-gray-50 dark:bg-dark-bg/50 rounded-lg border border-gray-200 dark:border-dark-border">
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ t('products.show.maxStock') }}</p>
                                            <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                                {{ product.max_stock }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="p-3 bg-green-900/20 rounded-lg border border-green-800">
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ t('products.show.totalValue') }}</p>
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
                                    {{ t('common.status') }}
                                </h3>
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ t('common.active') }}</span>
                                        <span :class="[
                                            'px-2 py-1 text-xs font-semibold rounded-full',
                                            product.is_active
                                                ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300'
                                                : 'bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-300'
                                        ]">
                                            {{ product.is_active ? t('common.yes') : t('common.no') }}
                                        </span>
                                    </div>
                                    <div class="pt-3 border-t border-gray-200 dark:border-dark-border">
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ t('common.createdAt') }}</p>
                                        <p class="text-sm text-gray-900 dark:text-gray-100">
                                            {{ new Date(product.created_at).toLocaleString() }}
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ t('common.updatedAt') }}</p>
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
                            {{ t('products.show.activityHistory') }}
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
