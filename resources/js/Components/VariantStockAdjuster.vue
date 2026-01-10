<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
    variant: {
        type: Object,
        required: true
    },
    productId: {
        type: [Number, String],
        required: true
    }
});

const emit = defineEmits(['updated']);

const showPopover = ref(false);
const adjustType = ref('increase');
const quantity = ref(1);
const reason = ref('');
const loading = ref(false);
const error = ref('');

const adjustTypes = [
    { value: 'increase', label: 'Increase', icon: '+' },
    { value: 'decrease', label: 'Decrease', icon: '-' },
    { value: 'recount', label: 'Recount', icon: '=' },
    { value: 'damage', label: 'Damage', icon: '!' },
    { value: 'return', label: 'Return', icon: 'R' },
];

const stockStatus = computed(() => {
    if (props.variant.stock <= 0) {
        return { text: 'Out', class: 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300' };
    }
    if (props.variant.stock <= props.variant.min_stock) {
        return { text: 'Low', class: 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300' };
    }
    return null;
});

const openPopover = (type) => {
    adjustType.value = type;
    quantity.value = 1;
    reason.value = '';
    error.value = '';
    showPopover.value = true;
};

const closePopover = () => {
    showPopover.value = false;
};

const adjustStock = async () => {
    if (quantity.value < 1) {
        error.value = 'Quantity must be at least 1';
        return;
    }

    loading.value = true;
    error.value = '';

    try {
        const response = await axios.post(
            `/api/products/${props.productId}/variants/${props.variant.id}/adjust-stock`,
            {
                quantity: quantity.value,
                type: adjustType.value,
                reason: reason.value || null,
            }
        );

        emit('updated', response.data.data);
        closePopover();
    } catch (err) {
        error.value = err.response?.data?.message || 'Failed to adjust stock';
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <div class="relative inline-flex items-center gap-1">
        <!-- Stock Display -->
        <span class="font-medium text-gray-900 dark:text-gray-100 min-w-[3rem] text-center">
            {{ variant.stock }}
        </span>

        <!-- Status Badge -->
        <span
            v-if="stockStatus"
            :class="['px-1.5 py-0.5 text-xs font-medium rounded', stockStatus.class]"
        >
            {{ stockStatus.text }}
        </span>

        <!-- Adjust Buttons -->
        <div class="flex gap-0.5 ml-1">
            <button
                type="button"
                @click="openPopover('increase')"
                class="p-1 text-green-600 dark:text-green-400 hover:bg-green-100 dark:hover:bg-green-900/30 rounded transition"
                title="Increase stock"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m6-6H6" />
                </svg>
            </button>
            <button
                type="button"
                @click="openPopover('decrease')"
                class="p-1 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/30 rounded transition"
                title="Decrease stock"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 12H6" />
                </svg>
            </button>
        </div>

        <!-- Popover -->
        <Teleport to="body">
            <div
                v-if="showPopover"
                class="fixed inset-0 z-50"
                @click="closePopover"
            >
                <div
                    class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white dark:bg-dark-card rounded-lg shadow-xl border border-gray-200 dark:border-dark-border p-4 w-72"
                    @click.stop
                >
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">
                        Adjust Stock: {{ variant.title }}
                    </h4>

                    <!-- Type Selection -->
                    <div class="mb-3">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                            Adjustment Type
                        </label>
                        <select
                            v-model="adjustType"
                            class="w-full text-sm rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                        >
                            <option v-for="type in adjustTypes" :key="type.value" :value="type.value">
                                {{ type.label }}
                            </option>
                        </select>
                    </div>

                    <!-- Quantity -->
                    <div class="mb-3">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                            Quantity
                        </label>
                        <input
                            v-model.number="quantity"
                            type="number"
                            min="1"
                            class="w-full text-sm rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                        />
                    </div>

                    <!-- Reason -->
                    <div class="mb-3">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                            Reason (optional)
                        </label>
                        <input
                            v-model="reason"
                            type="text"
                            placeholder="e.g., Restock, Damaged goods"
                            class="w-full text-sm rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                        />
                    </div>

                    <!-- Error -->
                    <p v-if="error" class="text-xs text-red-500 mb-3">{{ error }}</p>

                    <!-- Actions -->
                    <div class="flex gap-2">
                        <button
                            type="button"
                            @click="closePopover"
                            class="flex-1 px-3 py-1.5 text-sm bg-gray-100 dark:bg-dark-bg text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-200 dark:hover:bg-dark-border"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            @click="adjustStock"
                            :disabled="loading"
                            class="flex-1 px-3 py-1.5 text-sm bg-primary-400 text-white rounded-md hover:bg-primary-500 disabled:opacity-50"
                        >
                            <span v-if="loading">...</span>
                            <span v-else>Apply</span>
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
