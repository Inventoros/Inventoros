<script setup>
import { computed } from 'vue';
import VariantStockAdjuster from './VariantStockAdjuster.vue';

const props = defineProps({
    variants: {
        type: Array,
        default: () => []
    },
    productId: {
        type: [Number, String],
        required: true
    },
    currencySymbol: {
        type: String,
        default: '$'
    },
    showStockAdjust: {
        type: Boolean,
        default: true
    }
});

const emit = defineEmits(['variant-updated']);

const formatPrice = (price) => {
    if (price === null || price === undefined) return '-';
    return `${props.currencySymbol}${parseFloat(price).toFixed(2)}`;
};

const getStatusBadge = (variant) => {
    if (!variant.is_active) {
        return { text: 'Inactive', class: 'bg-gray-100 dark:bg-gray-900/30 text-gray-600 dark:text-gray-400' };
    }
    return { text: 'Active', class: 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300' };
};

const onVariantUpdated = (updatedVariant) => {
    emit('variant-updated', updatedVariant);
};
</script>

<template>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
            <thead class="bg-gray-50 dark:bg-dark-bg">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Variant
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        SKU
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Price
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Stock
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        Status
                    </th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-dark-card divide-y divide-gray-200 dark:divide-dark-border">
                <tr v-for="variant in variants" :key="variant.id" class="hover:bg-gray-50 dark:hover:bg-dark-bg/50">
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ variant.title }}
                        </span>
                        <p v-if="variant.barcode" class="text-xs text-gray-500 dark:text-gray-400">
                            {{ variant.barcode }}
                        </p>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="text-sm text-gray-600 dark:text-gray-300 font-mono">
                            {{ variant.sku || '-' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ formatPrice(variant.price) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <VariantStockAdjuster
                            v-if="showStockAdjust"
                            :variant="variant"
                            :product-id="productId"
                            @updated="onVariantUpdated"
                        />
                        <span v-else class="text-sm text-gray-900 dark:text-gray-100">
                            {{ variant.stock }}
                        </span>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span :class="['px-2 py-1 text-xs font-medium rounded-full', getStatusBadge(variant).class]">
                            {{ getStatusBadge(variant).text }}
                        </span>
                    </td>
                </tr>
                <tr v-if="variants.length === 0">
                    <td colspan="5" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                        No variants found
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Summary Row -->
        <div v-if="variants.length > 0" class="mt-3 px-4 py-2 bg-gray-50 dark:bg-dark-bg rounded-lg flex items-center justify-between text-sm">
            <span class="text-gray-600 dark:text-gray-400">
                Total Variants: <span class="font-medium text-gray-900 dark:text-gray-100">{{ variants.length }}</span>
            </span>
            <span class="text-gray-600 dark:text-gray-400">
                Total Stock: <span class="font-medium text-gray-900 dark:text-gray-100">{{ variants.reduce((sum, v) => sum + (v.stock || 0), 0) }}</span>
            </span>
        </div>
    </div>
</template>
