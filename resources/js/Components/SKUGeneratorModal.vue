<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import QuickAddModal from './QuickAddModal.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false
    },
    productName: {
        type: String,
        default: ''
    },
    categoryId: {
        type: [Number, String],
        default: null
    }
});

const emit = defineEmits(['apply', 'close']);

const skuPatterns = ref({ variables: [], presets: [] });
const selectedPattern = ref('');
const customPattern = ref('');
const skuPreview = ref('');
const skuGenerating = ref(false);
const loading = ref(false);

// Load patterns when modal opens
watch(() => props.show, async (newValue) => {
    if (newValue) {
        await loadSKUPatterns();
    } else {
        // Reset state when closed
        selectedPattern.value = '';
        customPattern.value = '';
        skuPreview.value = '';
    }
});

const loadSKUPatterns = async () => {
    loading.value = true;
    try {
        const response = await axios.get(route('sku.patterns'));
        skuPatterns.value = response.data;
    } catch (error) {
        console.error('Error loading SKU patterns:', error);
    } finally {
        loading.value = false;
    }
};

const generateSKUPreview = async (pattern) => {
    if (!pattern) {
        skuPreview.value = '';
        return;
    }

    skuGenerating.value = true;
    try {
        const response = await axios.post(route('sku.generate'), {
            pattern: pattern,
            product_name: props.productName || null,
            category_id: props.categoryId || null,
        });
        skuPreview.value = response.data.sku;
    } catch (error) {
        console.error('Error generating SKU:', error);
        skuPreview.value = 'Error generating preview';
    } finally {
        skuGenerating.value = false;
    }
};

const selectPreset = (preset) => {
    selectedPattern.value = preset.pattern;
    customPattern.value = '';
    generateSKUPreview(preset.pattern);
};

const onCustomPatternInput = () => {
    selectedPattern.value = '';
    generateSKUPreview(customPattern.value);
};

const applySKU = () => {
    if (skuPreview.value && skuPreview.value !== 'Error generating preview') {
        emit('apply', skuPreview.value);
        emit('close');
    }
};

const close = () => {
    emit('close');
};
</script>

<template>
    <QuickAddModal
        :show="show"
        title="SKU Generator"
        :loading="loading"
        max-width="2xl"
        @close="close"
    >
        <div class="space-y-4">
            <!-- Preset Patterns -->
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">
                    Choose a Preset Pattern
                </label>
                <div class="grid grid-cols-2 gap-2">
                    <button
                        v-for="preset in skuPatterns.presets"
                        :key="preset.pattern"
                        type="button"
                        @click="selectPreset(preset)"
                        :class="[
                            'p-3 text-left rounded-lg border-2 transition',
                            selectedPattern === preset.pattern
                                ? 'border-primary-400 bg-primary-400/10'
                                : 'border-gray-200 dark:border-dark-border hover:border-primary-400/50'
                        ]"
                    >
                        <div class="font-medium text-sm text-gray-900 dark:text-gray-100">{{ preset.name }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 font-mono mt-1">{{ preset.example }}</div>
                    </button>
                </div>
            </div>

            <div class="relative">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-gray-200 dark:border-dark-border"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="px-2 bg-white dark:bg-dark-card text-gray-500 dark:text-gray-400">OR</span>
                </div>
            </div>

            <!-- Custom Pattern -->
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">
                    Custom Pattern
                </label>
                <input
                    v-model="customPattern"
                    @input="onCustomPatternInput"
                    type="text"
                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                    placeholder="e.g., {category}-{year}-{number}"
                />
            </div>

            <!-- Available Variables -->
            <div class="p-4 bg-gray-50 dark:bg-dark-bg rounded-lg">
                <p class="text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">Available Variables:</p>
                <div class="grid grid-cols-2 gap-2">
                    <div v-for="variable in skuPatterns.variables" :key="variable.key" class="text-xs">
                        <code class="px-1 py-0.5 bg-gray-200 dark:bg-dark-card rounded text-primary-400">{{ variable.key }}</code>
                        <span class="text-gray-600 dark:text-gray-400 ml-1">{{ variable.description }}</span>
                    </div>
                </div>
            </div>

            <!-- Preview -->
            <div v-if="skuPreview || skuGenerating" class="p-4 bg-primary-900/20 rounded-lg border border-primary-800">
                <p class="text-sm font-medium text-gray-300 mb-2">Preview:</p>
                <div v-if="skuGenerating" class="flex items-center gap-2">
                    <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-primary-400"></div>
                    <span class="text-sm text-gray-400">Generating...</span>
                </div>
                <p v-else class="text-lg font-mono font-bold text-primary-400">{{ skuPreview }}</p>
            </div>
        </div>

        <template #actions>
            <button
                type="button"
                @click="close"
                class="px-4 py-2 bg-dark-bg text-gray-600 dark:text-gray-300 rounded-md hover:bg-gray-100 dark:hover:bg-dark-bg/50"
            >
                Cancel
            </button>
            <button
                type="button"
                @click="applySKU"
                :disabled="!skuPreview || skuPreview === 'Error generating preview'"
                class="px-4 py-2 bg-primary-400 text-white rounded-md hover:bg-primary-500 disabled:opacity-50"
            >
                Apply SKU
            </button>
        </template>
    </QuickAddModal>
</template>
