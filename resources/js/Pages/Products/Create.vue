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
import { ArrowLeft, Zap, X, Plus, Trash2, ChevronDown, Layers } from 'lucide-vue-next';

const { t } = useI18n();

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
    reorder_point: '',
    reorder_quantity: '',
    category_id: '',
    location_id: '',
    barcode: '',
    notes: '',
    images: [],
    has_variants: false,
    tracking_type: 'none',
    type: 'standard',
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

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('products.addProduct')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('products.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('products.index')" class="text-text-tertiary hover:text-text-primary">{{ t('products.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('products.addProduct') }}</span>
            </div>
        </template>

        <PageHeader :title="t('products.addProduct')" description="Create a product with pricing, stock, variants, and images.">
            <template #actions>
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
                            <div class="mb-1 flex items-center justify-between">
                                <label for="sku" :class="fieldLabel + ' mb-0'">
                                    {{ t('products.create.sku') }} <span class="text-status-danger">*</span>
                                </label>
                                <button
                                    type="button"
                                    @click="showSKUGenerator = true"
                                    class="flex items-center gap-1 text-xs font-medium text-brand hover:text-brand-hover"
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
                        <!-- Currency Selection -->
                        <div>
                            <label :class="fieldLabel">
                                {{ t('common.currency') }} <span class="text-status-danger">*</span>
                            </label>
                            <select v-model="form.currency" :class="fieldInput" required>
                                <option v-for="(data, code) in currencies" :key="code" :value="code">
                                    {{ code }} ({{ data.symbol }}) - {{ data.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Purchase Price (Cost) -->
                        <div>
                            <label for="purchase_price" :class="fieldLabel">{{ t('products.create.purchasePrice') }}</label>
                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <span class="text-sm text-text-tertiary">{{ getCurrencySymbol(form.currency) }}</span>
                                </div>
                                <input id="purchase_price" v-model="form.purchase_price" type="number" step="0.01" min="0" :class="fieldInput + ' pl-8'" />
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
                                    <span class="text-sm text-text-tertiary">{{ getCurrencySymbol(form.currency) }}</span>
                                </div>
                                <input id="price" v-model="form.price" type="number" step="0.01" min="0" :class="fieldInput + ' pl-8'" required />
                            </div>
                            <p class="mt-1 text-xs text-text-tertiary">{{ t('products.create.sellingPriceHint') }}</p>
                            <p v-if="form.errors.price" :class="fieldError">{{ form.errors.price }}</p>
                        </div>

                        <!-- Profit Indicator -->
                        <div v-if="form.price && form.purchase_price" class="rounded-lg border border-status-success/20 bg-status-success-soft p-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-status-success">{{ t('products.create.profitPerUnit') }}:</span>
                                <span class="text-lg font-bold text-status-success">
                                    {{ getCurrencySymbol(form.currency) }}{{ (parseFloat(form.price) - parseFloat(form.purchase_price)).toFixed(2) }}
                                </span>
                            </div>
                            <div class="mt-1 flex items-center justify-between">
                                <span class="text-xs text-status-success">{{ t('products.create.margin') }}:</span>
                                <span class="text-sm font-semibold text-status-success">
                                    {{ ((parseFloat(form.price) - parseFloat(form.purchase_price)) / parseFloat(form.price) * 100).toFixed(1) }}%
                                </span>
                            </div>
                        </div>

                        <!-- Additional Currencies -->
                        <div v-if="additionalCurrencies.length > 0 || showCurrencySelect" class="space-y-2">
                            <label :class="fieldLabel">Additional Currencies</label>

                            <div v-for="(currencyPrice, index) in additionalCurrencies" :key="index" class="grid grid-cols-3 gap-2">
                                <select v-model="currencyPrice.currency" class="col-span-1" :class="fieldInput" disabled>
                                    <option :value="currencyPrice.currency">{{ currencyPrice.currency }} ({{ getCurrencySymbol(currencyPrice.currency) }})</option>
                                </select>

                                <div class="col-span-2 flex gap-2">
                                    <div class="relative flex-1">
                                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                            <span class="text-sm text-text-tertiary">{{ getCurrencySymbol(currencyPrice.currency) }}</span>
                                        </div>
                                        <input v-model="currencyPrice.price" type="number" step="0.01" min="0" :class="fieldInput + ' pl-8'" />
                                    </div>
                                    <Button type="button" variant="danger" size="icon" @click="removeCurrency(index)">
                                        <X :size="16" />
                                    </Button>
                                </div>
                            </div>

                            <div v-if="showCurrencySelect" class="flex gap-2">
                                <select v-model="selectedNewCurrency" class="flex-1" :class="fieldInput">
                                    <option value="">Select currency...</option>
                                    <option v-for="curr in availableCurrencies" :key="curr.code" :value="curr.code">
                                        {{ curr.code }} ({{ curr.symbol }}) - {{ curr.name }}
                                    </option>
                                </select>
                                <Button type="button" variant="default" @click="addCurrency">Add</Button>
                                <Button type="button" variant="secondary" @click="showCurrencySelect = false">Cancel</Button>
                            </div>
                        </div>

                        <button
                            v-if="!showCurrencySelect && availableCurrencies.length > 0"
                            type="button"
                            @click="showCurrencySelect = true"
                            class="text-sm font-medium text-brand hover:text-brand-hover"
                        >
                            + Add price in another currency
                        </button>

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
                            <div class="mb-1 flex items-center justify-between">
                                <label for="category" :class="fieldLabel + ' mb-0'">
                                    {{ t('products.category') }} <span class="text-status-danger">*</span>
                                </label>
                                <button type="button" @click="showCategoryModal = true" class="text-xs font-medium text-brand hover:text-brand-hover">
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
                            <div class="mb-1 flex items-center justify-between">
                                <label for="location" :class="fieldLabel + ' mb-0'">
                                    {{ t('products.location') }} <span class="text-status-danger">*</span>
                                </label>
                                <button type="button" @click="showLocationModal = true" class="text-xs font-medium text-brand hover:text-brand-hover">
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
                <div class="lg:col-span-2">
                    <Card :padded="false">
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
                </div>

                <!-- Product Variants Section (Full Width) -->
                <div class="lg:col-span-2">
                    <Card :padded="false">
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
                            <p class="mb-4 text-sm text-text-tertiary">{{ t('products.create.variantHint') }}</p>
                            <ProductVariantManager
                                :model-value="variantData"
                                @update:model-value="updateVariantData"
                                :product-price="form.price"
                                :product-purchase-price="form.purchase_price"
                                :currency-symbol="getCurrencySymbol(form.currency)"
                            />
                        </div>
                    </Card>
                </div>

                <!-- Notes (Full Width) -->
                <div class="lg:col-span-2">
                    <Card :padded="false">
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
            </div>

            <!-- Form Actions -->
            <div class="mt-6 flex items-center justify-end gap-2">
                <Button variant="secondary" as="Link" :href="route('products.index')">{{ t('common.cancel') }}</Button>
                <Button type="submit" variant="default" :loading="form.processing" :disabled="form.processing">
                    {{ t('products.create.createProduct') }}
                </Button>
            </div>
        </form>

        <!-- Plugin Slot: After Form -->
        <PluginSlot slot="after-form" :components="pluginComponents?.afterForm" />

        <!-- Category Quick-Add Modal -->
        <QuickAddModal
            :show="showCategoryModal"
            :title="t('products.quickAddCategory.title')"
            :loading="categoryLoading"
            @close="showCategoryModal = false"
        >
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
            <template #actions>
                <Button type="button" variant="secondary" @click="showCategoryModal = false">
                    {{ t('common.cancel') }}
                </Button>
                <Button
                    type="button"
                    variant="default"
                    :loading="categoryLoading"
                    :disabled="categoryLoading || !categoryForm.name"
                    @click="createCategory"
                >
                    <span v-if="categoryLoading">{{ t('common.creating') }}</span>
                    <span v-else>{{ t('categories.createCategory') }}</span>
                </Button>
            </template>
        </QuickAddModal>

        <!-- Location Quick-Add Modal -->
        <QuickAddModal
            :show="showLocationModal"
            :title="t('products.quickAddLocation.title')"
            :loading="locationLoading"
            @close="showLocationModal = false"
        >
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
            <template #actions>
                <Button type="button" variant="secondary" @click="showLocationModal = false">
                    {{ t('common.cancel') }}
                </Button>
                <Button
                    type="button"
                    variant="default"
                    :loading="locationLoading"
                    :disabled="locationLoading || !locationForm.name || !locationForm.code"
                    @click="createLocation"
                >
                    <span v-if="locationLoading">{{ t('common.creating') }}</span>
                    <span v-else>{{ t('locations.createLocation') }}</span>
                </Button>
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
    </AppLayout>
</template>
