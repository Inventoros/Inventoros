<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    assemblyProducts: Array,
    warehouses: Array,
    preselectedProductId: [Number, String],
});

const form = useForm({
    product_id: props.preselectedProductId || '',
    quantity: 1,
    warehouse_id: '',
    notes: '',
});

const selectedProduct = computed(() => {
    if (!form.product_id) return null;
    return props.assemblyProducts.find(p => p.id == form.product_id);
});

const requiredComponents = computed(() => {
    if (!selectedProduct.value || !selectedProduct.value.components) return [];
    return selectedProduct.value.components.map(comp => ({
        ...comp,
        required_qty: comp.quantity * form.quantity,
        available: comp.component?.stock || 0,
        sufficient: (comp.component?.stock || 0) >= comp.quantity * form.quantity,
    }));
});

const allComponentsSufficient = computed(() => {
    return requiredComponents.value.length > 0 && requiredComponents.value.every(c => c.sufficient);
});

const submit = () => {
    form.post(route('work-orders.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Create Work Order" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Create Work Order</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Start a new production work order for an assembly product</p>
                </div>
                <Link
                    :href="route('work-orders.index')"
                    class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                >
                    Back to Work Orders
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <div class="p-6">
                        <form @submit.prevent="submit">
                            <div class="space-y-6">
                                <!-- Assembly Product -->
                                <div>
                                    <label for="product_id" class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                                        Assembly Product <span class="text-red-500">*</span>
                                    </label>
                                    <select
                                        id="product_id"
                                        v-model="form.product_id"
                                        class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                        required
                                    >
                                        <option value="">Select an assembly product...</option>
                                        <option v-for="product in assemblyProducts" :key="product.id" :value="product.id">
                                            {{ product.name }} ({{ product.sku }})
                                        </option>
                                    </select>
                                    <p v-if="form.errors.product_id" class="mt-1 text-sm text-red-400">
                                        {{ form.errors.product_id }}
                                    </p>
                                </div>

                                <!-- Quantity -->
                                <div>
                                    <label for="quantity" class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                                        Quantity to Produce <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="quantity"
                                        v-model.number="form.quantity"
                                        type="number"
                                        min="1"
                                        class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                        required
                                    />
                                    <p v-if="form.errors.quantity" class="mt-1 text-sm text-red-400">
                                        {{ form.errors.quantity }}
                                    </p>
                                </div>

                                <!-- Warehouse -->
                                <div v-if="warehouses && warehouses.length > 0">
                                    <label for="warehouse_id" class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                                        Warehouse
                                    </label>
                                    <select
                                        id="warehouse_id"
                                        v-model="form.warehouse_id"
                                        class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    >
                                        <option value="">No specific warehouse</option>
                                        <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">
                                            {{ warehouse.name }}
                                        </option>
                                    </select>
                                    <p v-if="form.errors.warehouse_id" class="mt-1 text-sm text-red-400">
                                        {{ form.errors.warehouse_id }}
                                    </p>
                                </div>

                                <!-- Notes -->
                                <div>
                                    <label for="notes" class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">
                                        Notes
                                    </label>
                                    <textarea
                                        id="notes"
                                        v-model="form.notes"
                                        rows="3"
                                        class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                        placeholder="Optional production notes..."
                                    ></textarea>
                                    <p v-if="form.errors.notes" class="mt-1 text-sm text-red-400">
                                        {{ form.errors.notes }}
                                    </p>
                                </div>

                                <!-- Required Components Preview -->
                                <div v-if="selectedProduct && requiredComponents.length > 0" class="border border-gray-200 dark:border-dark-border rounded-lg overflow-hidden">
                                    <div class="bg-gray-50 dark:bg-dark-bg px-4 py-3 border-b border-gray-200 dark:border-dark-border">
                                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                            Required Components
                                        </h3>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                            Materials needed to produce {{ form.quantity }} unit{{ form.quantity !== 1 ? 's' : '' }} of {{ selectedProduct.name }}
                                        </p>
                                    </div>
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                                        <thead class="bg-gray-50 dark:bg-dark-bg">
                                            <tr>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Component</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">SKU</th>
                                                <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Required</th>
                                                <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Available</th>
                                                <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white dark:bg-dark-card divide-y divide-gray-200 dark:divide-dark-border">
                                            <tr
                                                v-for="comp in requiredComponents"
                                                :key="comp.id"
                                                :class="!comp.sufficient ? 'bg-red-900/10' : ''"
                                            >
                                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                                    {{ comp.component?.name || 'Unknown' }}
                                                </td>
                                                <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                                    {{ comp.component?.sku || '-' }}
                                                </td>
                                                <td class="px-4 py-3 text-center text-sm font-medium text-gray-900 dark:text-gray-100">
                                                    {{ comp.required_qty }}
                                                </td>
                                                <td class="px-4 py-3 text-center text-sm" :class="comp.sufficient ? 'text-green-400' : 'text-red-400 font-semibold'">
                                                    {{ comp.available }}
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <span
                                                        class="px-2 py-0.5 text-xs font-semibold rounded-full"
                                                        :class="comp.sufficient ? 'bg-green-900/30 text-green-300' : 'bg-red-900/30 text-red-300'"
                                                    >
                                                        {{ comp.sufficient ? 'OK' : 'Insufficient' }}
                                                    </span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <!-- Insufficient Stock Warning -->
                                    <div v-if="!allComponentsSufficient" class="px-4 py-3 bg-red-900/20 border-t border-red-800">
                                        <p class="text-sm text-red-300 flex items-center">
                                            <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                            </svg>
                                            Some components have insufficient stock. You can still create the work order, but production cannot begin until stock is replenished.
                                        </p>
                                    </div>
                                </div>

                                <!-- No components warning -->
                                <div v-if="selectedProduct && requiredComponents.length === 0" class="p-4 bg-yellow-900/20 rounded-lg border border-yellow-800">
                                    <p class="text-sm text-yellow-300 flex items-center">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                        </svg>
                                        This assembly product has no components defined. Add components in the product detail page before creating a work order.
                                    </p>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="mt-6 flex items-center justify-end gap-4">
                                <Link
                                    :href="route('work-orders.index')"
                                    class="inline-flex items-center px-4 py-2 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                                >
                                    Cancel
                                </Link>
                                <button
                                    type="submit"
                                    class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 focus:bg-primary-500 active:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-400 focus:ring-offset-2 focus:ring-offset-dark-bg transition ease-in-out duration-150"
                                    :class="{ 'opacity-25': form.processing }"
                                    :disabled="form.processing"
                                >
                                    <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Create Work Order
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
