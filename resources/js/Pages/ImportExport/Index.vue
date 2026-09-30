<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import FileDrop from '@/Components/ImportExport/FileDrop.vue';
import ImportResult from '@/Components/ImportExport/ImportResult.vue';
import { usePermissions } from '@/composables/usePermissions';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatNumber } from '@/lib/money';
import {
    Upload,
    Download,
    SlidersHorizontal,
    FileText,
    Check,
    Info,
} from 'lucide-vue-next';

const props = defineProps({
    categories: Array,
    locations: Array,
    exports: {
        type: Array,
        default: () => [],
    },
    currencyColumns: {
        type: Array,
        default: () => [],
    },
    orderStatuses: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();
const page = usePage();
const { hasPermission, canVisit } = usePermissions();

const canExport = computed(() => hasPermission('export_data'));
const canExportUsers = computed(() => canExport.value && hasPermission('view_users'));
const canImport = computed(() => hasPermission('import_data'));
const canImportOrders = computed(() => canImport.value && hasPermission('create_orders'));
const canImportUsers = computed(() => canImport.value && hasPermission('create_users'));

// ---- Tabs ------------------------------------------------------------------

const tabs = computed(() => [
    { key: 'products', label: t('importExport.tabs.products'), visible: true },
    { key: 'orders', label: t('importExport.tabs.orders'), visible: canExport.value || canImportOrders.value },
    { key: 'users', label: t('importExport.tabs.users'), visible: canExportUsers.value || canImportUsers.value },
].filter((tab) => tab.visible));

const tabKeys = computed(() => tabs.value.map((tab) => tab.key));

// The importers flash a structured result (message + per-row stats) that the
// global FlashMessages toast deliberately skips; this page renders it, and
// opens the tab of the dataset that was imported.
const importResult = ref(page.props.flash?.warning && typeof page.props.flash.warning === 'object'
    ? page.props.flash.warning
    : null);

watch(() => page.props.flash?.warning, (value) => {
    importResult.value = value && typeof value === 'object' ? value : null;
    if (importResult.value?.type && tabKeys.value.includes(importResult.value.type)) {
        activeTab.value = importResult.value.type;
    }
});

const requestedTab = new URLSearchParams(window.location.search).get('tab') || importResult.value?.type;
const activeTab = ref(tabKeys.value.includes(requestedTab) ? requestedTab : 'products');

const exportTypeLabels = computed(() => ({
    products: t('importExport.tabs.products'),
    orders: t('importExport.tabs.orders'),
    order_lines: t('importExport.orders.linesLabel'),
    users: t('importExport.tabs.users'),
}));

const download = (routeName, params) => {
    const query = new URLSearchParams(
        Object.entries(params).filter(([, value]) => value !== '' && value !== false && value != null),
    ).toString();

    window.location.href = route(routeName) + (query ? `?${query}` : '');
};

// ---- Products --------------------------------------------------------------

const productImport = useForm({ file: null });

const exportFilters = ref({
    category_id: '',
    location_id: '',
    status: '',
    low_stock: false,
});

const showExportFilters = ref(false);

const submitProductImport = () => {
    productImport.post(route('import-export.import-products'), {
        preserveScroll: true,
        onSuccess: () => productImport.reset('file'),
    });
};

const exportProducts = () => {
    download('import-export.export-products', {
        ...exportFilters.value,
        low_stock: exportFilters.value.low_stock ? '1' : '',
    });
};

const clearFilters = () => {
    exportFilters.value = {
        category_id: '',
        location_id: '',
        status: '',
        low_stock: false,
    };
};

const hasActiveFilters = computed(() => {
    return exportFilters.value.category_id ||
           exportFilters.value.location_id ||
           exportFilters.value.status ||
           exportFilters.value.low_stock;
});

// ---- Orders ----------------------------------------------------------------

const orderExport = ref({
    mode: 'orders',
    status: '',
    date_from: '',
    date_to: '',
});

const exportOrders = () => {
    download('import-export.export-orders', {
        ...orderExport.value,
        mode: orderExport.value.mode === 'lines' ? 'lines' : '',
    });
};

const orderImport = useForm({ file: null, historical: false, notify_integrations: true });

const submitOrderImport = () => {
    orderImport
        .transform((data) => ({
            ...data,
            historical: data.historical ? 1 : 0,
            // Historical imports never notify integrations (server-enforced too).
            notify_integrations: !data.historical && data.notify_integrations ? 1 : 0,
        }))
        .post(route('import-export.import-orders'), {
            preserveScroll: true,
            onSuccess: () => orderImport.reset('file'),
        });
};

// ---- Users -----------------------------------------------------------------

const exportUsers = () => {
    download('import-export.export-users', {});
};

const userImport = useForm({ file: null, send_invites: true });

const submitUserImport = () => {
    userImport
        .transform((data) => ({ ...data, send_invites: data.send_invites ? 1 : 0 }))
        .post(route('import-export.import-users'), {
            preserveScroll: true,
            onSuccess: () => userImport.reset('file'),
        });
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const checkbox = 'h-4 w-4 rounded border-border-subtle bg-surface-canvas text-brand ds-focus-ring';
const thClass = 'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
const code = 'rounded bg-surface-overlay px-1 py-0.5 font-mono text-xs text-text-primary';
</script>

<template>
    <Head :title="t('nav.importExport')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('nav.importExport') }}</span>
            </div>
        </template>

        <div>
            <PageHeader :title="t('importExport.title')" :description="t('importExport.pageDescription')" />

            <ImportResult v-if="importResult" :result="importResult" class="mt-6" @dismiss="importResult = null" />

            <!-- Tabs -->
            <div class="mt-6 border-b border-border-subtle">
                <nav class="ds-tabs -mb-px gap-6 sm:gap-8" role="tablist">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        type="button"
                        role="tab"
                        :aria-selected="activeTab === tab.key"
                        :class="[
                            'border-b-2 px-1 py-3 text-sm font-medium transition-colors ds-focus-ring',
                            activeTab === tab.key
                                ? 'border-brand text-brand'
                                : 'border-transparent text-text-tertiary hover:border-border-strong hover:text-text-secondary'
                        ]"
                        @click="activeTab = tab.key"
                    >
                        {{ tab.label }}
                    </button>
                </nav>
            </div>

            <!-- ============================ PRODUCTS ============================ -->
            <div v-show="activeTab === 'products'">
                <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <!-- Export Section -->
                    <Card v-if="canExport">
                        <div class="mb-4 flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-soft text-brand">
                                <Download :size="18" />
                            </span>
                            <div>
                                <h3 class="text-sm font-semibold text-text-primary">{{ t('importExport.exportProducts') }}</h3>
                                <p class="text-sm text-text-tertiary">{{ t('importExport.export.subtitle') }}</p>
                            </div>
                        </div>

                        <p class="mb-6 text-sm text-text-secondary">
                            {{ t('importExport.export.description') }}
                        </p>

                        <div class="space-y-3">
                            <Button
                                type="button"
                                variant="secondary"
                                class="w-full"
                                @click="showExportFilters = !showExportFilters"
                            >
                                <SlidersHorizontal :size="14" />
                                {{ showExportFilters ? t('importExport.export.hideFilters') : t('importExport.export.showFilters') }}
                                <Badge v-if="hasActiveFilters" variant="brand" size="sm">{{ Object.values(exportFilters).filter(v => v).length }}</Badge>
                            </Button>

                            <div v-if="showExportFilters" class="space-y-3 rounded-lg border border-border-subtle bg-surface-canvas p-4">
                                <div>
                                    <label for="export_category" :class="fieldLabel">{{ t('products.category') }}</label>
                                    <select id="export_category" v-model="exportFilters.category_id" :class="fieldInput">
                                        <option value="">{{ t('products.allCategories') }}</option>
                                        <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="export_location" :class="fieldLabel">{{ t('products.location') }}</label>
                                    <select id="export_location" v-model="exportFilters.location_id" :class="fieldInput">
                                        <option value="">{{ t('products.allLocations') }}</option>
                                        <option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="export_status" :class="fieldLabel">{{ t('common.status') }}</label>
                                    <select id="export_status" v-model="exportFilters.status" :class="fieldInput">
                                        <option value="">{{ t('common.allStatuses') }}</option>
                                        <option value="active">{{ t('common.active') }}</option>
                                        <option value="inactive">{{ t('common.inactive') }}</option>
                                        <option value="discontinued">{{ t('importExport.export.discontinued') }}</option>
                                    </select>
                                </div>

                                <div class="flex items-center">
                                    <input id="low_stock" v-model="exportFilters.low_stock" type="checkbox" :class="checkbox" />
                                    <label for="low_stock" class="ml-2 text-sm font-medium text-text-secondary">{{ t('importExport.export.lowStockOnly') }}</label>
                                </div>

                                <Button
                                    v-if="hasActiveFilters"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="w-full"
                                    @click="clearFilters"
                                >
                                    {{ t('importExport.export.clearAllFilters') }}
                                </Button>
                            </div>

                            <Button type="button" variant="default" size="lg" class="w-full" @click="exportProducts">
                                <Download :size="16" />
                                {{ t('importExport.export.exportToExcel') }}
                            </Button>

                            <div class="border-t border-border-subtle pt-4">
                                <h4 class="mb-2 text-sm font-medium text-text-secondary">{{ t('importExport.export.exportIncludesLabel') }}</h4>
                                <ul class="space-y-1 text-sm text-text-tertiary">
                                    <li class="flex items-center gap-2">
                                        <Check :size="16" class="text-status-success" />
                                        {{ t('importExport.export.includeNames') }}
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <Check :size="16" class="text-status-success" />
                                        {{ t('importExport.export.includePricing') }}
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <Check :size="16" class="text-status-success" />
                                        {{ t('importExport.export.includeStock') }}
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <Check :size="16" class="text-status-success" />
                                        {{ t('importExport.export.includeCategories') }}
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </Card>

                    <!-- Import Section -->
                    <Card v-if="canImport">
                        <div class="mb-4 flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-soft text-brand">
                                <Upload :size="18" />
                            </span>
                            <div>
                                <h3 class="text-sm font-semibold text-text-primary">{{ t('importExport.importProducts') }}</h3>
                                <p class="text-sm text-text-tertiary">{{ t('importExport.import.subtitle') }}</p>
                            </div>
                        </div>

                        <p class="mb-6 text-sm text-text-secondary">
                            {{ t('importExport.import.description') }}
                        </p>

                        <form class="space-y-4" @submit.prevent="submitProductImport">
                            <FileDrop v-model="productImport.file" input-id="product_import_file" />

                            <div v-if="productImport.errors.file" class="text-sm text-status-danger">
                                {{ productImport.errors.file }}
                            </div>

                            <Button
                                type="submit"
                                variant="default"
                                size="lg"
                                class="w-full"
                                :loading="productImport.processing"
                                :disabled="!productImport.file || productImport.processing"
                            >
                                <span v-if="productImport.processing">{{ t('importExport.import.importing') }}</span>
                                <span v-else>{{ t('importExport.importProducts') }}</span>
                            </Button>

                            <div class="border-t border-border-subtle pt-4">
                                <p class="mb-3 text-sm text-text-tertiary">
                                    {{ t('importExport.import.templateHelpLabel') }}
                                </p>
                                <Button v-if="canVisit('import-export.download-template')" as="a" variant="secondary" class="w-full" :href="route('import-export.download-template')">
                                    <Download :size="14" />
                                    {{ t('importExport.import.downloadTemplate') }}
                                </Button>
                            </div>
                        </form>
                    </Card>
                </div>

                <Card v-if="canImport" class="mt-4">
                    <h3 class="mb-4 text-sm font-semibold text-text-primary">{{ t('importExport.import.instructions') }}</h3>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <h4 class="mb-2 text-sm font-medium text-text-secondary">{{ t('importExport.import.requiredFieldsLabel') }}</h4>
                            <ul class="space-y-1 text-sm text-text-tertiary">
                                <li class="flex items-start gap-2">
                                    <span class="text-status-danger">*</span>
                                    <span><strong class="text-text-secondary">{{ 'name' }}</strong> - {{ t('importExport.import.fieldNameDesc') }}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="text-status-danger">*</span>
                                    <span><strong class="text-text-secondary">{{ 'sku' }}</strong> - {{ t('importExport.import.fieldSkuDesc') }}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="text-status-danger">*</span>
                                    <span><strong class="text-text-secondary">{{ 'price' }}</strong> - {{ t('importExport.import.fieldPriceDesc') }}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="text-status-danger">*</span>
                                    <span><strong class="text-text-secondary">{{ 'stock' }}</strong> - {{ t('importExport.import.fieldStockDesc') }}</span>
                                </li>
                            </ul>
                        </div>

                        <div>
                            <h4 class="mb-2 text-sm font-medium text-text-secondary">{{ t('importExport.import.importantNotesLabel') }}</h4>
                            <ul class="space-y-1 text-sm text-text-tertiary">
                                <li class="flex items-start gap-2">
                                    <Info :size="16" class="mt-0.5 shrink-0 text-brand" />
                                    <span>{{ t('importExport.import.noteUpdate') }}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <Info :size="16" class="mt-0.5 shrink-0 text-brand" />
                                    <span>{{ t('importExport.import.noteCreate') }}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <Info :size="16" class="mt-0.5 shrink-0 text-brand" />
                                    <span>{{ t('importExport.import.noteCurrency') }}</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <Info :size="16" class="mt-0.5 shrink-0 text-brand" />
                                    <span>
                                        {{ t('importExport.products.currencyNote') }}
                                        <template v-if="currencyColumns.length">
                                            {{ t('importExport.products.currencyColumnsInUse') }}
                                            <code v-for="column in currencyColumns" :key="column" :class="[code, 'mr-1']">{{ column }}</code>
                                        </template>
                                    </span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <Info :size="16" class="mt-0.5 shrink-0 text-brand" />
                                    <span>{{ t('importExport.import.noteSkip') }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- ============================= ORDERS ============================= -->
            <div v-show="activeTab === 'orders'">
                <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <Card v-if="canExport">
                        <div class="mb-4 flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-soft text-brand">
                                <Download :size="18" />
                            </span>
                            <div>
                                <h3 class="text-sm font-semibold text-text-primary">{{ t('importExport.orders.exportTitle') }}</h3>
                                <p class="text-sm text-text-tertiary">{{ t('importExport.orders.exportSubtitle') }}</p>
                            </div>
                        </div>

                        <form class="space-y-4" @submit.prevent="exportOrders">
                            <fieldset>
                                <legend :class="fieldLabel">{{ t('importExport.orders.format') }}</legend>
                                <div class="space-y-2">
                                    <label class="flex items-start gap-2 rounded-lg border border-border-subtle bg-surface-canvas p-3 text-sm">
                                        <input v-model="orderExport.mode" type="radio" value="orders" class="mt-0.5 ds-focus-ring" />
                                        <span>
                                            <span class="block font-medium text-text-primary">{{ t('importExport.orders.perOrder') }}</span>
                                            <span class="block text-text-tertiary">{{ t('importExport.orders.perOrderHelp') }}</span>
                                        </span>
                                    </label>
                                    <label class="flex items-start gap-2 rounded-lg border border-border-subtle bg-surface-canvas p-3 text-sm">
                                        <input v-model="orderExport.mode" type="radio" value="lines" class="mt-0.5 ds-focus-ring" />
                                        <span>
                                            <span class="block font-medium text-text-primary">{{ t('importExport.orders.perLine') }}</span>
                                            <span class="block text-text-tertiary">{{ t('importExport.orders.perLineHelp') }}</span>
                                        </span>
                                    </label>
                                </div>
                            </fieldset>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div>
                                    <label for="order_export_status" :class="fieldLabel">{{ t('common.status') }}</label>
                                    <select id="order_export_status" v-model="orderExport.status" :class="fieldInput">
                                        <option value="">{{ t('common.allStatuses') }}</option>
                                        <option v-for="status in orderStatuses" :key="status" :value="status" class="capitalize">{{ t(`orders.status.${status}`) }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="order_export_from" :class="fieldLabel">{{ t('importExport.orders.createdFrom') }}</label>
                                    <input id="order_export_from" v-model="orderExport.date_from" type="date" :class="fieldInput" />
                                </div>
                                <div>
                                    <label for="order_export_to" :class="fieldLabel">{{ t('importExport.orders.createdTo') }}</label>
                                    <input id="order_export_to" v-model="orderExport.date_to" type="date" :class="fieldInput" />
                                </div>
                            </div>

                            <Button type="submit" variant="default" size="lg" class="w-full">
                                <Download :size="16" />
                                {{ t('importExport.orders.exportButton') }}
                            </Button>
                        </form>
                    </Card>

                    <Card v-if="canImportOrders">
                        <div class="mb-4 flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-soft text-brand">
                                <Upload :size="18" />
                            </span>
                            <div>
                                <h3 class="text-sm font-semibold text-text-primary">{{ t('importExport.orders.importTitle') }}</h3>
                                <p class="text-sm text-text-tertiary">{{ t('importExport.orders.importSubtitle') }}</p>
                            </div>
                        </div>

                        <form class="space-y-4" @submit.prevent="submitOrderImport">
                            <FileDrop v-model="orderImport.file" input-id="order_import_file" />

                            <div v-if="orderImport.errors.file" class="text-sm text-status-danger">
                                {{ orderImport.errors.file }}
                            </div>

                            <label class="flex items-start gap-2 rounded-lg border border-border-subtle bg-surface-canvas p-3 text-sm">
                                <input v-model="orderImport.historical" type="checkbox" :class="[checkbox, 'mt-0.5']" />
                                <span>
                                    <span class="block font-medium text-text-primary">{{ t('importExport.orders.historical') }}</span>
                                    <span class="block text-text-tertiary">{{ t('importExport.orders.historicalHelp') }}</span>
                                </span>
                            </label>

                            <label
                                v-if="!orderImport.historical"
                                class="flex items-start gap-2 rounded-lg border border-border-subtle bg-surface-canvas p-3 text-sm"
                            >
                                <input v-model="orderImport.notify_integrations" type="checkbox" :class="[checkbox, 'mt-0.5']" />
                                <span>
                                    <span class="block font-medium text-text-primary">{{ t('importExport.orders.notifyIntegrations') }}</span>
                                    <span class="block text-text-tertiary">{{ t('importExport.orders.notifyIntegrationsHelp') }}</span>
                                </span>
                            </label>

                            <Button
                                type="submit"
                                variant="default"
                                size="lg"
                                class="w-full"
                                :loading="orderImport.processing"
                                :disabled="!orderImport.file || orderImport.processing"
                            >
                                {{ orderImport.processing ? t('importExport.import.importing') : t('importExport.orders.importButton') }}
                            </Button>

                            <div class="border-t border-border-subtle pt-4">
                                <Button v-if="canVisit('import-export.download-order-template')" as="a" variant="secondary" class="w-full" :href="route('import-export.download-order-template')">
                                    <Download :size="14" />
                                    {{ t('importExport.import.downloadTemplate') }}
                                </Button>
                            </div>
                        </form>
                    </Card>
                </div>

                <Card class="mt-4">
                    <h3 class="mb-4 text-sm font-semibold text-text-primary">{{ t('importExport.orders.formatTitle') }}</h3>
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <h4 class="mb-2 text-sm font-medium text-text-secondary">{{ t('importExport.orders.linesExportTitle') }}</h4>
                            <ul class="space-y-1 text-sm text-text-tertiary">
                                <li class="flex items-start gap-2"><Info :size="16" class="mt-0.5 shrink-0 text-brand" /><span>{{ t('importExport.orders.linesNoteRow') }}</span></li>
                                <li class="flex items-start gap-2"><Info :size="16" class="mt-0.5 shrink-0 text-brand" /><span>{{ t('importExport.orders.linesNoteTotals') }}</span></li>
                                <li class="flex items-start gap-2"><Info :size="16" class="mt-0.5 shrink-0 text-brand" /><span>{{ t('importExport.orders.linesNoteVariant') }}</span></li>
                            </ul>
                        </div>
                        <div v-if="canImportOrders">
                            <h4 class="mb-2 text-sm font-medium text-text-secondary">{{ t('importExport.orders.importRulesTitle') }}</h4>
                            <ul class="space-y-1 text-sm text-text-tertiary">
                                <li class="flex items-start gap-2">
                                    <span class="text-status-danger">*</span>
                                    <span>
                                        <code :class="code">external_reference</code>, <code :class="code">order_date</code>, <code :class="code">quantity</code>,
                                        {{ t('importExport.orders.requiredSku') }}
                                    </span>
                                </li>
                                <li class="flex items-start gap-2"><Info :size="16" class="mt-0.5 shrink-0 text-brand" /><span>{{ t('importExport.orders.ruleGrouping') }}</span></li>
                                <li class="flex items-start gap-2"><Info :size="16" class="mt-0.5 shrink-0 text-brand" /><span>{{ t('importExport.orders.ruleAllOrNothing') }}</span></li>
                                <li class="flex items-start gap-2"><Info :size="16" class="mt-0.5 shrink-0 text-brand" /><span>{{ t('importExport.orders.ruleDuplicates') }}</span></li>
                                <li class="flex items-start gap-2"><Info :size="16" class="mt-0.5 shrink-0 text-brand" /><span>{{ t('importExport.orders.ruleCustomers') }}</span></li>
                                <li class="flex items-start gap-2"><Info :size="16" class="mt-0.5 shrink-0 text-brand" /><span>{{ t('importExport.orders.ruleStock') }}</span></li>
                                <li class="flex items-start gap-2"><Info :size="16" class="mt-0.5 shrink-0 text-brand" /><span>{{ t('importExport.orders.ruleIntegrations') }}</span></li>
                            </ul>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- ============================== USERS ============================= -->
            <div v-show="activeTab === 'users'">
                <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <Card v-if="canExportUsers">
                        <div class="mb-4 flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-soft text-brand">
                                <Download :size="18" />
                            </span>
                            <div>
                                <h3 class="text-sm font-semibold text-text-primary">{{ t('importExport.users.exportTitle') }}</h3>
                                <p class="text-sm text-text-tertiary">{{ t('importExport.users.exportSubtitle') }}</p>
                            </div>
                        </div>
                        <p class="mb-6 text-sm text-text-secondary">{{ t('importExport.users.exportDescription') }}</p>
                        <Button type="button" variant="default" size="lg" class="w-full" @click="exportUsers">
                            <Download :size="16" />
                            {{ t('importExport.users.exportButton') }}
                        </Button>
                    </Card>

                    <Card v-if="canImportUsers">
                        <div class="mb-4 flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-soft text-brand">
                                <Upload :size="18" />
                            </span>
                            <div>
                                <h3 class="text-sm font-semibold text-text-primary">{{ t('importExport.users.importTitle') }}</h3>
                                <p class="text-sm text-text-tertiary">{{ t('importExport.users.importSubtitle') }}</p>
                            </div>
                        </div>

                        <form class="space-y-4" @submit.prevent="submitUserImport">
                            <FileDrop v-model="userImport.file" input-id="user_import_file" />

                            <div v-if="userImport.errors.file" class="text-sm text-status-danger">
                                {{ userImport.errors.file }}
                            </div>

                            <label class="flex items-start gap-2 rounded-lg border border-border-subtle bg-surface-canvas p-3 text-sm">
                                <input v-model="userImport.send_invites" type="checkbox" :class="[checkbox, 'mt-0.5']" />
                                <span>
                                    <span class="block font-medium text-text-primary">{{ t('importExport.users.sendInvites') }}</span>
                                    <span class="block text-text-tertiary">{{ t('importExport.users.sendInvitesHelp') }}</span>
                                </span>
                            </label>

                            <Button
                                type="submit"
                                variant="default"
                                size="lg"
                                class="w-full"
                                :loading="userImport.processing"
                                :disabled="!userImport.file || userImport.processing"
                            >
                                {{ userImport.processing ? t('importExport.import.importing') : t('importExport.users.importButton') }}
                            </Button>

                            <div class="border-t border-border-subtle pt-4">
                                <Button v-if="canVisit('import-export.download-user-template')" as="a" variant="secondary" class="w-full" :href="route('import-export.download-user-template')">
                                    <Download :size="14" />
                                    {{ t('importExport.import.downloadTemplate') }}
                                </Button>
                            </div>
                        </form>
                    </Card>
                </div>

                <Card v-if="canImportUsers" class="mt-4">
                    <h3 class="mb-4 text-sm font-semibold text-text-primary">{{ t('importExport.import.instructions') }}</h3>
                    <ul class="space-y-1 text-sm text-text-tertiary">
                        <li class="flex items-start gap-2">
                            <span class="text-status-danger">*</span>
                            <span><code :class="code">name</code>, <code :class="code">email</code></span>
                        </li>
                        <li class="flex items-start gap-2"><Info :size="16" class="mt-0.5 shrink-0 text-brand" /><span>{{ t('importExport.users.ruleRole') }}</span></li>
                        <li class="flex items-start gap-2"><Info :size="16" class="mt-0.5 shrink-0 text-brand" /><span>{{ t('importExport.users.ruleRoles') }}</span></li>
                        <li class="flex items-start gap-2"><Info :size="16" class="mt-0.5 shrink-0 text-brand" /><span>{{ t('importExport.users.rulePasswords') }}</span></li>
                        <li class="flex items-start gap-2"><Info :size="16" class="mt-0.5 shrink-0 text-brand" /><span>{{ t('importExport.users.ruleDuplicates') }}</span></li>
                        <li class="flex items-start gap-2"><Info :size="16" class="mt-0.5 shrink-0 text-brand" /><span>{{ t('importExport.users.ruleEscalation') }}</span></li>
                    </ul>
                </Card>
            </div>

            <!-- Recent Exports (queued downloads) -->
            <section v-if="props.exports.length" class="mt-8">
                <div class="mb-1">
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('importExport.exports.title') }}</h3>
                </div>
                <p class="mb-4 text-sm text-text-tertiary">
                    {{ t('importExport.exports.description') }}
                </p>
                <div class="w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border-subtle">
                                <th :class="thClass">{{ t('importExport.exports.file') }}</th>
                                <th :class="thClass">{{ t('common.type') }}</th>
                                <th :class="thClass">{{ t('importExport.exports.rows') }}</th>
                                <th :class="thClass">{{ t('common.status') }}</th>
                                <th :class="[thClass, 'text-right']">{{ t('importExport.exports.action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in props.exports" :key="e.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                                <td class="px-4 py-3 text-text-primary">
                                    <div class="flex items-center gap-2">
                                        <FileText :size="15" class="text-text-tertiary" />
                                        {{ e.filename }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-text-secondary">{{ exportTypeLabels[e.type] || e.type }}</td>
                                <td class="px-4 py-3 tabular-nums text-text-secondary">{{ e.row_count != null ? formatNumber(e.row_count) : '-' }}</td>
                                <td class="px-4 py-3">
                                    <Badge
                                        :variant="{
                                            completed: 'success',
                                            pending: 'warning',
                                            processing: 'warning',
                                            failed: 'danger',
                                        }[e.status] || 'neutral'"
                                        size="sm"
                                    >
                                        {{ t(`importExport.exports.statuses.${e.status}`) }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a
                                        v-if="e.status === 'completed'"
                                        :href="route('import-export.download', e.id)"
                                        class="font-medium text-brand transition-colors hover:text-brand-hover"
                                    >{{ t('importExport.exports.download') }}</a>
                                    <span v-else class="text-text-tertiary">-</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
