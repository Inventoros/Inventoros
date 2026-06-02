<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import ProductVariantManager from '@/Components/ProductVariantManager.vue';
import QuickAddModal from '@/Components/QuickAddModal.vue';
import SKUGeneratorModal from '@/Components/SKUGeneratorModal.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import ImageUploader from '@/Components/ImageUploader.vue';
import { ArrowLeft, Eye, Zap, ChevronDown, Layers, Info, X, Trash2 } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    product: Object,
    categories: Array,
    locations: Array,
    currencies: Object,
    defaultCurrency: String,
    pluginComponents: Object,
});

// Prepare existing options and variants for the variant manager
const prepareExistingOptions = () => {
    return (props.product.options || []).map(opt => ({
        id: opt.id,
        name: opt.name,
        values: opt.values,
        position: opt.position
    }));
};

const prepareExistingVariants = () => {
    return (props.product.variants || []).map(v => ({
        id: v.id,
        option_values: v.option_values,
        title: v.title,
        sku: v.sku || '',
        barcode: v.barcode || '',
        price: v.price,
        purchase_price: v.purchase_price,
        stock: v.stock || 0,
        min_stock: v.min_stock || 0,
        is_active: v.is_active ?? true,
        position: v.position
    }));
};

const form = useForm({
    name: props.product.name,
    description: props.product.description,
    sku: props.product.sku,
    price: props.product.price,
    purchase_price: props.product.purchase_price || '',
    stock: props.product.stock,
    min_stock: props.product.min_stock,
    reorder_point: props.product.reorder_point || '',
    reorder_quantity: props.product.reorder_quantity || '',
    category_id: props.product.category_id,
    location_id: props.product.location_id,
    barcode: props.product.barcode || '',
    notes: props.product.notes || '',
    images: (props.product.images || []).map(url => ({
        url: `/storage/${url}`,
        preview: `/storage/${url}`,
        name: url.split('/').pop(),
        size: 0
    })),
    has_variants: props.product.has_variants || false,
    tracking_type: props.product.tracking_type || 'none',
    type: props.product.type || 'standard',
    options: prepareExistingOptions(),
    variants: prepareExistingVariants(),
});

// Variant management
const showVariantSection = ref(props.product.has_variants || false);
const variantData = ref({
    options: prepareExistingOptions(),
    variants: prepareExistingVariants()
});

const toggleVariants = () => {
    showVariantSection.value = !showVariantSection.value;
    form.has_variants = showVariantSection.value;
};

const updateVariantData = (data) => {
    variantData.value = data;
    form.options = data.options.map((opt, idx) => ({
        id: opt.id || null,
        name: opt.name,
        values: opt.values,
        position: idx
    }));
    form.variants = data.variants.map((v, idx) => ({
        id: v.id || null,
        option_values: v.option_values,
        title: v.title,
        sku: v.sku,
        barcode: v.barcode,
        price: v.price,
        purchase_price: v.purchase_price,
        stock: v.stock,
        min_stock: v.min_stock,
        is_active: v.is_active,
        position: idx
    }));
};

const getCurrencySymbol = (code) => {
    return props.currencies?.[code]?.symbol || code || '$';
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

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="`${t('products.edit.title')} - ${product.name}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('products.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('products.index')" class="text-text-tertiary hover:text-text-primary">{{ t('products.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ product.name }}</span>
            </div>
        </template>

        <PageHeader :title="t('products.edit.title')" :description="product.name">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('products.show', product.id)">
                    <Eye :size="14" />
                    {{ t('common.view') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('products.index')">
                    <ArrowLeft :size="14" />
                    {{ t('products.edit.backToInventory') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <!-- Plugin Slot: Before Form -->
        <PluginSlot slot="before-form" :components="pluginComponents?.beforeForm" />

        <form @submit.prevent="submit" class="mt-6">
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <!-- Basic Information -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('products.create.basicInfo') }}</h3></div>
                    <div class="space-y-4 p-5">
                        <!-- Product Name -->
                        <div>
                            <label for="name" :class="fieldLabel">
                                {{ t('products.create.productName') }} <span class="text-status-danger">*</span>
                            </label>
                            <input id="name" v-model="form.name" type="text" :class="fieldInput" required />
                            <p v-if="form.errors.name" :class="fieldError">{{ form.errors.name }}</p>
                        </div>

                        <!-- SKU -->
                        <div>
                            <div class="flex items-center justify-between">
                                <label for="sku" :class="fieldLabel">
                                    {{ t('products.create.sku') }} <span class="text-status-danger">*</span>
                                </label>
                                <button
                                    type="button"
                                    @click="showSKUGenerator = true"
                                    class="mb-1 flex items-center gap-1 text-xs font-medium text-brand hover:text-brand-hover"
                                >
                                    <Zap :size="12" />
                                    {{ t('products.create.generateSku') }}
                                </button>
                            </div>
                            <input id="sku" v-model="form.sku" type="text" :class="fieldInput" required />
                            <p v-if="form.errors.sku" :class="fieldError">{{ form.errors.sku }}</p>
                        </div>

                        <!-- Barcode -->
                        <div>
                            <label for="barcode" :class="fieldLabel">{{ t('products.create.barcode') }}</label>
                            <input id="barcode" v-model="form.barcode" type="text" :class="fieldInput" />
                            <p v-if="form.errors.barcode" :class="fieldError">{{ form.errors.barcode }}</p>
                        </div>

                        <!-- Product Type -->
                        <div>
                            <label for="type" :class="fieldLabel">Product Type</label>
                            <select id="type" v-model="form.type" :class="fieldInput">
                                <option value="standard">Standard</option>
                                <option value="kit">Kit</option>
                                <option value="assembly">Assembly</option>
                            </select>
                            <p class="mt-1 text-xs text-text-tertiary">
                                Kits bundle components for sale. Assemblies require work orders to produce.
                            </p>
                            <p v-if="form.errors.type" :class="fieldError">{{ form.errors.type }}</p>
                        </div>

                        <!-- Description -->
                        <div>
                            <label for="description" :class="fieldLabel">{{ t('products.create.description') }}</label>
                            <textarea id="description" v-model="form.description" rows="4" :class="fieldArea"></textarea>
                            <p v-if="form.errors.description" :class="fieldError">{{ form.errors.description }}</p>
                        </div>
                    </div>
                </Card>

                <!-- Pricing & Inventory -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('products.create.pricingInventory') }}</h3></div>
                    <div class="space-y-4 p-5">
                        <!-- Purchase Price (Cost) -->
                        <div>
                            <label for="purchase_price" :class="fieldLabel">{{ t('products.create.purchasePrice') }}</label>
                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <span class="text-sm text-text-tertiary">$</span>
                                </div>
                                <input id="purchase_price" v-model="form.purchase_price" type="number" step="0.01" min="0" :class="[fieldInput, 'pl-7']" />
                            </div>
                            <p class="mt-1 text-xs text-text-tertiary">{{ t('products.create.purchasePriceHint') }}</p>
                            <p v-if="form.errors.purchase_price" :class="fieldError">{{ form.errors.purchase_price }}</p>
                        </div>

                        <!-- Selling Price -->
                        <div>
                            <label for="price" :class="fieldLabel">
                                {{ t('products.create.sellingPrice') }} <span class="text-status-danger">*</span>
                            </label>
                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <span class="text-sm text-text-tertiary">$</span>
                                </div>
                                <input id="price" v-model="form.price" type="number" step="0.01" min="0" :class="[fieldInput, 'pl-7']" required />
                            </div>
                            <p class="mt-1 text-xs text-text-tertiary">{{ t('products.create.sellingPriceHint') }}</p>
                            <p v-if="form.errors.price" :class="fieldError">{{ form.errors.price }}</p>
                        </div>

                        <!-- Profit Indicator -->
                        <div v-if="form.price && form.purchase_price" class="rounded-lg border border-status-success/20 bg-status-success-soft p-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-status-success">{{ t('products.create.profitPerUnit') }}:</span>
                                <span class="text-lg font-bold text-status-success">
                                    ${{ (parseFloat(form.price) - parseFloat(form.purchase_price)).toFixed(2) }}
                                </span>
                            </div>
                            <div class="mt-1 flex items-center justify-between">
                                <span class="text-xs text-status-success">{{ t('products.create.margin') }}:</span>
                                <span class="text-sm font-semibold text-status-success">
                                    {{ ((parseFloat(form.price) - parseFloat(form.purchase_price)) / parseFloat(form.price) * 100).toFixed(1) }}%
                                </span>
                            </div>
                        </div>

                        <!-- Stock Quantity -->
                        <div>
                            <label for="stock" :class="fieldLabel">
                                {{ t('products.create.currentStock') }} <span class="text-status-danger">*</span>
                            </label>
                            <input id="stock" v-model="form.stock" type="number" min="0" :class="fieldInput" required />
                            <p v-if="form.errors.stock" :class="fieldError">{{ form.errors.stock }}</p>
                        </div>

                        <!-- Minimum Stock -->
                        <div>
                            <label for="min_stock" :class="fieldLabel">
                                {{ t('products.create.minStockLevel') }} <span class="text-status-danger">*</span>
                            </label>
                            <input id="min_stock" v-model="form.min_stock" type="number" min="0" :class="fieldInput" required />
                            <p class="mt-1 text-xs text-text-tertiary">{{ t('products.create.minStockHint') }}</p>
                            <p v-if="form.errors.min_stock" :class="fieldError">{{ form.errors.min_stock }}</p>
                        </div>

                        <!-- Reorder Point -->
                        <div>
                            <label for="reorder_point" :class="fieldLabel">Reorder Point</label>
                            <input id="reorder_point" v-model="form.reorder_point" type="number" min="0" :class="fieldInput" placeholder="Leave empty to disable" />
                            <p class="mt-1 text-xs text-text-tertiary">Stock level that triggers automatic reorder</p>
                            <p v-if="form.errors.reorder_point" :class="fieldError">{{ form.errors.reorder_point }}</p>
                        </div>

                        <!-- Reorder Quantity -->
                        <div>
                            <label for="reorder_quantity" :class="fieldLabel">Reorder Quantity</label>
                            <input id="reorder_quantity" v-model="form.reorder_quantity" type="number" min="0" :class="fieldInput" placeholder="Leave empty to disable" />
                            <p class="mt-1 text-xs text-text-tertiary">Quantity to order when stock reaches reorder point</p>
                            <p v-if="form.errors.reorder_quantity" :class="fieldError">{{ form.errors.reorder_quantity }}</p>
                        </div>

                        <!-- Category -->
                        <div>
                            <div class="flex items-center justify-between">
                                <label for="category" :class="fieldLabel">
                                    {{ t('products.category') }} <span class="text-status-danger">*</span>
                                </label>
                                <button
                                    type="button"
                                    @click="showCategoryModal = true"
                                    class="mb-1 text-xs font-medium text-brand hover:text-brand-hover"
                                >
                                    {{ t('products.create.quickAdd') }}
                                </button>
                            </div>
                            <select id="category" v-model="form.category_id" :class="fieldInput" required>
                                <option value="">{{ t('products.create.selectCategory') }}</option>
                                <option v-for="category in categories" :key="category.id" :value="category.id">
                                    {{ category.name }}
                                </option>
                            </select>
                            <p v-if="form.errors.category_id" :class="fieldError">{{ form.errors.category_id }}</p>
                        </div>

                        <!-- Location -->
                        <div>
                            <div class="flex items-center justify-between">
                                <label for="location" :class="fieldLabel">
                                    {{ t('products.location') }} <span class="text-status-danger">*</span>
                                </label>
                                <button
                                    type="button"
                                    @click="showLocationModal = true"
                                    class="mb-1 text-xs font-medium text-brand hover:text-brand-hover"
                                >
                                    {{ t('products.create.quickAdd') }}
                                </button>
                            </div>
                            <select id="location" v-model="form.location_id" :class="fieldInput" required>
                                <option value="">{{ t('products.create.selectLocation') }}</option>
                                <option v-for="location in locations" :key="location.id" :value="location.id">
                                    {{ location.name }}
                                </option>
                            </select>
                            <p v-if="form.errors.location_id" :class="fieldError">{{ form.errors.location_id }}</p>
                        </div>

                        <!-- Tracking Type -->
                        <div>
                            <label for="tracking_type" :class="fieldLabel">Inventory Tracking</label>
                            <select id="tracking_type" v-model="form.tracking_type" :class="fieldInput">
                                <option value="none">No Tracking</option>
                                <option value="batch">Batch Tracking</option>
                                <option value="serial">Serial Number Tracking</option>
                            </select>
                            <p class="mt-1 text-xs text-text-tertiary">Choose how to track individual units of this product.</p>
                            <p v-if="form.errors.tracking_type" :class="fieldError">{{ form.errors.tracking_type }}</p>
                        </div>
                    </div>
                </Card>

                <!-- Product Images (Full Width) -->
                <Card :padded="false" class="lg:col-span-2">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('products.create.productImages') }}</h3></div>
                    <div class="p-5">
                        <ImageUploader
                            v-model="form.images"
                            :max-images="5"
                            :max-size-in-m-b="5"
                        />
                        <p v-if="form.errors.images" :class="fieldError">{{ form.errors.images }}</p>
                    </div>
                </Card>

                <!-- Product Variants Section (Full Width) -->
                <Card :padded="false" class="lg:col-span-2">
                    <button
                        type="button"
                        @click="toggleVariants"
                        class="flex w-full items-center justify-between p-5 transition-colors hover:bg-surface-sunken"
                    >
                        <div class="flex items-center gap-3">
                            <Layers :size="18" class="text-text-tertiary" />
                            <span class="text-sm font-semibold text-text-primary">
                                {{ t('products.create.productVariants') }}
                            </span>
                            <Badge v-if="form.variants.length > 0" variant="brand" size="sm">
                                {{ form.variants.length }} variants
                            </Badge>
                        </div>
                        <ChevronDown
                            :size="18"
                            :class="['text-text-tertiary transition-transform', showVariantSection ? 'rotate-180' : '']"
                        />
                    </button>

                    <div v-if="showVariantSection" class="border-t border-border-subtle p-5">
                        <p class="mb-4 text-sm text-text-tertiary">
                            {{ t('products.create.variantHint') }}
                        </p>

                        <!-- Note about stock tracking -->
                        <div v-if="form.variants.length > 0" class="mb-4 rounded-lg border border-status-info/20 bg-status-info-soft p-3">
                            <p class="flex items-center gap-1.5 text-sm text-status-info">
                                <Info :size="16" />
                                {{ t('products.create.variantStockHint') }}
                            </p>
                        </div>

                        <ProductVariantManager
                            :model-value="variantData"
                            @update:model-value="updateVariantData"
                            :product-price="form.price"
                            :product-purchase-price="form.purchase_price"
                            :currency-symbol="getCurrencySymbol(product.currency || defaultCurrency)"
                        />
                    </div>
                </Card>

                <!-- Notes (Full Width) -->
                <Card :padded="false" class="lg:col-span-2">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('common.notes') }}</h3></div>
                    <div class="p-5">
                        <textarea
                            id="notes"
                            v-model="form.notes"
                            rows="3"
                            :class="fieldArea"
                            :placeholder="t('products.create.notesPlaceholder')"
                        ></textarea>
                        <p v-if="form.errors.notes" :class="fieldError">{{ form.errors.notes }}</p>
                    </div>
                </Card>
            </div>

            <!-- Form Actions -->
            <div class="mt-6 flex items-center justify-end gap-3">
                <Button variant="secondary" as="Link" :href="route('products.index')">
                    {{ t('common.cancel') }}
                </Button>
                <Button type="submit" variant="default" :loading="form.processing" :disabled="form.processing">
                    {{ t('products.edit.updateProduct') }}
                </Button>
            </div>
        </form>

        <!-- Plugin Slot: After Form -->
        <PluginSlot slot="after-form" :components="pluginComponents?.afterForm" />

        <!-- Category Quick-Add Modal -->
        <div v-if="showCategoryModal" class="fixed inset-0 z-50 overflow-y-auto" @click="showCategoryModal = false">
            <div class="flex min-h-screen items-center justify-center px-4">
                <div class="fixed inset-0 bg-black/50 transition-opacity"></div>

                <Card class="relative w-full max-w-md shadow-xl" @click.stop>
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">
                            {{ t('products.quickAddCategory.title') }}
                        </h3>
                        <button
                            @click="showCategoryModal = false"
                            class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-text-primary"
                        >
                            <X :size="18" />
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label :class="fieldLabel">
                                {{ t('products.quickAddCategory.categoryName') }} <span class="text-status-danger">*</span>
                            </label>
                            <input
                                v-model="categoryForm.name"
                                type="text"
                                :class="fieldInput"
                                :placeholder="t('products.quickAddCategory.placeholder')"
                                required
                            />
                        </div>

                        <div>
                            <label :class="fieldLabel">{{ t('common.description') }}</label>
                            <textarea
                                v-model="categoryForm.description"
                                rows="3"
                                :class="fieldArea"
                                :placeholder="t('products.quickAddCategory.descPlaceholder')"
                            ></textarea>
                        </div>

                        <div class="mt-6 flex justify-end gap-3">
                            <Button type="button" variant="secondary" @click="showCategoryModal = false">
                                {{ t('common.cancel') }}
                            </Button>
                            <Button
                                type="button"
                                variant="default"
                                @click="createCategory"
                                :loading="categoryLoading"
                                :disabled="categoryLoading || !categoryForm.name"
                            >
                                {{ categoryLoading ? t('common.creating') : t('categories.createCategory') }}
                            </Button>
                        </div>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Location Quick-Add Modal -->
        <div v-if="showLocationModal" class="fixed inset-0 z-50 overflow-y-auto" @click="showLocationModal = false">
            <div class="flex min-h-screen items-center justify-center px-4">
                <div class="fixed inset-0 bg-black/50 transition-opacity"></div>

                <Card class="relative w-full max-w-md shadow-xl" @click.stop>
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">
                            {{ t('products.quickAddLocation.title') }}
                        </h3>
                        <button
                            @click="showLocationModal = false"
                            class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-text-primary"
                        >
                            <X :size="18" />
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label :class="fieldLabel">
                                {{ t('products.quickAddLocation.locationName') }} <span class="text-status-danger">*</span>
                            </label>
                            <input
                                v-model="locationForm.name"
                                type="text"
                                :class="fieldInput"
                                :placeholder="t('products.quickAddLocation.namePlaceholder')"
                                required
                            />
                        </div>

                        <div>
                            <label :class="fieldLabel">
                                {{ t('products.quickAddLocation.locationCode') }} <span class="text-status-danger">*</span>
                            </label>
                            <input
                                v-model="locationForm.code"
                                type="text"
                                :class="fieldInput"
                                :placeholder="t('products.quickAddLocation.codePlaceholder')"
                                required
                            />
                        </div>

                        <div>
                            <label :class="fieldLabel">{{ t('common.description') }}</label>
                            <textarea
                                v-model="locationForm.description"
                                rows="3"
                                :class="fieldArea"
                                :placeholder="t('products.quickAddLocation.descPlaceholder')"
                            ></textarea>
                        </div>

                        <div class="mt-6 flex justify-end gap-3">
                            <Button type="button" variant="secondary" @click="showLocationModal = false">
                                {{ t('common.cancel') }}
                            </Button>
                            <Button
                                type="button"
                                variant="default"
                                @click="createLocation"
                                :loading="locationLoading"
                                :disabled="locationLoading || !locationForm.name || !locationForm.code"
                            >
                                {{ locationLoading ? t('common.creating') : t('locations.createLocation') }}
                            </Button>
                        </div>
                    </div>
                </Card>
            </div>
        </div>

        <!-- SKU Generator Modal -->
        <SKUGeneratorModal
            :show="showSKUGenerator"
            :product-name="form.name"
            :category-id="form.category_id"
            @apply="applySKUFromModal"
            @close="showSKUGenerator = false"
        />
    </AppLayout>
</template>
