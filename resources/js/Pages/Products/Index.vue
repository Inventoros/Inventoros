<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { defineAsyncComponent, ref, onMounted, onUnmounted } from 'vue';
import {
    Plus, Search, Eye, Pencil, Trash2, Copy, Barcode, ScanLine,
    AlertTriangle, Boxes, X,
} from 'lucide-vue-next';

// Defer the modal (which transitively imports html5-qrcode, ~200 KB)
// until the user actually opens the scanner.
const BarcodeScannerModal = defineAsyncComponent(() => import('@/Components/BarcodeScannerModal.vue'));
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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

// Bulk selection
const selectedProducts = ref([]);
const selectAll = ref(false);

// Bulk operations state
const showBulkCategoryModal = ref(false);
const showBulkPriceModal = ref(false);
const bulkCategoryId = ref('');
const bulkPriceType = ref('percentage');
const bulkPriceValue = ref(0);
const bulkProcessing = ref(false);

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
        if (showScannerModal.value) {
            closeScanner();
        } else {
            openScanner();
        }
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
        selectedProducts.value = props.products.data.map(p => p.id);
    } else {
        selectedProducts.value = [];
    }
};

const isSelected = (productId) => selectedProducts.value.includes(productId);

const toggleSelect = (productId) => {
    const idx = selectedProducts.value.indexOf(productId);
    if (idx > -1) {
        selectedProducts.value.splice(idx, 1);
    } else {
        selectedProducts.value.push(productId);
    }
    selectAll.value = props.products.data.length > 0 && selectedProducts.value.length === props.products.data.length;
};

const printSelectedBarcodes = () => {
    if (selectedProducts.value.length === 0) return;
    const ids = selectedProducts.value.join(',');
    window.open(route('products.barcode.bulk-print', { ids }), '_blank');
};

const printBarcode = (productId) => {
    window.open(route('products.barcode.print', productId), '_blank');
};

const formatCurrency = (value) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value);

const searchProducts = () => {
    router.get(route('products.index'), {
        search: search.value,
        category: category.value,
        location: location.value,
    }, { preserveState: true, preserveScroll: true });
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

const isLowStock = (product) => product.stock <= product.min_stock;

const bulkDelete = () => {
    if (!confirm(`Are you sure you want to delete ${selectedProducts.value.length} product(s)? This action cannot be undone.`)) return;
    bulkProcessing.value = true;
    router.post(route('products.bulk.delete'), { ids: selectedProducts.value }, {
        onSuccess: () => { selectedProducts.value = []; selectAll.value = false; bulkProcessing.value = false; },
        onError: () => { bulkProcessing.value = false; },
    });
};

const bulkUpdateCategory = () => {
    if (!bulkCategoryId.value) return;
    bulkProcessing.value = true;
    router.post(route('products.bulk.update-category'), { ids: selectedProducts.value, category_id: bulkCategoryId.value }, {
        onSuccess: () => { selectedProducts.value = []; selectAll.value = false; showBulkCategoryModal.value = false; bulkCategoryId.value = ''; bulkProcessing.value = false; },
        onError: () => { bulkProcessing.value = false; },
    });
};

const bulkUpdatePrice = () => {
    if (!bulkPriceValue.value) return;
    bulkProcessing.value = true;
    router.post(route('products.bulk.update-price'), { ids: selectedProducts.value, type: bulkPriceType.value, value: parseFloat(bulkPriceValue.value) }, {
        onSuccess: () => { selectedProducts.value = []; selectAll.value = false; showBulkPriceModal.value = false; bulkPriceValue.value = 0; bulkProcessing.value = false; },
        onError: () => { bulkProcessing.value = false; },
    });
};

const bulkExport = () => {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = route('products.bulk.export');
    form.style.display = 'none';
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = '_token';
    csrfInput.value = document.querySelector('meta[name="csrf-token"]')?.content;
    form.appendChild(csrfInput);
    selectedProducts.value.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ids[]';
        input.value = id;
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
};

const duplicateProduct = (product) => {
    router.post(route('products.duplicate', product.id));
};

const selectClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
const thClass =
    'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('products.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('products.title') }}</span>
            </div>
        </template>

        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <PageHeader :title="t('products.title')" description="Your catalog, stock levels, and reorder thresholds.">
            <template #actions>
                <Button variant="default" size="sm" as="Link" :href="route('products.create')">
                    <Plus :size="14" />
                    {{ t('products.addProduct') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Filters -->
        <Card class="mt-6">
            <form @submit.prevent="searchProducts" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div class="md:col-span-2">
                        <label for="search" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('products.searchProducts') }}</label>
                        <div class="relative">
                            <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                            <input id="search" v-model="search" type="text" :placeholder="t('products.searchPlaceholder')"
                                class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring" />
                        </div>
                    </div>
                    <div>
                        <label for="category" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('products.category') }}</label>
                        <select id="category" v-model="category" :class="selectClass">
                            <option value="">{{ t('products.allCategories') }}</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="location" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('products.location') }}</label>
                        <select id="location" v-model="location" :class="selectClass">
                            <option value="">{{ t('products.allLocations') }}</option>
                            <option v-for="loc in locations" :key="loc.id" :value="loc.id">{{ loc.name }}</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="submit" variant="default" size="sm"><Search :size="14" />{{ t('common.search') }}</Button>
                    <Button type="button" variant="secondary" size="sm" @click="clearFilters">{{ t('common.clearFilters') }}</Button>
                </div>
            </form>
        </Card>

        <PluginSlot slot="before-table" :components="pluginComponents?.beforeTable" />

        <!-- Bulk actions bar -->
        <div v-if="selectedProducts.length > 0" class="sticky top-12 z-30 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-brand/20 bg-brand-soft p-3">
            <div class="flex items-center gap-3">
                <span class="text-sm font-medium text-text-primary">{{ selectedProducts.length }} product{{ selectedProducts.length > 1 ? 's' : '' }} selected</span>
                <button @click="selectedProducts = []; selectAll = false" class="text-xs text-text-tertiary hover:text-text-primary">{{ t('common.clearSelection') }}</button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="default" size="sm" @click="printSelectedBarcodes"><Barcode :size="14" />{{ t('products.printBarcodes') }}</Button>
                <Button variant="secondary" size="sm" :disabled="bulkProcessing" @click="showBulkCategoryModal = true">Change category</Button>
                <Button variant="secondary" size="sm" :disabled="bulkProcessing" @click="showBulkPriceModal = true">Adjust price</Button>
                <Button variant="secondary" size="sm" :disabled="bulkProcessing" @click="bulkExport">Export</Button>
                <Button variant="danger" size="sm" :disabled="bulkProcessing" @click="bulkDelete"><Trash2 :size="14" />Delete</Button>
            </div>
        </div>

        <!-- Products table -->
        <div class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th class="w-10 px-4 py-2.5">
                            <input type="checkbox" v-model="selectAll" @change="toggleSelectAll" class="rounded border-border-strong bg-surface-canvas text-brand focus:ring-brand" />
                        </th>
                        <th :class="thClass">{{ t('products.productCol') }}</th>
                        <th :class="thClass">{{ t('products.skuBarcode') }}</th>
                        <th :class="thClass">{{ t('products.category') }}</th>
                        <th :class="thClass">{{ t('products.location') }}</th>
                        <th :class="thClass">{{ t('products.stock') }}</th>
                        <th :class="thClass">{{ t('common.price') }}</th>
                        <th :class="[thClass, 'text-right']">{{ t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="products.data.length === 0">
                        <td colspan="8" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <Boxes :size="22" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">{{ t('products.noProductsFound') }}</p>
                                <Button variant="default" size="sm" as="Link" :href="route('products.create')"><Plus :size="14" />{{ t('products.addFirstProduct') }}</Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-for="product in products.data" :key="product.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                        <td class="px-4 py-3">
                            <input type="checkbox" :checked="isSelected(product.id)" @change="toggleSelect(product.id)" class="rounded border-border-strong bg-surface-canvas text-brand focus:ring-brand" />
                        </td>
                        <td class="px-4 py-3">
                            <Link :href="route('products.show', product.id)" class="font-medium text-text-primary hover:text-brand">{{ product.name }}</Link>
                            <p v-if="product.description" class="max-w-xs truncate text-xs text-text-tertiary">{{ product.description }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-mono text-xs text-text-secondary">{{ product.sku }}</p>
                            <p v-if="product.barcode" class="font-mono text-xs text-text-tertiary">{{ product.barcode }}</p>
                        </td>
                        <td class="px-4 py-3"><Badge variant="brand" size="sm">{{ product.category?.name || t('common.na') }}</Badge></td>
                        <td class="px-4 py-3"><Badge variant="neutral" size="sm">{{ product.location?.name || t('common.na') }}</Badge></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1">
                                <span :class="['font-medium tabular-nums', isLowStock(product) ? 'text-status-danger' : 'text-text-primary']">{{ product.stock }}</span>
                                <AlertTriangle v-if="isLowStock(product)" :size="14" class="text-status-danger" />
                            </div>
                            <p class="text-[11px] text-text-tertiary">Min: {{ product.min_stock }}</p>
                        </td>
                        <td class="px-4 py-3 font-medium tabular-nums text-text-primary">{{ formatCurrency(product.price) }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Link :href="route('products.show', product.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-brand" title="View"><Eye :size="16" /></Link>
                                <button v-if="product.barcode || product.sku" @click="printBarcode(product.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-text-primary" title="Print barcode"><Barcode :size="16" /></button>
                                <button @click="duplicateProduct(product)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-warning" title="Duplicate"><Copy :size="16" /></button>
                                <Link :href="route('products.edit', product.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-success" title="Edit"><Pencil :size="16" /></Link>
                                <button @click="deleteProduct(product)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-danger" title="Delete"><Trash2 :size="16" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="products.data.length > 0" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-xs text-text-tertiary">
                {{ t('common.showing') }} <span class="font-medium text-text-secondary">{{ products.from }}</span>
                {{ t('common.to') }} <span class="font-medium text-text-secondary">{{ products.to }}</span>
                {{ t('common.of') }} <span class="font-medium text-text-secondary">{{ products.total }}</span> {{ t('common.results') }}
            </p>
            <nav class="inline-flex items-center gap-1">
                <template v-for="link in products.links" :key="link.label">
                    <Link v-if="link.url" :href="link.url"
                        :class="[
                            'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-colors',
                            link.active ? 'border-brand bg-brand text-brand-foreground' : 'border-border-subtle bg-surface-canvas text-text-secondary hover:bg-surface-overlay',
                        ]"
                        v-html="link.label" />
                    <span v-else class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-2.5 text-xs text-text-tertiary opacity-50" v-html="link.label" />
                </template>
            </nav>
        </div>

        <PluginSlot slot="footer" :components="pluginComponents?.footer" />

        <!-- Bulk Category Modal -->
        <Teleport to="body">
            <div v-if="showBulkCategoryModal" class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="fixed inset-0 bg-black/50" @click="showBulkCategoryModal = false"></div>
                <div class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg">
                    <h3 class="mb-1 text-base font-semibold text-text-primary">Change category</h3>
                    <p class="mb-4 text-sm text-text-secondary">Update category for {{ selectedProducts.length }} selected product(s).</p>
                    <select v-model="bulkCategoryId" :class="[selectClass, 'mb-4']">
                        <option value="">Select a category...</option>
                        <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                    </select>
                    <div class="flex justify-end gap-2">
                        <Button variant="secondary" size="sm" @click="showBulkCategoryModal = false">Cancel</Button>
                        <Button variant="default" size="sm" :disabled="!bulkCategoryId || bulkProcessing" @click="bulkUpdateCategory">Update category</Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Bulk Price Modal -->
        <Teleport to="body">
            <div v-if="showBulkPriceModal" class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="fixed inset-0 bg-black/50" @click="showBulkPriceModal = false"></div>
                <div class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg">
                    <h3 class="mb-1 text-base font-semibold text-text-primary">Adjust price</h3>
                    <p class="mb-4 text-sm text-text-secondary">Adjust price for {{ selectedProducts.length }} selected product(s).</p>
                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-text-secondary">Adjustment type</label>
                            <select v-model="bulkPriceType" :class="selectClass">
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount ($)</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-text-secondary">Value <span class="text-text-tertiary">(use negative to decrease)</span></label>
                            <input v-model.number="bulkPriceValue" type="number" step="0.01"
                                class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                                :placeholder="bulkPriceType === 'percentage' ? 'e.g. 10 for +10%, -20 for -20%' : 'e.g. 5.00 or -3.50'" />
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button variant="secondary" size="sm" @click="showBulkPriceModal = false">Cancel</Button>
                        <Button variant="default" size="sm" :disabled="!bulkPriceValue || bulkProcessing" @click="bulkUpdatePrice">Apply price change</Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Floating barcode scan button -->
        <button
            v-if="$page.props.auth.permissions.includes('products.view')"
            @click="openScanner"
            class="fixed bottom-6 right-6 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-brand text-brand-foreground shadow-md transition-colors duration-200 hover:bg-brand-hover"
            :title="t('products.scanBarcode')"
        >
            <ScanLine :size="24" />
        </button>

        <!-- Barcode Scanner Modal -->
        <BarcodeScannerModal :show="showScannerModal" @close="closeScanner" @product-found="handleProductFound" />
    </AppLayout>
</template>
