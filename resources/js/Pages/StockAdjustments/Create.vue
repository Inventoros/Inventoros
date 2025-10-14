<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    products: Array,
    types: Object,
});

const form = useForm({
    product_id: '',
    type: 'manual',
    adjustment_quantity: 0,
    reason: '',
    notes: '',
});

const selectedProduct = computed(() => {
    return props.products.find(p => p.id === form.product_id);
});

const newStock = computed(() => {
    if (!selectedProduct.value) return 0;
    return selectedProduct.value.stock + parseInt(form.adjustment_quantity || 0);
});

const adjustmentType = computed(() => {
    if (form.adjustment_quantity > 0) return 'increase';
    if (form.adjustment_quantity < 0) return 'decrease';
    return 'none';
});

watch(() => form.adjustment_quantity, (newVal) => {
    // Auto-select reason based on adjustment type
    if (newVal > 0 && !form.reason) {
        form.reason = 'Stock increase';
    } else if (newVal < 0 && !form.reason) {
        form.reason = 'Stock decrease';
    }
});

const submit = () => {
    form.post(route('stock-adjustments.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Create Stock Adjustment" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Create Stock Adjustment</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manually adjust product stock levels</p>
                </div>
                <Link
                    :href="route('stock-adjustments.index')"
                    class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                >
                    Back to List
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
                <form @submit.prevent="submit" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-6">Adjustment Details</h3>

                    <div class="space-y-6">
                        <!-- Product Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Product <span class="text-red-500">*</span>
                            </label>
                            <select
                                v-model="form.product_id"
                                required
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                :class="{ 'border-red-500': form.errors.product_id }"
                            >
                                <option value="">Select a product</option>
                                <option v-for="product in products" :key="product.id" :value="product.id">
                                    {{ product.name }} ({{ product.sku }}) - Current Stock: {{ product.stock }}
                                </option>
                            </select>
                            <p v-if="form.errors.product_id" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.product_id }}
                            </p>
                        </div>

                        <!-- Current Stock Display -->
                        <div v-if="selectedProduct" class="p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                            <div class="grid grid-cols-3 gap-4 text-center">
                                <div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Current Stock</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ selectedProduct.stock }}</p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Adjustment</p>
                                    <p class="text-2xl font-bold" :class="{
                                        'text-green-600 dark:text-green-400': adjustmentType === 'increase',
                                        'text-red-600 dark:text-red-400': adjustmentType === 'decrease',
                                        'text-gray-600 dark:text-gray-400': adjustmentType === 'none'
                                    }">
                                        {{ form.adjustment_quantity > 0 ? '+' : '' }}{{ form.adjustment_quantity || 0 }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">New Stock</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ newStock }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Adjustment Type -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Type <span class="text-red-500">*</span>
                            </label>
                            <select
                                v-model="form.type"
                                required
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                :class="{ 'border-red-500': form.errors.type }"
                            >
                                <option v-for="(label, value) in types" :key="value" :value="value">{{ label }}</option>
                            </select>
                            <p v-if="form.errors.type" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.type }}
                            </p>
                        </div>

                        <!-- Adjustment Quantity -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Adjustment Quantity <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.adjustment_quantity"
                                type="number"
                                required
                                step="1"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                :class="{ 'border-red-500': form.errors.adjustment_quantity }"
                                placeholder="Enter positive number to add, negative to subtract"
                            />
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Use positive numbers to increase stock (+10), negative to decrease stock (-5)
                            </p>
                            <p v-if="form.errors.adjustment_quantity" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.adjustment_quantity }}
                            </p>
                        </div>

                        <!-- Reason -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Reason <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.reason"
                                type="text"
                                required
                                maxlength="255"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                :class="{ 'border-red-500': form.errors.reason }"
                                placeholder="e.g., Damaged items, Inventory recount, Customer return"
                            />
                            <p v-if="form.errors.reason" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.reason }}
                            </p>
                        </div>

                        <!-- Notes -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Notes (Optional)
                            </label>
                            <textarea
                                v-model="form.notes"
                                rows="4"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                :class="{ 'border-red-500': form.errors.notes }"
                                placeholder="Add any additional details about this adjustment..."
                            ></textarea>
                            <p v-if="form.errors.notes" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.notes }}
                            </p>
                        </div>

                        <!-- Warning for negative adjustments -->
                        <div v-if="form.adjustment_quantity < 0 && selectedProduct && newStock < 0" class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                            <div class="flex gap-3">
                                <svg class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <div>
                                    <p class="text-sm font-medium text-red-900 dark:text-red-100">Warning: Negative Stock</p>
                                    <p class="text-xs text-red-700 dark:text-red-300 mt-1">
                                        This adjustment will result in negative stock ({{ newStock }}). Please verify the quantity.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="mt-8 flex gap-3 justify-end pt-6 border-t border-gray-200 dark:border-dark-border">
                        <Link
                            :href="route('stock-adjustments.index')"
                            class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                        >
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing || !form.product_id || form.adjustment_quantity === 0"
                            class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {{ form.processing ? 'Creating...' : 'Create Adjustment' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
