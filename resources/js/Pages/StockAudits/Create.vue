<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    locations: Array,
    products: Array,
    auditTypes: Object,
});

const form = useForm({
    name: '',
    description: '',
    audit_type: 'cycle',
    warehouse_location_id: '',
    notes: '',
    product_ids: [],
});

const productSearch = ref('');
const selectAllProducts = ref(false);

const filteredProducts = computed(() => {
    if (!productSearch.value) return props.products;
    const search = productSearch.value.toLowerCase();
    return props.products.filter(
        p => p.name.toLowerCase().includes(search) || p.sku.toLowerCase().includes(search)
    );
});

const toggleSelectAll = () => {
    if (selectAllProducts.value) {
        form.product_ids = filteredProducts.value.map(p => p.id);
    } else {
        form.product_ids = [];
    }
};

const toggleProduct = (productId) => {
    const index = form.product_ids.indexOf(productId);
    if (index > -1) {
        form.product_ids.splice(index, 1);
    } else {
        form.product_ids.push(productId);
    }
};

const submit = () => {
    form.post(route('stock-audits.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Create Stock Audit" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Create Stock Audit</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Set up a new stock audit or cycle count</p>
                </div>
                <Link
                    :href="route('stock-audits.index')"
                    class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                >
                    Back to List
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <form @submit.prevent="submit" class="space-y-6">
                    <!-- Audit Details -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-6">Audit Details</h3>

                        <div class="space-y-6">
                            <!-- Name -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Audit Name <span class="text-red-500">*</span>
                                </label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    required
                                    maxlength="255"
                                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    :class="{ 'border-red-500': form.errors.name }"
                                    placeholder="e.g., Q1 2026 Warehouse Cycle Count"
                                />
                                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                    {{ form.errors.name }}
                                </p>
                            </div>

                            <!-- Description -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Description
                                </label>
                                <textarea
                                    v-model="form.description"
                                    rows="3"
                                    maxlength="1000"
                                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    :class="{ 'border-red-500': form.errors.description }"
                                    placeholder="Describe the purpose of this audit..."
                                ></textarea>
                                <p v-if="form.errors.description" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                    {{ form.errors.description }}
                                </p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Audit Type -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Audit Type <span class="text-red-500">*</span>
                                    </label>
                                    <select
                                        v-model="form.audit_type"
                                        required
                                        class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                        :class="{ 'border-red-500': form.errors.audit_type }"
                                    >
                                        <option v-for="(label, value) in auditTypes" :key="value" :value="value">{{ label }}</option>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        <span v-if="form.audit_type === 'full'">Count all products in the warehouse</span>
                                        <span v-else-if="form.audit_type === 'cycle'">Count a subset of products on a rotating schedule</span>
                                        <span v-else-if="form.audit_type === 'spot'">Quick check of specific products</span>
                                    </p>
                                    <p v-if="form.errors.audit_type" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.audit_type }}
                                    </p>
                                </div>

                                <!-- Location -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Warehouse Location
                                    </label>
                                    <select
                                        v-model="form.warehouse_location_id"
                                        class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                        :class="{ 'border-red-500': form.errors.warehouse_location_id }"
                                    >
                                        <option value="">All Locations</option>
                                        <option v-for="location in locations" :key="location.id" :value="location.id">
                                            {{ location.name }}
                                            <span v-if="location.code">({{ location.code }})</span>
                                        </option>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        Leave blank to include products from all locations
                                    </p>
                                    <p v-if="form.errors.warehouse_location_id" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{ form.errors.warehouse_location_id }}
                                    </p>
                                </div>
                            </div>

                            <!-- Notes -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Notes
                                </label>
                                <textarea
                                    v-model="form.notes"
                                    rows="2"
                                    maxlength="2000"
                                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    :class="{ 'border-red-500': form.errors.notes }"
                                    placeholder="Additional notes or instructions for audit staff..."
                                ></textarea>
                                <p v-if="form.errors.notes" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                    {{ form.errors.notes }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Product Selection (for cycle/spot audits) -->
                    <div v-if="form.audit_type !== 'full'" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Select Products</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                            Choose which products to include in this audit. Leave empty to include all products{{ form.warehouse_location_id ? ' at the selected location' : '' }}.
                        </p>

                        <!-- Product Search -->
                        <div class="mb-4">
                            <input
                                v-model="productSearch"
                                type="text"
                                placeholder="Search products by name or SKU..."
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            />
                        </div>

                        <!-- Select All -->
                        <div class="mb-3 flex items-center gap-2">
                            <input
                                type="checkbox"
                                v-model="selectAllProducts"
                                @change="toggleSelectAll"
                                class="rounded border-gray-300 dark:border-dark-border text-primary-400 shadow-sm focus:ring-primary-400"
                            />
                            <span class="text-sm text-gray-600 dark:text-gray-300">
                                Select all ({{ filteredProducts.length }} products)
                            </span>
                            <span v-if="form.product_ids.length > 0" class="text-xs text-primary-400">
                                {{ form.product_ids.length }} selected
                            </span>
                        </div>

                        <!-- Product List -->
                        <div class="max-h-64 overflow-y-auto border border-gray-200 dark:border-dark-border rounded-lg divide-y divide-gray-200 dark:divide-dark-border">
                            <label
                                v-for="product in filteredProducts"
                                :key="product.id"
                                class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-dark-bg/50 cursor-pointer"
                            >
                                <input
                                    type="checkbox"
                                    :checked="form.product_ids.includes(product.id)"
                                    @change="toggleProduct(product.id)"
                                    class="rounded border-gray-300 dark:border-dark-border text-primary-400 shadow-sm focus:ring-primary-400"
                                />
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ product.name }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">SKU: {{ product.sku }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ product.stock }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">in stock</p>
                                </div>
                            </label>
                            <div v-if="filteredProducts.length === 0" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                No products found
                            </div>
                        </div>
                    </div>

                    <!-- Info Box for Full Audit -->
                    <div v-if="form.audit_type === 'full'" class="p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                        <div class="flex gap-3">
                            <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-blue-900 dark:text-blue-100">Full Audit</p>
                                <p class="text-xs text-blue-700 dark:text-blue-300 mt-1">
                                    All active products{{ form.warehouse_location_id ? ' at the selected location' : '' }} will be automatically included in this audit.
                                    You can review items after creation.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex gap-3 justify-end">
                        <Link
                            :href="route('stock-audits.index')"
                            class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                        >
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing || !form.name"
                            class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {{ form.processing ? 'Creating...' : 'Create Audit' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
