<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    locations: Array,
    products: Array,
});

const form = useForm({
    from_location_id: '',
    to_location_id: '',
    notes: '',
    items: [
        { product_id: '', quantity: 1, notes: '' },
    ],
});

const addItem = () => {
    form.items.push({ product_id: '', quantity: 1, notes: '' });
};

const removeItem = (index) => {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
    }
};

const availableToLocations = computed(() => {
    return props.locations.filter(loc => loc.id !== parseInt(form.from_location_id));
});

const getProduct = (productId) => {
    return props.products.find(p => p.id === parseInt(productId));
};

const totalItems = computed(() => {
    return form.items.reduce((sum, item) => sum + (parseInt(item.quantity) || 0), 0);
});

const hasValidItems = computed(() => {
    return form.items.some(item => item.product_id && item.quantity > 0);
});

const submit = () => {
    form.post(route('stock-transfers.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Create Stock Transfer" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Create Stock Transfer</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Transfer inventory between locations</p>
                </div>
                <Link
                    :href="route('stock-transfers.index')"
                    class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                >
                    Back to List
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <form @submit.prevent="submit" class="space-y-6">
                    <!-- Location Selection -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-6">Transfer Details</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- From Location -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    From Location <span class="text-red-500">*</span>
                                </label>
                                <select
                                    v-model="form.from_location_id"
                                    required
                                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    :class="{ 'border-red-500': form.errors.from_location_id }"
                                >
                                    <option value="">Select source location</option>
                                    <option v-for="location in locations" :key="location.id" :value="location.id">
                                        {{ location.name }} {{ location.code ? `(${location.code})` : '' }}
                                    </option>
                                </select>
                                <p v-if="form.errors.from_location_id" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                    {{ form.errors.from_location_id }}
                                </p>
                            </div>

                            <!-- To Location -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    To Location <span class="text-red-500">*</span>
                                </label>
                                <select
                                    v-model="form.to_location_id"
                                    required
                                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    :class="{ 'border-red-500': form.errors.to_location_id }"
                                >
                                    <option value="">Select destination location</option>
                                    <option v-for="location in availableToLocations" :key="location.id" :value="location.id">
                                        {{ location.name }} {{ location.code ? `(${location.code})` : '' }}
                                    </option>
                                </select>
                                <p v-if="form.errors.to_location_id" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                    {{ form.errors.to_location_id }}
                                </p>
                            </div>
                        </div>

                        <!-- Arrow indicator between locations -->
                        <div v-if="form.from_location_id && form.to_location_id" class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                            <div class="flex items-center justify-center gap-3 text-sm text-blue-800 dark:text-blue-300">
                                <span class="font-medium">{{ locations.find(l => l.id === parseInt(form.from_location_id))?.name }}</span>
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                                </svg>
                                <span class="font-medium">{{ locations.find(l => l.id === parseInt(form.to_location_id))?.name }}</span>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="mt-6">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Notes (Optional)
                            </label>
                            <textarea
                                v-model="form.notes"
                                rows="3"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                placeholder="Add any notes about this transfer..."
                            ></textarea>
                            <p v-if="form.errors.notes" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.notes }}
                            </p>
                        </div>
                    </div>

                    <!-- Transfer Items -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Transfer Items</h3>
                            <button
                                type="button"
                                @click="addItem"
                                class="px-3 py-1.5 bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium rounded-lg transition flex items-center gap-1"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                Add Item
                            </button>
                        </div>

                        <p v-if="form.errors.items" class="mb-4 text-sm text-red-600 dark:text-red-400">
                            {{ form.errors.items }}
                        </p>

                        <div class="space-y-4">
                            <div
                                v-for="(item, index) in form.items"
                                :key="index"
                                class="flex gap-4 items-start p-4 bg-gray-50 dark:bg-dark-bg rounded-lg border border-gray-200 dark:border-dark-border"
                            >
                                <!-- Product -->
                                <div class="flex-1 min-w-[200px]">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Product <span class="text-red-500">*</span>
                                    </label>
                                    <select
                                        v-model="item.product_id"
                                        required
                                        class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm"
                                        :class="{ 'border-red-500': form.errors[`items.${index}.product_id`] }"
                                    >
                                        <option value="">Select product</option>
                                        <option v-for="product in products" :key="product.id" :value="product.id">
                                            {{ product.name }} ({{ product.sku }}) - Stock: {{ product.stock }}
                                        </option>
                                    </select>
                                    <p v-if="form.errors[`items.${index}.product_id`]" class="mt-1 text-xs text-red-600 dark:text-red-400">
                                        {{ form.errors[`items.${index}.product_id`] }}
                                    </p>
                                </div>

                                <!-- Quantity -->
                                <div class="w-32">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Qty <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        v-model="item.quantity"
                                        type="number"
                                        min="1"
                                        required
                                        class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm"
                                        :class="{ 'border-red-500': form.errors[`items.${index}.quantity`] }"
                                    />
                                    <p v-if="item.product_id && getProduct(item.product_id)" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        Available: {{ getProduct(item.product_id).stock }}
                                    </p>
                                    <p v-if="form.errors[`items.${index}.quantity`]" class="mt-1 text-xs text-red-600 dark:text-red-400">
                                        {{ form.errors[`items.${index}.quantity`] }}
                                    </p>
                                </div>

                                <!-- Notes -->
                                <div class="flex-1 min-w-[150px]">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Notes
                                    </label>
                                    <input
                                        v-model="item.notes"
                                        type="text"
                                        class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm"
                                        placeholder="Item notes..."
                                    />
                                </div>

                                <!-- Remove button -->
                                <div class="pt-7">
                                    <button
                                        type="button"
                                        @click="removeItem(index)"
                                        :disabled="form.items.length <= 1"
                                        class="p-2 text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition disabled:opacity-30 disabled:cursor-not-allowed"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Summary -->
                        <div v-if="hasValidItems" class="mt-6 p-4 bg-gray-100 dark:bg-dark-bg rounded-lg border border-gray-200 dark:border-dark-border">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                    Total items: {{ form.items.filter(i => i.product_id).length }} product(s)
                                </span>
                                <span class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                    Total quantity: {{ totalItems }} unit(s)
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex gap-3 justify-end">
                        <Link
                            :href="route('stock-transfers.index')"
                            class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                        >
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing || !form.from_location_id || !form.to_location_id || !hasValidItems"
                            class="px-6 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {{ form.processing ? 'Creating...' : 'Create Transfer' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
