<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const importForm = useForm({
    file: null,
});

const fileInput = ref(null);
const fileName = ref('');
const isDragging = ref(false);

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
</script>

<template>
    <Head title="Import / Export" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">
                Import / Export
            </h2>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Success/Error Messages -->
                <div v-if="$page.props.flash.success" class="mb-6 bg-green-900/20 border border-green-800 rounded-lg p-4">
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-green-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <p class="text-green-300">{{ $page.props.flash.success }}</p>
                    </div>
                </div>

                <div v-if="$page.props.flash.error" class="mb-6 bg-red-900/20 border border-red-800 rounded-lg p-4">
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <p class="text-red-300">{{ $page.props.flash.error }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Export Section -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="p-3 bg-green-900/20 rounded-lg">
                                <svg class="w-6 h-6 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10" />
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Export Products</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Download your inventory data</p>
                            </div>
                        </div>

                        <p class="text-gray-600 dark:text-gray-300 mb-6">
                            Export all your products to an Excel file. The export includes product details, pricing, stock levels, categories, and locations.
                        </p>

                        <div class="space-y-3">
                            <a
                                :href="route('import-export.export-products')"
                                class="w-full inline-flex items-center justify-center px-4 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg font-semibold transition"
                            >
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                Export to Excel
                            </a>

                            <div class="pt-4 border-t border-gray-200 dark:border-dark-border">
                                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Export includes:</h4>
                                <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                    <li class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        Product names, SKUs, and barcodes
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        Pricing and currency information
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        Stock levels and minimum stock
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        Categories and locations
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Import Section -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="p-3 bg-blue-900/20 rounded-lg">
                                <svg class="w-6 h-6 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Import Products</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Upload inventory data in bulk</p>
                            </div>
                        </div>

                        <p class="text-gray-600 dark:text-gray-300 mb-6">
                            Import products from a CSV or Excel file. Download the template below to ensure your file has the correct format.
                        </p>

                        <form @submit.prevent="submitImport" class="space-y-4">
                            <!-- File Upload Area -->
                            <div
                                @drop.prevent="handleDrop"
                                @dragover.prevent="handleDragOver"
                                @dragleave.prevent="handleDragLeave"
                                :class="[
                                    'border-2 border-dashed rounded-lg p-8 text-center transition',
                                    isDragging
                                        ? 'border-primary-400 bg-primary-400/10'
                                        : 'border-gray-300 dark:border-dark-border bg-gray-50 dark:bg-dark-bg'
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
                                    <svg class="w-12 h-12 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                    </svg>
                                    <p class="text-gray-600 dark:text-gray-300 mb-2">Drag and drop your file here, or</p>
                                    <button
                                        type="button"
                                        @click="$refs.fileInput.click()"
                                        class="text-primary-400 hover:text-primary-300 font-semibold"
                                    >
                                        browse to upload
                                    </button>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Supported formats: CSV, XLSX, XLS (max 10MB)</p>
                                </div>

                                <div v-else class="flex items-center justify-between bg-white dark:bg-dark-card px-4 py-3 rounded-lg">
                                    <div class="flex items-center gap-3">
                                        <svg class="w-8 h-8 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        <div class="text-left">
                                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ fileName }}</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">Ready to import</p>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        @click="removeFile"
                                        class="text-red-400 hover:text-red-300"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div v-if="importForm.errors.file" class="text-sm text-red-400">
                                {{ importForm.errors.file }}
                            </div>

                            <!-- Import Button -->
                            <button
                                type="submit"
                                :disabled="!fileName || importForm.processing"
                                class="w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold disabled:opacity-50 disabled:cursor-not-allowed transition"
                            >
                                <span v-if="importForm.processing">Importing...</span>
                                <span v-else>Import Products</span>
                            </button>

                            <!-- Download Template -->
                            <div class="pt-4 border-t border-gray-200 dark:border-dark-border">
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                                    Need help? Download our template file with example data:
                                </p>
                                <a
                                    :href="route('import-export.download-template')"
                                    class="w-full inline-flex items-center justify-center px-4 py-2 bg-gray-100 dark:bg-dark-bg text-gray-700 dark:text-gray-300 rounded-lg font-medium hover:bg-gray-200 dark:hover:bg-dark-bg/80 border border-gray-200 dark:border-dark-border transition"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    Download Template
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Import Instructions -->
                <div class="mt-6 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Import Instructions</h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Required Fields:</h4>
                            <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                <li class="flex items-start gap-2">
                                    <span class="text-red-400">*</span>
                                    <span><strong>name</strong> - Product name</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="text-red-400">*</span>
                                    <span><strong>sku</strong> - Unique SKU identifier</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="text-red-400">*</span>
                                    <span><strong>price</strong> - Selling price</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="text-red-400">*</span>
                                    <span><strong>stock</strong> - Current stock quantity</span>
                                </li>
                            </ul>
                        </div>

                        <div>
                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Important Notes:</h4>
                            <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                <li class="flex items-start gap-2">
                                    <svg class="w-4 h-4 text-primary-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span>Existing products (matching SKU) will be updated</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <svg class="w-4 h-4 text-primary-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span>Categories and locations will be created if they don't exist</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <svg class="w-4 h-4 text-primary-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span>Default currency is USD if not specified</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <svg class="w-4 h-4 text-primary-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span>Invalid rows will be skipped and reported</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
