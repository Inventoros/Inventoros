<script setup>
import { ref, watch } from 'vue';
import BarcodeScanner from '@/Components/BarcodeScanner.vue';
import axios from 'axios';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close', 'product-found', 'error']);

const manualCode = ref('');
const isLoading = ref(false);
const scannerEnabled = ref(false);
const errorMessage = ref('');
const foundProduct = ref(null);
const scannerMode = ref('camera'); // 'camera' or 'manual'

watch(() => props.show, (newVal) => {
    if (newVal) {
        scannerEnabled.value = scannerMode.value === 'camera';
        errorMessage.value = '';
        foundProduct.value = null;
        manualCode.value = '';
    } else {
        scannerEnabled.value = false;
    }
});

const lookupBarcode = async (code) => {
    if (!code || isLoading.value) return;

    isLoading.value = true;
    errorMessage.value = '';
    foundProduct.value = null;

    try {
        const response = await axios.get(`/api/v1/barcode/${encodeURIComponent(code)}`);

        if (response.data.found) {
            foundProduct.value = response.data.product;
            emit('product-found', response.data.product);
        } else {
            errorMessage.value = 'Product not found';
        }
    } catch (err) {
        if (err.response?.status === 404) {
            errorMessage.value = 'No product found with this barcode or SKU';
        } else if (err.response?.status === 401) {
            errorMessage.value = 'Authentication required';
        } else {
            errorMessage.value = err.response?.data?.message || 'Failed to lookup barcode';
        }
        emit('error', errorMessage.value);
    } finally {
        isLoading.value = false;
    }
};

const onScan = (code) => {
    lookupBarcode(code);
};

const onScanError = (error) => {
    errorMessage.value = error;
};

const submitManualCode = () => {
    if (manualCode.value.trim()) {
        lookupBarcode(manualCode.value.trim());
    }
};

const toggleMode = () => {
    if (scannerMode.value === 'camera') {
        scannerMode.value = 'manual';
        scannerEnabled.value = false;
    } else {
        scannerMode.value = 'camera';
        scannerEnabled.value = true;
    }
    errorMessage.value = '';
    foundProduct.value = null;
};

const close = () => {
    scannerEnabled.value = false;
    emit('close');
};

const selectProduct = () => {
    if (foundProduct.value) {
        emit('product-found', foundProduct.value);
        close();
    }
};
</script>

<template>
    <Teleport to="body">
        <Transition name="modal">
            <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center">
                <!-- Backdrop -->
                <div class="absolute inset-0 bg-black/50" @click="close"></div>

                <!-- Modal -->
                <div class="relative bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-xl max-w-md w-full mx-4 max-h-[90vh] overflow-y-auto">
                    <!-- Header -->
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                            Scan Barcode
                        </h3>
                        <button @click="close" class="text-gray-400 hover:text-gray-500">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="p-6 space-y-4">
                        <!-- Mode Toggle -->
                        <div class="flex items-center justify-center gap-2">
                            <button
                                @click="toggleMode"
                                :class="[
                                    'px-4 py-2 text-sm rounded-md transition-colors',
                                    scannerMode === 'camera'
                                        ? 'bg-primary-400 text-white'
                                        : 'bg-gray-100 dark:bg-dark-bg text-gray-700 dark:text-gray-300'
                                ]"
                            >
                                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                Camera
                            </button>
                            <button
                                @click="toggleMode"
                                :class="[
                                    'px-4 py-2 text-sm rounded-md transition-colors',
                                    scannerMode === 'manual'
                                        ? 'bg-primary-400 text-white'
                                        : 'bg-gray-100 dark:bg-dark-bg text-gray-700 dark:text-gray-300'
                                ]"
                            >
                                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                                Manual Entry
                            </button>
                        </div>

                        <!-- Camera Scanner -->
                        <div v-if="scannerMode === 'camera'" class="flex justify-center">
                            <BarcodeScanner
                                :enabled="scannerEnabled"
                                :width="300"
                                :height="200"
                                @scan="onScan"
                                @error="onScanError"
                            />
                        </div>

                        <!-- Manual Entry -->
                        <div v-else class="space-y-3">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Enter Barcode or SKU
                            </label>
                            <input
                                v-model="manualCode"
                                type="text"
                                placeholder="Enter barcode or SKU..."
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                @keyup.enter="submitManualCode"
                            />
                            <button
                                @click="submitManualCode"
                                :disabled="!manualCode.trim() || isLoading"
                                class="w-full inline-flex justify-center items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 disabled:opacity-50"
                            >
                                <svg v-if="isLoading" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Lookup
                            </button>
                        </div>

                        <!-- Loading State -->
                        <div v-if="isLoading && scannerMode === 'camera'" class="text-center text-gray-500 dark:text-gray-400">
                            <svg class="animate-spin mx-auto h-6 w-6" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <p class="mt-2 text-sm">Looking up product...</p>
                        </div>

                        <!-- Error Message -->
                        <div v-if="errorMessage" class="p-3 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 rounded-md text-sm">
                            {{ errorMessage }}
                        </div>

                        <!-- Found Product -->
                        <div v-if="foundProduct" class="p-4 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded-md">
                            <h4 class="font-medium text-green-800 dark:text-green-400 mb-2">Product Found</h4>
                            <dl class="text-sm space-y-1">
                                <div class="flex justify-between">
                                    <dt class="text-gray-600 dark:text-gray-400">Name:</dt>
                                    <dd class="text-gray-900 dark:text-gray-100 font-medium">{{ foundProduct.name }}</dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-600 dark:text-gray-400">SKU:</dt>
                                    <dd class="text-gray-900 dark:text-gray-100">{{ foundProduct.sku || '-' }}</dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-600 dark:text-gray-400">Stock:</dt>
                                    <dd class="text-gray-900 dark:text-gray-100">{{ foundProduct.stock }}</dd>
                                </div>
                            </dl>
                            <button
                                @click="selectProduct"
                                class="mt-3 w-full inline-flex justify-center items-center px-4 py-2 bg-green-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-600"
                            >
                                Select Product
                            </button>
                        </div>

                        <!-- Instructions -->
                        <p v-if="scannerMode === 'camera' && !errorMessage && !foundProduct && !isLoading" class="text-center text-sm text-gray-500 dark:text-gray-400">
                            Point your camera at a barcode to scan
                        </p>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
</style>
