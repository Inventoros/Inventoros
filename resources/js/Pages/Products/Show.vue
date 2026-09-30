<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PluginTabs from '@/Components/PluginTabs.vue';
import ActivityTimeline from '@/Components/ActivityTimeline.vue';
import VariantsTable from '@/Components/VariantsTable.vue';
import BatchList from '@/Components/BatchList.vue';
import SerialList from '@/Components/SerialList.vue';
import WarehouseStockLevels from '@/Components/WarehouseStockLevels.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, onMounted, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatPercent } from '@/lib/reportFormat';
import axios from 'axios';
import ImageGallery from '@/Components/ImageGallery.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney } from '@/lib/money';
import { displayDate, displayDateTime } from '@/lib/dates';
import { onHandStock, stockStatus as stockStatusOf, stockValue } from '@/lib/productStock';
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
    Truck,
    QrCode,
} from 'lucide-vue-next';

const { t } = useI18n();
const { hasPermission, canVisit } = usePermissions();

const props = defineProps({
    product: Object,
    activities: Array,
    locationBreakdown: { type: Array, default: () => [] },
    warehouseStockLevels: { type: Array, default: () => [] },
    priceHistory: { type: Array, default: () => [] },
    pluginComponents: Object,
});

const barcodeImage = ref(null);
const barcodeTypeLabel = ref('');
const barcodeLoading = ref(false);

// QR code: encodes the SKU (any scanner, in-app lookup) or a link to this page.
const qrMode = ref('sku');
const qrImage = ref(null);
const qrPayload = ref('');

const loadQrCode = async () => {
    try {
        const response = await axios.get(route('products.qr.generate', { product: props.product.id, mode: qrMode.value }));
        qrImage.value = response.data.qr;
        qrPayload.value = response.data.payload;
    } catch (error) {
        console.error('Failed to load QR code:', error);
    }
};

watch(qrMode, loadQrCode);

const printQrLabel = () => {
    window.open(route('products.qr.print', { product: props.product.id, mode: qrMode.value }), '_blank');
};

const formatCurrency = (value) => {
    return formatMoney(value, props.product.currency);
};

// A product sold by variant keeps its stock on the variants: these read the
// active variants (and follow edits made in the variant table).
const onHand = computed(() => onHandStock(props.product, variants.value));
const valueAtPrice = computed(() => stockValue(props.product, variants.value, 'price'));
const valueAtCost = computed(() => stockValue(props.product, variants.value, 'purchase_price'));

const stockStatus = computed(() => ({
    out_of_stock: { text: t('products.show.outOfStock'), variant: 'danger' },
    low_stock: { text: t('products.show.lowStock'), variant: 'warning' },
    in_stock: { text: t('products.show.inStock'), variant: 'success' },
}[stockStatusOf(props.product, variants.value)]));

// Supplier links, primary first
const productSuppliers = computed(() =>
    [...(props.product.suppliers || [])].sort(
        (a, b) => Number(b.pivot?.is_primary) - Number(a.pivot?.is_primary)
    )
);

const formatDate = (iso) => displayDate(iso);

const supplierThClass = 'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';

// Load barcode on mount if product has barcode or SKU
onMounted(() => {
    if (props.product.barcode || props.product.sku) {
        loadBarcode();
    }
    loadQrCode();
});

const loadBarcode = async () => {
    barcodeLoading.value = true;
    try {
        const response = await axios.get(route('products.barcode.generate', props.product.id));
        barcodeImage.value = response.data.barcode;
        barcodeTypeLabel.value = response.data.type_label || '';
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
    if (!confirm(t('products.show.confirmGenerateRandom'))) return;

    try {
        await axios.post(route('products.barcode.generate-random', props.product.id));
        router.reload({ only: ['product'] });
        setTimeout(loadBarcode, 100);
    } catch (error) {
        console.error('Failed to generate barcode:', error);
        alert(t('products.show.generateBarcodeFailed'));
    }
};

const generateFromSKU = async () => {
    if (!confirm(t('products.show.confirmGenerateFromSku'))) return;

    try {
        await axios.post(route('products.barcode.generate-from-sku', props.product.id));
        router.reload({ only: ['product'] });
        setTimeout(loadBarcode, 100);
    } catch (error) {
        console.error('Failed to generate barcode:', error);
        alert(t('products.show.generateFromSkuFailed'));
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
        alert(error.response?.data?.message || t('products.show.bom.addFailed'));
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
        alert(t('products.show.bom.updateQuantityFailed'));
    }
};

const removeComponent = async (component) => {
    if (!confirm(t('products.show.bom.confirmRemove'))) return;
    try {
        await axios.delete(route('products.components.destroy', [props.product.id, component.id]));
        router.reload({ only: ['product'] });
    } catch (error) {
        alert(t('products.show.bom.removeFailed'));
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
    <Head :title="product.display_name ?? product.name" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('products.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('products.index')" class="text-text-tertiary hover:text-text-primary">{{ t('products.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ product.display_name ?? product.name }}</span>
            </div>
        </template>

        <PageHeader :title="product.display_name ?? product.name" :description="`SKU: ${product.sku}`">
            <template #actions>
                <Button variant="secondary" size="sm" @click="duplicateProduct">
                    <Copy :size="14" />
                    {{ t('products.duplicate') }}
                </Button>
                <Button v-if="canVisit('products.edit')" variant="default" size="sm" as="Link" :href="route('products.edit', product.id)">
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

        <!-- Plugin tabs: none registered renders the core content unchanged -->
        <PluginTabs :components="pluginComponents?.tabs">
            <!-- Key metrics -->
            <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <StatTile
                    :label="t('products.show.currentStock')"
                    :value="onHand"
                    :hint="t('products.show.minHint', { count: product.min_stock })"
                    icon-tone="brand"
                >
                    <template #icon><Boxes :size="18" /></template>
                </StatTile>
                <StatTile
                    :label="t('products.show.sellingPrice')"
                    :value="formatCurrency(product.display_price ?? product.price)"
                    :hint="product.currency || 'USD'"
                    icon-tone="success"
                >
                    <template #icon><DollarSign :size="18" /></template>
                </StatTile>
                <StatTile
                    :label="t('products.show.totalValue')"
                    :value="formatCurrency(valueAtPrice)"
                    :hint="t('products.show.stockAtPrice')"
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
                                    <h3 class="text-lg font-semibold text-text-primary">{{ product.display_name ?? product.name }}</h3>
                                    <div v-if="product.type && product.type !== 'standard'" class="mt-1">
                                        <Badge :variant="product.type === 'kit' ? 'info' : 'brand'" size="sm">
                                            {{ product.type === 'kit' ? t('products.types.kit') : t('products.types.assembly') }}
                                        </Badge>
                                    </div>
                                    <p class="mt-1 text-sm text-text-tertiary">SKU: {{ product.sku }}</p>
                                    <p v-if="product.barcode" class="text-sm text-text-tertiary">{{ t('products.show.barcodeValue', { barcode: product.barcode }) }}</p>
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
                                <div v-if="locationBreakdown.length" class="sm:col-span-2">
                                    <h4 class="mb-1 text-sm font-medium text-text-tertiary">{{ t('products.show.stockByLocation') }}</h4>
                                    <ul class="divide-y divide-border-subtle rounded-md border border-border-subtle">
                                        <li
                                            v-for="bin in locationBreakdown"
                                            :key="bin.id"
                                            class="flex items-center justify-between px-3 py-1.5 text-sm"
                                        >
                                            <span class="text-text-primary">
                                                {{ bin.location?.name || t('products.show.noLocation') }}
                                                <span v-if="bin.location?.code" class="text-text-tertiary">({{ bin.location.code }})</span>
                                            </span>
                                            <span class="tabular-nums font-medium text-text-primary">{{ bin.quantity }}</span>
                                        </li>
                                    </ul>
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
                                        {{ formatCurrency(product.display_price ?? product.price) }}
                                    </p>
                                    <p v-if="product.currency" class="mt-1 text-xs text-text-tertiary">
                                        {{ t('products.show.currencyValue', { currency: product.currency }) }}
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
                                        {{ formatPercent(((product.price - product.purchase_price) / product.price * 100)) }}
                                    </p>
                                </div>
                                <div>
                                    <h4 class="mb-1 text-xs font-medium text-status-success">{{ t('products.show.totalProfitInStock') }}</h4>
                                    <p class="text-lg font-bold tabular-nums text-status-success">
                                        {{ formatCurrency(valueAtPrice - valueAtCost) }}
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
                                            {{ formatMoney(price, currency) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Card>

                    <!-- Per-warehouse stock and thresholds -->
                    <WarehouseStockLevels
                        v-if="warehouseStockLevels.length"
                        :product-id="product.id"
                        :levels="warehouseStockLevels"
                        :can-edit="hasPermission('edit_products')"
                        :product-levels="{
                            min_stock: product.min_stock,
                            reorder_point: product.reorder_point,
                            reorder_quantity: product.reorder_quantity,
                            max_stock: product.max_stock,
                        }"
                    />

                    <!-- Suppliers -->
                    <Card :padded="false">
                        <div class="flex items-center justify-between px-5 pt-5">
                            <h3 class="text-sm font-semibold text-text-primary">{{ t('productSuppliers.title') }}</h3>
                            <Button v-if="(hasPermission('edit_products')) && canVisit('products.edit')" variant="ghost" size="sm" as="Link" :href="route('products.edit', product.id)">
                                <Pencil :size="14" />
                                {{ t('common.edit') }}
                            </Button>
                        </div>
                        <div class="p-5">
                            <div v-if="productSuppliers.length > 0" class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-border-subtle">
                                            <th :class="supplierThClass">{{ t('productSuppliers.supplier') }}</th>
                                            <th :class="supplierThClass">{{ t('productSuppliers.supplierSku') }}</th>
                                            <th :class="[supplierThClass, 'text-right']">{{ t('productSuppliers.costPrice') }}</th>
                                            <th :class="[supplierThClass, 'text-right']">{{ t('productSuppliers.leadTimeDays') }}</th>
                                            <th :class="[supplierThClass, 'text-right']">{{ t('productSuppliers.minimumOrderQuantity') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="supplier in productSuppliers" :key="supplier.id" class="border-b border-border-subtle last:border-b-0">
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-2">
                                                    <Link v-if="canVisit('suppliers.show')" :href="route('suppliers.show', supplier.id)" class="font-medium text-brand hover:underline">{{ supplier.name }}</Link>
                                                    <span v-else class="font-medium text-text-primary">{{ supplier.name }}</span>
                                                    <Badge v-if="supplier.pivot?.is_primary" variant="brand" size="sm">{{ t('productSuppliers.primary') }}</Badge>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 font-mono text-xs text-text-secondary">{{ supplier.pivot?.supplier_sku || '-' }}</td>
                                            <td class="px-4 py-3 text-right tabular-nums text-text-primary">{{ supplier.pivot?.cost_price != null ? formatCurrency(supplier.pivot.cost_price) : '-' }}</td>
                                            <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ supplier.pivot?.lead_time_days ?? '-' }}</td>
                                            <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ supplier.pivot?.minimum_order_quantity ?? '-' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-else class="flex flex-col items-center gap-2 py-6 text-center">
                                <Truck :size="20" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">{{ t('productSuppliers.empty') }}</p>
                                <Link v-if="(hasPermission('edit_products')) && canVisit('products.edit')" :href="route('products.edit', product.id)" class="text-sm font-medium text-brand hover:underline">
                                    {{ t('productSuppliers.add') }}
                                </Link>
                            </div>
                        </div>
                    </Card>

                    <!-- Supplier price history -->
                    <Card v-if="priceHistory.length > 0" :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('productSuppliers.priceHistory') }}</h3></div>
                        <div class="p-5">
                            <div class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-border-subtle">
                                            <th :class="supplierThClass">{{ t('productSuppliers.recordedAt') }}</th>
                                            <th :class="supplierThClass">{{ t('productSuppliers.supplier') }}</th>
                                            <th :class="[supplierThClass, 'text-right']">{{ t('productSuppliers.costPrice') }}</th>
                                            <th :class="supplierThClass">{{ t('productSuppliers.source') }}</th>
                                            <th :class="supplierThClass">{{ t('productSuppliers.recordedBy') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="entry in priceHistory" :key="entry.id" class="border-b border-border-subtle last:border-b-0">
                                            <td class="whitespace-nowrap px-4 py-2.5 text-text-secondary">{{ formatDate(entry.recorded_at) }}</td>
                                            <td class="px-4 py-2.5 text-text-primary">
                                                {{ entry.supplier_name || '-' }}
                                                <span v-if="entry.variant_title" class="block text-xs text-text-tertiary">{{ entry.variant_title }}</span>
                                            </td>
                                            <td class="px-4 py-2.5 text-right tabular-nums text-text-primary">{{ formatCurrency(entry.cost_price) }}</td>
                                            <td class="px-4 py-2.5 text-text-secondary">
                                                <Link
                                                    v-if="entry.source === 'purchase_order' && entry.purchase_order_id && canVisit('purchase-orders.show')"
                                                    :href="route('purchase-orders.show', entry.purchase_order_id)"
                                                    class="text-brand hover:underline"
                                                >
                                                    {{ t('productSuppliers.sourcePurchaseOrder', { number: entry.po_number }) }}
                                                </Link>
                                                <span v-else-if="entry.source === 'purchase_order' && entry.purchase_order_id">{{ t('productSuppliers.sourcePurchaseOrder', { number: entry.po_number }) }}</span>
                                                <span v-else>{{ t('productSuppliers.sourceLink') }}</span>
                                            </td>
                                            <td class="px-4 py-2.5 text-text-tertiary">{{ entry.user_name || '-' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </Card>

                    <!-- Product Variants -->
                    <Card v-if="product.has_variants && variants.length > 0" :padded="false">
                        <div class="flex items-center justify-between px-5 pt-5">
                            <h3 class="text-sm font-semibold text-text-primary">{{ t('products.show.variants') }}</h3>
                            <Badge variant="brand" size="sm">{{ t('products.variantsCount', { count: variants.length }, variants.length) }}</Badge>
                        </div>
                        <div class="p-5">
                            <VariantsTable
                                :variants="variants"
                                :product-id="product.id"
                                :currency-symbol="getCurrencySymbol()"
                                :product-price="product.price"
                                :show-stock-adjust="true"
                                @variant-updated="onVariantUpdated"
                            />

                            <!-- Variant Stock Note -->
                            <div class="mt-4 flex items-start gap-2 rounded-lg border border-status-info/20 bg-status-info-soft p-3">
                                <Info :size="16" class="mt-0.5 shrink-0 text-status-info" />
                                <p class="text-sm text-status-info">
                                    {{ t('products.show.variantStockTracked') }}: <span class="font-semibold">{{ totalVariantStock }}</span>
                                </p>
                            </div>
                        </div>
                    </Card>

                    <!-- Components (BOM) for Kits/Assemblies -->
                    <Card v-if="isKitOrAssembly" :padded="false">
                        <div class="flex items-center justify-between px-5 pt-5">
                            <div class="flex items-center gap-3">
                                <h3 class="text-sm font-semibold text-text-primary">{{ t('products.show.bom.title') }}</h3>
                                <Badge :variant="product.type === 'kit' ? 'info' : 'brand'" size="sm">
                                    {{ product.type === 'kit' ? t('products.types.kit') : t('products.types.assembly') }}
                                </Badge>
                            </div>
                            <Button variant="default" size="sm" @click="showAddComponent = !showAddComponent">
                                <Plus :size="14" />
                                {{ t('products.show.bom.addComponent') }}
                            </Button>
                        </div>
                        <div class="p-5">
                            <!-- Kit Available Stock -->
                            <div v-if="product.type === 'kit' && components.length > 0" class="mb-4 flex items-center justify-between rounded-lg border border-status-info/20 bg-status-info-soft p-3">
                                <span class="flex items-center gap-2 text-sm text-status-info">
                                    <Info :size="16" class="shrink-0" />
                                    {{ t('products.show.bom.availableKitStock') }}
                                </span>
                                <span class="text-lg font-bold text-status-info">{{ availableKitStock }}</span>
                            </div>

                            <!-- Assembly: Create Work Order button -->
                            <div v-if="product.type === 'assembly' && components.length > 0" class="mb-4">
                                <Button v-if="canVisit('work-orders.create')" variant="default" size="md" as="Link" :href="route('work-orders.create', { product_id: product.id })">
                                    <Settings2 :size="16" />
                                    {{ t('workOrders.createWorkOrder') }}
                                </Button>
                            </div>

                            <!-- Add Component Form -->
                            <div v-if="showAddComponent" class="mb-4 rounded-lg border border-border-subtle bg-surface-canvas p-4">
                                <h4 class="mb-3 text-sm font-medium text-text-primary">{{ t('products.show.bom.addComponent') }}</h4>
                                <div class="flex items-end gap-3">
                                    <div class="relative flex-1">
                                        <label class="mb-1 block text-xs text-text-tertiary">{{ t('products.show.bom.searchProduct') }}</label>
                                        <input
                                            v-model="componentSearch"
                                            type="text"
                                            :placeholder="t('products.show.bom.searchPlaceholder')"
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
                                                <span class="ml-2 text-text-tertiary">{{ t('products.show.bom.stockInline', { count: result.stock }) }}</span>
                                            </button>
                                        </div>
                                        <div v-if="componentSearching" class="absolute z-10 mt-1 w-full rounded-lg border border-border-subtle bg-surface-raised p-3 text-center shadow-lg">
                                            <span class="text-sm text-text-tertiary">{{ t('products.show.bom.searching') }}</span>
                                        </div>
                                    </div>
                                    <div class="w-28">
                                        <label class="mb-1 block text-xs text-text-tertiary">{{ t('common.quantity') }}</label>
                                        <input
                                            v-model.number="newComponentQty"
                                            type="number"
                                            min="1"
                                            :class="fieldInput"
                                        />
                                    </div>
                                    <Button type="button" variant="default" @click="addComponent" :disabled="!selectedComponent">
                                        {{ t('common.add') }}
                                    </Button>
                                    <Button type="button" variant="secondary" @click="showAddComponent = false">
                                        {{ t('common.cancel') }}
                                    </Button>
                                </div>
                            </div>

                            <!-- Components Table -->
                            <div v-if="components.length > 0" class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                                <table class="min-w-full">
                                    <thead>
                                        <tr class="border-b border-border-subtle">
                                            <th :class="thClass">{{ t('common.product') }}</th>
                                            <th :class="thClass">{{ t('products.show.sku') }}</th>
                                            <th :class="[thClass, 'text-center']">{{ t('products.show.bom.qtyRequired') }}</th>
                                            <th :class="[thClass, 'text-center']">{{ t('products.show.bom.availableStock') }}</th>
                                            <th :class="[thClass, 'text-right']">{{ t('common.actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="comp in components" :key="comp.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                                            <td class="px-4 py-3">
                                                <Link :href="route('products.show', comp.component?.id || comp.component_product_id)" class="text-sm font-medium text-brand hover:underline">
                                                    {{ comp.component?.name || t('products.show.bom.unknown') }}
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
                                                        <button @click="saveComponentQty(comp)" class="text-sm text-status-success hover:underline">{{ t('common.save') }}</button>
                                                        <button @click="editingComponentId = null" class="text-sm text-text-tertiary hover:text-text-primary">{{ t('common.cancel') }}</button>
                                                    </template>
                                                    <template v-else>
                                                        <button @click="startEditComponent(comp)" class="text-sm text-brand hover:underline">{{ t('common.edit') }}</button>
                                                        <button @click="removeComponent(comp)" class="text-sm text-status-danger hover:underline">{{ t('products.show.bom.remove') }}</button>
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
                                <p class="text-sm text-text-tertiary">{{ t('products.show.bom.empty') }}</p>
                                <p class="text-xs text-text-tertiary">{{ t('products.show.bom.emptyHint') }}</p>
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
                                    <img :src="barcodeImage" :alt="t('products.show.barcode')" class="h-auto max-w-full" />
                                </div>

                                <div class="text-center">
                                    <p class="font-mono text-sm text-text-secondary">
                                        {{ product.barcode || product.sku }}
                                    </p>
                                    <p v-if="barcodeTypeLabel" class="mt-0.5 text-xs text-text-tertiary">{{ barcodeTypeLabel }}</p>
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

                    <!-- QR Code -->
                    <Card :padded="false">
                        <div class="flex items-center justify-between gap-3 px-5 pt-5">
                            <h3 class="text-sm font-semibold text-text-primary">{{ t('products.show.qrCode') }}</h3>
                            <div class="inline-flex rounded-md border border-border-subtle p-0.5" role="radiogroup" :aria-label="t('products.show.qrCode')">
                                <button
                                    v-for="option in [{ value: 'sku', label: t('products.show.qrModeSku') }, { value: 'url', label: t('products.show.qrModeUrl') }]"
                                    :key="option.value"
                                    type="button"
                                    role="radio"
                                    :aria-checked="qrMode === option.value"
                                    :class="[
                                        'rounded px-2 py-1 text-xs font-medium transition-colors ds-focus-ring',
                                        qrMode === option.value ? 'bg-brand text-brand-foreground' : 'text-text-secondary hover:bg-surface-overlay',
                                    ]"
                                    @click="qrMode = option.value"
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                        </div>
                        <div class="space-y-3 p-5">
                            <div v-if="qrImage" class="flex justify-center rounded-lg border border-border-subtle bg-white p-4">
                                <img :src="qrImage" :alt="t('products.show.qrCode')" class="h-40 w-40" />
                            </div>
                            <p class="break-all text-center font-mono text-xs text-text-secondary">{{ qrPayload }}</p>
                            <p class="text-xs text-text-tertiary">{{ t('products.show.qrModeHint') }}</p>
                            <Button variant="secondary" class="w-full" @click="printQrLabel">
                                <QrCode :size="16" />
                                {{ t('products.show.printQrLabel') }}
                            </Button>
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
                                        :class="onHand <= product.min_stock ? 'text-status-danger' : 'text-brand'"
                                    >
                                        {{ onHand }}
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
                                        {{ formatCurrency(valueAtPrice) }}
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
                                        {{ displayDateTime(product.created_at) }}
                                    </p>
                                </div>
                                <div>
                                    <p class="mb-1 text-xs text-text-tertiary">{{ t('common.updatedAt') }}</p>
                                    <p class="text-sm text-text-primary">
                                        {{ displayDateTime(product.updated_at) }}
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
        </PluginTabs>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
    </AppLayout>
</template>
