<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
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
import {
    Boxes,
    DollarSign,
    Wallet,
    Copy,
    Pencil,
    ArrowLeft,
    Plus,
    Printer,
    Info,
    Settings2,
    Package,
} from '@lucide/vue';

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
        return { text: t('products.show.outOfStock'), variant: 'danger' };
    }
    if (props.product.stock <= props.product.min_stock) {
        return { text: t('products.show.lowStock'), variant: 'warning' };
    }
    return { text: t('products.show.inStock'), variant: 'success' };
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
    const symbols = { USD: '$', EUR: '€', GBP: '£', JPY: '¥' };
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

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
</script>

<template>
    <Head :title="product.name" />

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

        <PageHeader :title="product.name" :description="`SKU: ${product.sku}`">
            <template #actions>
                <Button variant="secondary" size="sm" @click="duplicateProduct">
                    <Copy :size="14" />
                    Duplicate
                </Button>
                <Button variant="default" size="sm" as="Link" :href="route('products.edit', product.id)">
                    <Pencil :size="14" />
                    {{ t('common.edit') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('products.index')">
                    <ArrowLeft :size="14" />
                    {{ t('products.show.backToInventory') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <!-- Key metrics -->
        <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <StatTile
                :label="t('products.show.currentStock')"
                :value="product.stock"
                :hint="`min ${product.min_stock}`"
                icon-tone="brand"
            >
                <template #icon><Boxes :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('products.show.sellingPrice')"
                :value="formatCurrency(product.price)"
                :hint="product.currency || 'USD'"
                icon-tone="success"
            >
                <template #icon><DollarSign :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('products.show.totalValue')"
                :value="formatCurrency(product.price * product.stock)"
                hint="stock at price"
                icon-tone="violet"
            >
                <template #icon><Wallet :size="18" /></template>
            </StatTile>
        </section>

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Main Info -->
            <div class="space-y-4 lg:col-span-2">
                <!-- Basic Information -->
                <Card :padded="false">
                    <div class="p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <h3 class="text-lg font-semibold text-text-primary">{{ product.name }}</h3>
                                <div v-if="product.type && product.type !== 'standard'" class="mt-1">
                                    <Badge :variant="product.type === 'kit' ? 'info' : 'brand'" size="sm">
                                        {{ product.type === 'kit' ? 'Kit' : 'Assembly' }}
                                    </Badge>
                                </div>
                                <p class="mt-1 text-sm text-text-tertiary">SKU: {{ product.sku }}</p>
                                <p v-if="product.barcode" class="text-sm text-text-tertiary">Barcode: {{ product.barcode }}</p>
                            </div>
                            <Badge :variant="stockStatus.variant" size="md" dot>{{ stockStatus.text }}</Badge>
                        </div>

                        <div v-if="product.description" class="mt-6">
                            <h4 class="mb-2 text-sm font-medium text-text-tertiary">{{ t('common.description') }}</h4>
                            <p class="text-text-primary">{{ product.description }}</p>
                        </div>

                        <div v-if="product.notes" class="mt-6 rounded-lg border border-status-warning/20 bg-status-warning-soft p-4">
                            <h4 class="mb-2 text-sm font-medium text-status-warning">{{ t('common.notes') }}</h4>
                            <p class="text-sm text-status-warning">{{ product.notes }}</p>
                        </div>

                        <div class="mt-6 grid grid-cols-2 gap-4">
                            <div>
                                <h4 class="mb-1 text-sm font-medium text-text-tertiary">{{ t('products.category') }}</h4>
                                <p class="text-text-primary">
                                    {{ product.category?.name || t('products.show.uncategorized') }}
                                </p>
                            </div>
                            <div>
                                <h4 class="mb-1 text-sm font-medium text-text-tertiary">{{ t('products.location') }}</h4>
                                <p class="text-text-primary">
                                    {{ product.location?.name || t('products.show.noLocation') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- Pricing -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('products.show.pricingInfo') }}</h3></div>
                    <div class="p-5">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <h4 class="mb-1 text-sm font-medium text-text-tertiary">{{ t('products.show.sellingPrice') }}</h4>
                                <p class="text-2xl font-bold tabular-nums text-text-primary">
                                    {{ formatCurrency(product.price) }}
                                </p>
                                <p v-if="product.currency" class="mt-1 text-xs text-text-tertiary">
                                    Currency: {{ product.currency }}
                                </p>
                            </div>
                            <div v-if="product.purchase_price">
                                <h4 class="mb-1 text-sm font-medium text-text-tertiary">{{ t('products.show.purchasePrice') }}</h4>
                                <p class="text-2xl font-bold tabular-nums text-text-primary">
                                    {{ formatCurrency(product.purchase_price) }}
                                </p>
                                <p class="mt-1 text-xs text-text-tertiary">
                                    {{ t('products.show.whatYouPaid') }}
                                </p>
                            </div>
                        </div>

                        <!-- Profit Information -->
                        <div v-if="product.purchase_price && product.price" class="mt-6 grid grid-cols-3 gap-4 rounded-lg border border-status-success/20 bg-status-success-soft p-4">
                            <div>
                                <h4 class="mb-1 text-xs font-medium text-status-success">{{ t('products.show.profitPerUnit') }}</h4>
                                <p class="text-lg font-bold tabular-nums text-status-success">
                                    {{ formatCurrency(product.price - product.purchase_price) }}
                                </p>
                            </div>
                            <div>
                                <h4 class="mb-1 text-xs font-medium text-status-success">{{ t('products.show.profitMargin') }}</h4>
                                <p class="text-lg font-bold tabular-nums text-status-success">
                                    {{ ((product.price - product.purchase_price) / product.price * 100).toFixed(1) }}%
                                </p>
                            </div>
                            <div>
                                <h4 class="mb-1 text-xs font-medium text-status-success">{{ t('products.show.totalProfitInStock') }}</h4>
                                <p class="text-lg font-bold tabular-nums text-status-success">
                                    {{ formatCurrency((product.price - product.purchase_price) * product.stock) }}
                                </p>
                            </div>
                        </div>

                        <!-- Additional Currencies -->
                        <div v-if="product.price_in_currencies && Object.keys(product.price_in_currencies).length > 0" class="mt-6">
                            <h4 class="mb-3 text-sm font-medium text-text-tertiary">{{ t('products.show.altCurrencies') }}</h4>
                            <div class="grid grid-cols-3 gap-3">
                                <div
                                    v-for="(price, currency) in product.price_in_currencies"
                                    :key="currency"
                                    class="rounded-lg border border-border-subtle bg-surface-canvas p-3"
                                >
                                    <p class="text-xs text-text-tertiary">{{ currency }}</p>
                                    <p class="text-lg font-semibold tabular-nums text-text-primary">
                                        {{ new Intl.NumberFormat('en-US', { style: 'currency', currency: currency }).format(price) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- Product Variants -->
                <Card v-if="product.has_variants && variants.length > 0" :padded="false">
                    <div class="flex items-center justify-between px-5 pt-5">
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('products.show.variants') }}</h3>
                        <Badge variant="brand" size="sm">{{ variants.length }} variants</Badge>
                    </div>
                    <div class="p-5">
                        <VariantsTable
                            :variants="variants"
                            :product-id="product.id"
                            :currency-symbol="getCurrencySymbol()"
                            :show-stock-adjust="true"
                            @variant-updated="onVariantUpdated"
                        />

                        <!-- Variant Stock Note -->
                        <div class="mt-4 flex items-start gap-2 rounded-lg border border-status-info/20 bg-status-info-soft p-3">
                            <Info :size="16" class="mt-0.5 shrink-0 text-status-info" />
                            <p class="text-sm text-status-info">
                                Stock is tracked per variant. Total variant stock: <span class="font-semibold">{{ totalVariantStock }}</span>
                            </p>
                        </div>
                    </div>
                </Card>

                <!-- Components (BOM) for Kits/Assemblies -->
                <Card v-if="isKitOrAssembly" :padded="false">
                    <div class="flex items-center justify-between px-5 pt-5">
                        <div class="flex items-center gap-3">
                            <h3 class="text-sm font-semibold text-text-primary">Bill of Materials</h3>
                            <Badge :variant="product.type === 'kit' ? 'info' : 'brand'" size="sm">
                                {{ product.type === 'kit' ? 'Kit' : 'Assembly' }}
                            </Badge>
                        </div>
                        <Button variant="default" size="sm" @click="showAddComponent = !showAddComponent">
                            <Plus :size="14" />
                            Add Component
                        </Button>
                    </div>
                    <div class="p-5">
                        <!-- Kit Available Stock -->
                        <div v-if="product.type === 'kit' && components.length > 0" class="mb-4 flex items-center justify-between rounded-lg border border-status-info/20 bg-status-info-soft p-3">
                            <span class="flex items-center gap-2 text-sm text-status-info">
                                <Info :size="16" class="shrink-0" />
                                Available Kit Stock (limited by lowest component)
                            </span>
                            <span class="text-lg font-bold text-status-info">{{ availableKitStock }}</span>
                        </div>

                        <!-- Assembly: Create Work Order button -->
                        <div v-if="product.type === 'assembly' && components.length > 0" class="mb-4">
                            <Button variant="default" size="md" as="Link" :href="route('work-orders.create', { product_id: product.id })">
                                <Settings2 :size="16" />
                                Create Work Order
                            </Button>
                        </div>

                        <!-- Add Component Form -->
                        <div v-if="showAddComponent" class="mb-4 rounded-lg border border-border-subtle bg-surface-canvas p-4">
                            <h4 class="mb-3 text-sm font-medium text-text-primary">Add Component</h4>
                            <div class="flex items-end gap-3">
                                <div class="relative flex-1">
                                    <label class="mb-1 block text-xs text-text-tertiary">Search Product</label>
                                    <input
                                        v-model="componentSearch"
                                        type="text"
                                        placeholder="Search by name or SKU..."
                                        :class="fieldInput"
                                        @input="searchComponents"
                                    />
                                    <!-- Search Results Dropdown -->
                                    <div v-if="componentSearchResults.length > 0" class="absolute z-10 mt-1 max-h-48 w-full overflow-y-auto rounded-lg border border-border-subtle bg-surface-raised shadow-lg">
                                        <button
                                            v-for="result in componentSearchResults"
                                            :key="result.id"
                                            type="button"
                                            @click="selectComponent(result)"
                                            class="w-full px-3 py-2 text-left text-sm transition-colors hover:bg-surface-overlay"
                                        >
                                            <span class="text-text-primary">{{ result.name }}</span>
                                            <span class="ml-2 text-text-tertiary">{{ result.sku }}</span>
                                            <span class="ml-2 text-text-tertiary">(Stock: {{ result.stock }})</span>
                                        </button>
                                    </div>
                                    <div v-if="componentSearching" class="absolute z-10 mt-1 w-full rounded-lg border border-border-subtle bg-surface-raised p-3 text-center shadow-lg">
                                        <span class="text-sm text-text-tertiary">Searching...</span>
                                    </div>
                                </div>
                                <div class="w-28">
                                    <label class="mb-1 block text-xs text-text-tertiary">Quantity</label>
                                    <input
                                        v-model.number="newComponentQty"
                                        type="number"
                                        min="1"
                                        :class="fieldInput"
                                    />
                                </div>
                                <Button type="button" variant="default" @click="addComponent" :disabled="!selectedComponent">
                                    Add
                                </Button>
                                <Button type="button" variant="secondary" @click="showAddComponent = false">
                                    Cancel
                                </Button>
                            </div>
                        </div>

                        <!-- Components Table -->
                        <div v-if="components.length > 0" class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                            <table class="min-w-full">
                                <thead>
                                    <tr class="border-b border-border-subtle">
                                        <th :class="thClass">Product</th>
                                        <th :class="thClass">SKU</th>
                                        <th :class="[thClass, 'text-center']">Qty Required</th>
                                        <th :class="[thClass, 'text-center']">Available Stock</th>
                                        <th :class="[thClass, 'text-right']">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="comp in components" :key="comp.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                                        <td class="px-4 py-3">
                                            <Link :href="route('products.show', comp.component?.id || comp.component_product_id)" class="text-sm font-medium text-brand hover:underline">
                                                {{ comp.component?.name || 'Unknown' }}
                                            </Link>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-text-tertiary">
                                            {{ comp.component?.sku || '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <template v-if="editingComponentId === comp.id">
                                                <input
                                                    v-model.number="editingComponentQty"
                                                    type="number"
                                                    min="1"
                                                    class="h-9 w-20 rounded-md border border-border-subtle bg-surface-canvas px-2 text-center text-sm text-text-primary ds-focus-ring"
                                                />
                                            </template>
                                            <template v-else>
                                                <span class="text-sm font-medium tabular-nums text-text-primary">{{ comp.quantity }}</span>
                                            </template>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span
                                                class="text-sm font-medium tabular-nums"
                                                :class="(comp.component?.stock || 0) >= comp.quantity ? 'text-status-success' : 'text-status-danger'"
                                            >
                                                {{ comp.component?.stock || 0 }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <div class="flex justify-end gap-2">
                                                <template v-if="editingComponentId === comp.id">
                                                    <button @click="saveComponentQty(comp)" class="text-sm text-status-success hover:underline">Save</button>
                                                    <button @click="editingComponentId = null" class="text-sm text-text-tertiary hover:text-text-primary">Cancel</button>
                                                </template>
                                                <template v-else>
                                                    <button @click="startEditComponent(comp)" class="text-sm text-brand hover:underline">Edit</button>
                                                    <button @click="removeComponent(comp)" class="text-sm text-status-danger hover:underline">Remove</button>
                                                </template>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Empty State -->
                        <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
                            <Package :size="22" class="text-text-tertiary" />
                            <p class="text-sm text-text-tertiary">No components added yet.</p>
                            <p class="text-xs text-text-tertiary">Click "Add Component" to build the bill of materials.</p>
                        </div>
                    </div>
                </Card>

                <!-- Batch Tracking -->
                <Card v-if="product.tracking_type === 'batch'">
                    <BatchList
                        :product-id="product.id"
                        :batches="product.batches || []"
                    />
                </Card>

                <!-- Serial Tracking -->
                <Card v-if="product.tracking_type === 'serial'">
                    <SerialList
                        :product-id="product.id"
                        :serials="product.serials || []"
                    />
                </Card>
            </div>

            <!-- Sidebar -->
            <div class="space-y-4">
                <!-- Plugin Slot: Sidebar -->
                <PluginSlot slot="sidebar" :components="pluginComponents?.sidebar" />

                <!-- Product Images -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('products.create.productImages') }}</h3></div>
                    <div class="p-5">
                        <ImageGallery
                            :images="productImages"
                            :product-name="product.name"
                        />
                    </div>
                </Card>

                <!-- Barcode -->
                <Card v-if="product.barcode || product.sku" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('products.show.barcode') }}</h3></div>
                    <div class="p-5">
                        <div v-if="barcodeLoading" class="flex items-center justify-center py-8">
                            <div class="h-8 w-8 animate-spin rounded-full border-b-2 border-brand"></div>
                        </div>

                        <div v-else-if="barcodeImage" class="space-y-4">
                            <div class="flex justify-center rounded-lg border border-border-subtle bg-white p-4">
                                <img :src="barcodeImage" alt="Barcode" class="h-auto max-w-full" />
                            </div>

                            <div class="text-center">
                                <p class="font-mono text-sm text-text-secondary">
                                    {{ product.barcode || product.sku }}
                                </p>
                            </div>

                            <Button variant="default" class="w-full" @click="printBarcode">
                                <Printer :size="16" />
                                {{ t('products.printBarcodes') }}
                            </Button>

                            <div class="space-y-2 border-t border-border-subtle pt-3">
                                <Button variant="secondary" class="w-full" @click="generateRandomBarcode">
                                    {{ t('products.show.generateNewRandom') }}
                                </Button>
                                <Button variant="secondary" class="w-full" @click="generateFromSKU">
                                    {{ t('products.show.generateFromSku') }}
                                </Button>
                            </div>
                        </div>

                        <div v-else class="py-4 text-center">
                            <p class="mb-3 text-sm text-text-tertiary">{{ t('products.show.noBarcodeAvailable') }}</p>
                            <Button variant="default" @click="generateRandomBarcode">
                                {{ t('products.show.generateBarcode') }}
                            </Button>
                        </div>
                    </div>
                </Card>

                <!-- Stock Information -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('products.show.stockInfo') }}</h3></div>
                    <div class="p-5">
                        <div class="space-y-4">
                            <div class="rounded-lg border border-brand/20 bg-brand-soft p-4">
                                <p class="mb-1 text-sm text-text-tertiary">{{ t('products.show.currentStock') }}</p>
                                <p
                                    class="text-3xl font-bold tabular-nums"
                                    :class="product.stock <= product.min_stock ? 'text-status-danger' : 'text-brand'"
                                >
                                    {{ product.stock }}
                                </p>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="rounded-lg border border-border-subtle bg-surface-canvas p-3">
                                    <p class="mb-1 text-xs text-text-tertiary">{{ t('products.show.minStock') }}</p>
                                    <p class="text-lg font-semibold tabular-nums text-text-primary">
                                        {{ product.min_stock }}
                                    </p>
                                </div>
                                <div v-if="product.max_stock" class="rounded-lg border border-border-subtle bg-surface-canvas p-3">
                                    <p class="mb-1 text-xs text-text-tertiary">{{ t('products.show.maxStock') }}</p>
                                    <p class="text-lg font-semibold tabular-nums text-text-primary">
                                        {{ product.max_stock }}
                                    </p>
                                </div>
                            </div>
                            <div class="rounded-lg border border-status-success/20 bg-status-success-soft p-3">
                                <p class="mb-1 text-xs text-text-tertiary">{{ t('products.show.totalValue') }}</p>
                                <p class="text-xl font-bold tabular-nums text-status-success">
                                    {{ formatCurrency(product.price * product.stock) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- Status -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('common.status') }}</h3></div>
                    <div class="p-5">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-text-tertiary">{{ t('common.active') }}</span>
                                <Badge :variant="product.is_active ? 'success' : 'neutral'" size="sm" dot>
                                    {{ product.is_active ? t('common.yes') : t('common.no') }}
                                </Badge>
                            </div>
                            <div class="border-t border-border-subtle pt-3">
                                <p class="mb-1 text-xs text-text-tertiary">{{ t('common.createdAt') }}</p>
                                <p class="text-sm text-text-primary">
                                    {{ new Date(product.created_at).toLocaleString() }}
                                </p>
                            </div>
                            <div>
                                <p class="mb-1 text-xs text-text-tertiary">{{ t('common.updatedAt') }}</p>
                                <p class="text-sm text-text-primary">
                                    {{ new Date(product.updated_at).toLocaleString() }}
                                </p>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Activity Timeline -->
        <Card :padded="false" class="mt-4">
            <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('products.show.activityHistory') }}</h3></div>
            <div class="p-5">
                <ActivityTimeline :activities="activities || []" />
            </div>
        </Card>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
    </AppLayout>
</template>

