<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({ options: [], variants: [] })
    },
    productPrice: {
        type: [Number, String],
        default: 0
    },
    productPurchasePrice: {
        type: [Number, String],
        default: 0
    },
    currencySymbol: {
        type: String,
        default: '$'
    },
    disabled: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['update:modelValue']);

// Local state
const options = ref(props.modelValue.options || []);
const variants = ref(props.modelValue.variants || []);
const showAddOption = ref(false);
const newOptionName = ref('');
const newOptionValues = ref('');
const editingVariant = ref(null);

// Max limits (Shopify-compatible)
const MAX_OPTIONS = 3;
const MAX_VARIANTS = 2048;

// Computed
const canAddOption = computed(() => options.value.length < MAX_OPTIONS);
const hasOptions = computed(() => options.value.length > 0);

const variantCount = computed(() => {
    if (options.value.length === 0) return 0;
    return options.value.reduce((acc, opt) => acc * (opt.values?.length || 1), 1);
});

const exceedsVariantLimit = computed(() => variantCount.value > MAX_VARIANTS);

// Watch for external changes
watch(() => props.modelValue, (newVal) => {
    options.value = newVal.options || [];
    variants.value = newVal.variants || [];
}, { deep: true });

// Update parent
const emitUpdate = () => {
    emit('update:modelValue', {
        options: options.value,
        variants: variants.value
    });
};

// Add new option
const addOption = () => {
    if (!newOptionName.value.trim()) return;
    if (options.value.length >= MAX_OPTIONS) return;

    const values = newOptionValues.value
        .split(',')
        .map(v => v.trim())
        .filter(v => v.length > 0);

    if (values.length === 0) return;

    options.value.push({
        name: newOptionName.value.trim(),
        values: values,
        position: options.value.length
    });

    newOptionName.value = '';
    newOptionValues.value = '';
    showAddOption.value = false;

    generateVariants();
    emitUpdate();
};

// Remove option
const removeOption = (index) => {
    options.value.splice(index, 1);
    options.value.forEach((opt, i) => opt.position = i);
    generateVariants();
    emitUpdate();
};

// Add value to existing option
const addValueToOption = (optionIndex, value) => {
    if (!value.trim()) return;
    if (!options.value[optionIndex].values.includes(value.trim())) {
        options.value[optionIndex].values.push(value.trim());
        generateVariants();
        emitUpdate();
    }
};

// Remove value from option
const removeValueFromOption = (optionIndex, valueIndex) => {
    options.value[optionIndex].values.splice(valueIndex, 1);
    if (options.value[optionIndex].values.length === 0) {
        removeOption(optionIndex);
    } else {
        generateVariants();
        emitUpdate();
    }
};

// Generate all variant combinations
const generateVariants = () => {
    if (options.value.length === 0) {
        variants.value = [];
        return;
    }

    const combinations = generateCombinations(options.value);
    const existingVariants = new Map(
        variants.value.map(v => [JSON.stringify(v.option_values), v])
    );

    variants.value = combinations.map((combo, index) => {
        const key = JSON.stringify(combo);
        const existing = existingVariants.get(key);

        if (existing) {
            return { ...existing, position: index };
        }

        return {
            option_values: combo,
            title: Object.values(combo).join(' / '),
            sku: '',
            barcode: '',
            price: null,
            purchase_price: null,
            stock: 0,
            min_stock: 0,
            is_active: true,
            position: index
        };
    });
};

// Helper to generate all combinations
const generateCombinations = (opts) => {
    if (opts.length === 0) return [];

    let combinations = [{}];

    for (const option of opts) {
        const newCombinations = [];
        for (const combo of combinations) {
            for (const value of option.values) {
                newCombinations.push({ ...combo, [option.name]: value });
            }
        }
        combinations = newCombinations;
    }

    return combinations;
};

// Update variant field
const updateVariant = (index, field, value) => {
    variants.value[index][field] = value;
    emitUpdate();
};

// Bulk update all variants
const bulkUpdateVariants = (field, value) => {
    variants.value.forEach(v => {
        v[field] = value;
    });
    emitUpdate();
};

// Get effective price for display
const getEffectivePrice = (variant) => {
    return variant.price ?? props.productPrice ?? 0;
};

const getEffectivePurchasePrice = (variant) => {
    return variant.purchase_price ?? props.productPurchasePrice ?? 0;
};
</script>

<template>
    <div class="space-y-6">
        <!-- Options Section -->
        <div>
            <div class="flex items-center justify-between mb-4">
                <h4 class="text-md font-semibold text-gray-900 dark:text-gray-100">
                    Product Options
                </h4>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {{ options.length }}/{{ MAX_OPTIONS }} options
                </span>
            </div>

            <!-- Existing Options -->
            <div v-if="options.length > 0" class="space-y-4 mb-4">
                <div
                    v-for="(option, optIndex) in options"
                    :key="optIndex"
                    class="p-4 bg-gray-50 dark:bg-dark-bg rounded-lg border border-gray-200 dark:border-dark-border"
                >
                    <div class="flex items-center justify-between mb-3">
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ option.name }}</span>
                        <button
                            type="button"
                            @click="removeOption(optIndex)"
                            :disabled="disabled"
                            class="text-red-400 hover:text-red-300 disabled:opacity-50"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="(value, valIndex) in option.values"
                            :key="valIndex"
                            class="inline-flex items-center gap-1 px-3 py-1 bg-primary-400/20 text-primary-400 rounded-full text-sm"
                        >
                            {{ value }}
                            <button
                                type="button"
                                @click="removeValueFromOption(optIndex, valIndex)"
                                :disabled="disabled"
                                class="ml-1 hover:text-red-400 disabled:opacity-50"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </span>

                        <!-- Add value input -->
                        <input
                            type="text"
                            placeholder="Add value..."
                            class="px-3 py-1 text-sm bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-full text-gray-900 dark:text-gray-100 w-32"
                            :disabled="disabled"
                            @keyup.enter="addValueToOption(optIndex, $event.target.value); $event.target.value = ''"
                        />
                    </div>
                </div>
            </div>

            <!-- Add Option Form -->
            <div v-if="showAddOption && canAddOption" class="p-4 bg-gray-50 dark:bg-dark-bg rounded-lg border border-gray-200 dark:border-dark-border border-dashed">
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                            Option Name
                        </label>
                        <input
                            v-model="newOptionName"
                            type="text"
                            placeholder="e.g., Size, Color, Material"
                            class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            :disabled="disabled"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                            Option Values (comma-separated)
                        </label>
                        <input
                            v-model="newOptionValues"
                            type="text"
                            placeholder="e.g., Small, Medium, Large"
                            class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            :disabled="disabled"
                        />
                    </div>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            @click="addOption"
                            :disabled="disabled || !newOptionName || !newOptionValues"
                            class="px-4 py-2 bg-primary-400 text-white rounded-md hover:bg-primary-500 disabled:opacity-50"
                        >
                            Add Option
                        </button>
                        <button
                            type="button"
                            @click="showAddOption = false; newOptionName = ''; newOptionValues = ''"
                            class="px-4 py-2 bg-gray-200 dark:bg-dark-card text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-300 dark:hover:bg-dark-border"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            </div>

            <!-- Add Option Button -->
            <button
                v-if="!showAddOption && canAddOption"
                type="button"
                @click="showAddOption = true"
                :disabled="disabled"
                class="w-full p-4 border-2 border-dashed border-gray-300 dark:border-dark-border rounded-lg text-gray-500 dark:text-gray-400 hover:border-primary-400 hover:text-primary-400 transition disabled:opacity-50"
            >
                <svg class="w-6 h-6 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                </svg>
                Add Option (e.g., Size, Color)
            </button>
        </div>

        <!-- Variant Limit Warning -->
        <div v-if="exceedsVariantLimit" class="p-4 bg-red-900/20 border border-red-800 rounded-lg">
            <p class="text-red-400 text-sm">
                Warning: Current options would create {{ variantCount }} variants, exceeding the limit of {{ MAX_VARIANTS }}.
                Please reduce the number of option values.
            </p>
        </div>

        <!-- Variants Section -->
        <div v-if="hasOptions && !exceedsVariantLimit">
            <div class="flex items-center justify-between mb-4">
                <h4 class="text-md font-semibold text-gray-900 dark:text-gray-100">
                    Variants ({{ variants.length }})
                </h4>
            </div>

            <!-- Variants Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                    <thead class="bg-gray-50 dark:bg-dark-bg">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                                Variant
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                                SKU
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                                Price
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                                Stock
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                                Active
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-dark-card divide-y divide-gray-200 dark:divide-dark-border">
                        <tr v-for="(variant, index) in variants" :key="index">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {{ variant.title }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <input
                                    :value="variant.sku"
                                    @input="updateVariant(index, 'sku', $event.target.value)"
                                    type="text"
                                    placeholder="Auto-generate"
                                    class="w-32 text-sm rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                                    :disabled="disabled"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <div class="relative w-28">
                                    <span class="absolute inset-y-0 left-0 pl-2 flex items-center text-gray-500 text-sm">
                                        {{ currencySymbol }}
                                    </span>
                                    <input
                                        :value="variant.price"
                                        @input="updateVariant(index, 'price', $event.target.value ? parseFloat($event.target.value) : null)"
                                        type="number"
                                        step="0.01"
                                        :placeholder="productPrice?.toString() || '0.00'"
                                        class="pl-6 w-full text-sm rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                                        :disabled="disabled"
                                    />
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <input
                                    :value="variant.stock"
                                    @input="updateVariant(index, 'stock', parseInt($event.target.value) || 0)"
                                    type="number"
                                    min="0"
                                    class="w-20 text-sm rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                                    :disabled="disabled"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input
                                        type="checkbox"
                                        :checked="variant.is_active"
                                        @change="updateVariant(index, 'is_active', $event.target.checked)"
                                        class="sr-only peer"
                                        :disabled="disabled"
                                    />
                                    <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-400/25 rounded-full peer dark:bg-dark-border peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all dark:border-gray-600 peer-checked:bg-primary-400"></div>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Bulk Actions -->
            <div class="mt-4 flex gap-4 text-sm">
                <button
                    type="button"
                    @click="bulkUpdateVariants('is_active', true)"
                    :disabled="disabled"
                    class="text-primary-400 hover:text-primary-300 disabled:opacity-50"
                >
                    Enable All
                </button>
                <button
                    type="button"
                    @click="bulkUpdateVariants('is_active', false)"
                    :disabled="disabled"
                    class="text-primary-400 hover:text-primary-300 disabled:opacity-50"
                >
                    Disable All
                </button>
            </div>
        </div>

        <!-- No Variants Message -->
        <div v-if="!hasOptions" class="text-center py-8 text-gray-500 dark:text-gray-400">
            <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
            </svg>
            <p>Add options like Size or Color to create product variants</p>
        </div>
    </div>
</template>
