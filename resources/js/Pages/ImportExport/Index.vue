<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    Upload,
    Download,
    CheckCircle2,
    AlertCircle,
    AlertTriangle,
    SlidersHorizontal,
    FileSpreadsheet,
    FileText,
    Check,
    RefreshCw,
    Info,
    X,
} from 'lucide-vue-next';

const props = defineProps({
    categories: Array,
    locations: Array,
    exports: {
        type: Array,
        default: () => [],
    },
});


const { t } = useI18n();
const page = usePage();

const importForm = useForm({
    file: null,
});

const fileInput = ref(null);
const fileName = ref('');
const isDragging = ref(false);

// Export filters
const exportFilters = ref({
    category_id: '',
    location_id: '',
    status: '',
    low_stock: false,
});

const showExportFilters = ref(false);

// Get flash messages and stats
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);
const flashWarning = computed(() => page.props.flash?.warning);

const handleFileSelect = (event) => {
    const file = event.target.files[0];
    if (file) {
        importForm.file = file;
        fileName.value = file.name;
    }
};

const handleDrop = (event) => {
    isDragging.value = false;
    const file = event.dataTransfer.files[0];
    if (file) {
        importForm.file = file;
        fileName.value = file.name;
    }
};

const handleDragOver = (event) => {
    isDragging.value = true;
};

const handleDragLeave = () => {
    isDragging.value = false;
};

const removeFile = () => {
    importForm.file = null;
    fileName.value = '';
    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const submitImport = () => {
    importForm.post(route('import-export.import-products'), {
        preserveScroll: true,
        onSuccess: () => {
            removeFile();
        },
    });
};

const exportProducts = () => {
    const params = new URLSearchParams();

    if (exportFilters.value.category_id) {
        params.append('category_id', exportFilters.value.category_id);
    }
    if (exportFilters.value.location_id) {
        params.append('location_id', exportFilters.value.location_id);
    }
    if (exportFilters.value.status) {
        params.append('status', exportFilters.value.status);
    }
    if (exportFilters.value.low_stock) {
        params.append('low_stock', '1');
    }

    const queryString = params.toString();
    const url = route('import-export.export-products') + (queryString ? '?' + queryString : '');

    window.location.href = url;
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

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const thClass = 'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
</script>

<template>
    <Head :title="t('nav.importExport')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">Import / Export</span>
            </div>
        </template>

        <PageHeader title="Import / Export" description="Bulk import products from a file, or export your inventory data." />

        <!-- Success Message -->
        <Card v-if="flashSuccess" class="mt-6 border-status-success/20 bg-status-success-soft">
            <div class="flex items-start gap-3">
                <CheckCircle2 :size="20" class="shrink-0 text-status-success" />
                <p class="text-sm text-status-success">{{ flashSuccess }}</p>
            </div>
        </Card>

        <!-- Error Message -->
        <Card v-if="flashError" class="mt-6 border-status-danger/20 bg-status-danger-soft">
            <div class="flex items-start gap-3">
                <AlertCircle :size="20" class="shrink-0 text-status-danger" />
                <p class="text-sm text-status-danger">{{ flashError }}</p>
            </div>
        </Card>

        <!-- Warning Message with Stats -->
        <Card v-if="flashWarning" class="mt-6 border-status-warning/20 bg-status-warning-soft">
            <div class="flex items-start gap-3">
                <AlertTriangle :size="20" class="shrink-0 text-status-warning" />
                <div class="flex-1">
                    <p class="mb-2 font-medium text-status-warning">{{ flashWarning.message }}</p>

                    <div v-if="flashWarning.stats" class="mt-3 space-y-2">
                        <div class="flex items-center gap-2 text-sm">
                            <span class="inline-flex items-center gap-1 text-status-success"><Check :size="14" /> Imported:</span>
                            <span class="text-text-secondary">{{ flashWarning.stats.imported }}</span>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="inline-flex items-center gap-1 text-status-info"><RefreshCw :size="14" /> Updated:</span>
                            <span class="text-text-secondary">{{ flashWarning.stats.updated }}</span>
                        </div>
                        <div v-if="flashWarning.stats.errors.length > 0" class="flex items-start gap-2 text-sm">
                            <span class="inline-flex items-center gap-1 text-status-danger"><X :size="14" /> Errors:</span>
                            <div class="flex-1">
                                <p class="mb-1 text-text-secondary">{{ flashWarning.stats.errors.length }} row(s) failed</p>
                                <div class="max-h-40 space-y-1 overflow-y-auto">
                                    <div v-for="(error, index) in flashWarning.stats.errors" :key="index" class="rounded bg-status-danger-soft px-2 py-1 text-xs text-status-danger">
                                        <span class="font-medium">Row {{ error.row }}:</span> {{ error.errors.join(', ') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Card>

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <!-- Export Section -->
            <Card>
                <div class="mb-4 flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-soft text-brand">
                        <Download :size="18" />
                    </span>
                    <div>
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('importExport.exportProducts') }}</h3>
                        <p class="text-sm text-text-tertiary">Download your inventory data</p>
                    </div>
                </div>

                <p class="mb-6 text-sm text-text-secondary">
                    Export all your products to an Excel file. The export includes product details, pricing, stock levels, categories, and locations.
                </p>

                <div class="space-y-3">
                    <!-- Filter Toggle Button -->
                    <Button
                        type="button"
                        variant="secondary"
                        class="w-full"
                        @click="showExportFilters = !showExportFilters"
                    >
                        <SlidersHorizontal :size="14" />
                        {{ showExportFilters ? 'Hide Filters' : 'Show Filters' }}
                        <Badge v-if="hasActiveFilters" variant="brand" size="sm">{{ Object.values(exportFilters).filter(v => v).length }}</Badge>
                    </Button>

                    <!-- Export Filters -->
                    <div v-if="showExportFilters" class="space-y-3 rounded-lg border border-border-subtle bg-surface-canvas p-4">
                        <div>
                            <label :class="fieldLabel">Category</label>
                            <select v-model="exportFilters.category_id" :class="fieldInput">
                                <option value="">All Categories</option>
                                <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                            </select>
                        </div>

                        <div>
                            <label :class="fieldLabel">Location</label>
                            <select v-model="exportFilters.location_id" :class="fieldInput">
                                <option value="">All Locations</option>
                                <option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option>
                            </select>
                        </div>

                        <div>
                            <label :class="fieldLabel">{{ t('common.status') }}</label>
                            <select v-model="exportFilters.status" :class="fieldInput">
                                <option value="">{{ t('common.allStatuses') }}</option>
                                <option value="active">{{ t('common.active') }}</option>
                                <option value="inactive">{{ t('common.inactive') }}</option>
                                <option value="discontinued">Discontinued</option>
                            </select>
                        </div>

                        <div class="flex items-center">
                            <input
                                id="low_stock"
                                v-model="exportFilters.low_stock"
                                type="checkbox"
                                class="h-4 w-4 rounded border-border-subtle bg-surface-canvas text-brand ds-focus-ring"
                            />
                            <label for="low_stock" class="ml-2 text-sm font-medium text-text-secondary">Low Stock Only</label>
                        </div>

                        <Button
                            v-if="hasActiveFilters"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="w-full"
                            @click="clearFilters"
                        >
                            Clear All Filters
                        </Button>
                    </div>

                    <!-- Export Button -->
                    <Button
                        type="button"
                        variant="default"
                        size="lg"
                        class="w-full"
                        @click="exportProducts"
                    >
                        <Download :size="16" />
                        Export to Excel
                    </Button>

                    <div class="border-t border-border-subtle pt-4">
                        <h4 class="mb-2 text-sm font-medium text-text-secondary">Export includes:</h4>
                        <ul class="space-y-1 text-sm text-text-tertiary">
                            <li class="flex items-center gap-2">
                                <Check :size="16" class="text-status-success" />
                                Product names, SKUs, and barcodes
                            </li>
                            <li class="flex items-center gap-2">
                                <Check :size="16" class="text-status-success" />
                                Pricing and currency information
                            </li>
                            <li class="flex items-center gap-2">
                                <Check :size="16" class="text-status-success" />
                                Stock levels and minimum stock
                            </li>
                            <li class="flex items-center gap-2">
                                <Check :size="16" class="text-status-success" />
                                Categories and locations
                            </li>
                        </ul>
                    </div>
                </div>
            </Card>

            <!-- Import Section -->
            <Card>
                <div class="mb-4 flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-soft text-brand">
                        <Upload :size="18" />
                    </span>
                    <div>
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('importExport.importProducts') }}</h3>
                        <p class="text-sm text-text-tertiary">Upload inventory data in bulk</p>
                    </div>
                </div>

                <p class="mb-6 text-sm text-text-secondary">
                    Import products from a CSV or Excel file. Download the template below to ensure your file has the correct format.
                </p>

                <form @submit.prevent="submitImport" class="space-y-4">
                    <!-- File Upload Area -->
                    <div
                        @drop.prevent="handleDrop"
                        @dragover.prevent="handleDragOver"
                        @dragleave.prevent="handleDragLeave"
                        :class="[
                            'rounded-lg border-2 border-dashed p-8 text-center transition-colors',
                            isDragging
                                ? 'border-brand bg-brand-soft'
                                : 'border-border-subtle bg-surface-canvas'
                        ]"
                    >
                        <input
                            ref="fileInput"
                            type="file"
                            accept=".csv,.xlsx,.xls"
                            @change="handleFileSelect"
                            class="hidden"
                        />

                        <div v-if="!fileName">
                            <Upload :size="40" class="mx-auto mb-4 text-text-tertiary" />
                            <p class="mb-2 text-sm text-text-secondary">Drag and drop your file here, or</p>
                            <button
                                type="button"
                                @click="$refs.fileInput.click()"
                                class="font-semibold text-brand hover:text-brand-hover ds-focus-ring"
                            >
                                browse to upload
                            </button>
                            <p class="mt-2 text-sm text-text-tertiary">Supported formats: CSV, XLSX, XLS (max 10MB)</p>
                        </div>

                        <div v-else class="flex items-center justify-between rounded-lg bg-surface-raised px-4 py-3">
                            <div class="flex items-center gap-3">
                                <FileSpreadsheet :size="28" class="text-status-success" />
                                <div class="text-left">
                                    <p class="font-medium text-text-primary">{{ fileName }}</p>
                                    <p class="text-sm text-text-tertiary">Ready to import</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                @click="removeFile"
                                class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-danger ds-focus-ring"
                            >
                                <X :size="18" />
                            </button>
                        </div>
                    </div>

                    <div v-if="importForm.errors.file" class="text-sm text-status-danger">
                        {{ importForm.errors.file }}
                    </div>

                    <!-- Import Button -->
                    <Button
                        type="submit"
                        variant="default"
                        size="lg"
                        class="w-full"
                        :loading="importForm.processing"
                        :disabled="!fileName || importForm.processing"
                    >
                        <span v-if="importForm.processing">Importing...</span>
                        <span v-else>{{ t('importExport.importProducts') }}</span>
                    </Button>

                    <!-- Download Template -->
                    <div class="border-t border-border-subtle pt-4">
                        <p class="mb-3 text-sm text-text-tertiary">
                            Need help? Download our template file with example data:
                        </p>
                        <Button
                            as="a"
                            variant="secondary"
                            class="w-full"
                            :href="route('import-export.download-template')"
                        >
                            <Download :size="14" />
                            Download Template
                        </Button>
                    </div>
                </form>
            </Card>
        </div>

        <!-- Import Instructions -->
        <Card class="mt-4">
            <h3 class="mb-4 text-sm font-semibold text-text-primary">Import Instructions</h3>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                    <h4 class="mb-2 text-sm font-medium text-text-secondary">Required Fields:</h4>
                    <ul class="space-y-1 text-sm text-text-tertiary">
                        <li class="flex items-start gap-2">
                            <span class="text-status-danger">*</span>
                            <span><strong class="text-text-secondary">name</strong> - Product name</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-status-danger">*</span>
                            <span><strong class="text-text-secondary">sku</strong> - Unique SKU identifier</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-status-danger">*</span>
                            <span><strong class="text-text-secondary">price</strong> - Selling price</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-status-danger">*</span>
                            <span><strong class="text-text-secondary">stock</strong> - Current stock quantity</span>
                        </li>
                    </ul>
                </div>

                <div>
                    <h4 class="mb-2 text-sm font-medium text-text-secondary">Important Notes:</h4>
                    <ul class="space-y-1 text-sm text-text-tertiary">
                        <li class="flex items-start gap-2">
                            <Info :size="16" class="mt-0.5 shrink-0 text-brand" />
                            <span>Existing products (matching SKU) will be updated</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <Info :size="16" class="mt-0.5 shrink-0 text-brand" />
                            <span>Categories and locations will be created if they don't exist</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <Info :size="16" class="mt-0.5 shrink-0 text-brand" />
                            <span>Default currency is USD if not specified</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <Info :size="16" class="mt-0.5 shrink-0 text-brand" />
                            <span>Invalid rows will be skipped and reported</span>
                        </li>
                    </ul>
                </div>
            </div>
        </Card>

        <!-- Recent Exports (queued downloads) -->
        <section v-if="props.exports.length" class="mt-8">
            <div class="mb-1">
                <h3 class="text-sm font-semibold text-text-primary">Your exports</h3>
            </div>
            <p class="mb-4 text-sm text-text-tertiary">
                Large exports are prepared in the background. You'll be notified when each is ready to download.
            </p>
            <div class="w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-subtle">
                            <th :class="thClass">File</th>
                            <th :class="thClass">Type</th>
                            <th :class="thClass">Rows</th>
                            <th :class="thClass">Status</th>
                            <th :class="[thClass, 'text-right']">Action</th>
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
                            <td class="px-4 py-3 capitalize text-text-secondary">{{ e.type }}</td>
                            <td class="px-4 py-3 tabular-nums text-text-secondary">{{ e.row_count ?? '—' }}</td>
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
                                    {{ e.status }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a
                                    v-if="e.status === 'completed'"
                                    :href="route('import-export.download', e.id)"
                                    class="font-medium text-brand transition-colors hover:text-brand-hover"
                                >Download</a>
                                <span v-else class="text-text-tertiary">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
