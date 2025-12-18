<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    supplier: Object,
    pluginComponents: Object,
});

const deleteSupplier = () => {
    if (confirm(`Are you sure you want to delete "${props.supplier.name}"?`)) {
        router.delete(route('suppliers.destroy', props.supplier.id));
    }
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: props.supplier.currency || 'USD',
    }).format(value || 0);
};
</script>

<template>
    <Head :title="supplier.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    {{ supplier.name }}
                </h2>
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('suppliers.edit', supplier.id)"
                        class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500"
                    >
                        Edit
                    </Link>
                    <Link
                        :href="route('suppliers.index')"
                        class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                    >
                        Back
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Plugin Slot: Header -->
                <PluginSlot slot="header" :components="pluginComponents?.header" />

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Main Info -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Contact Information -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Contact Information</h3>
                            </div>
                            <div class="p-6">
                                <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Contact Person</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ supplier.contact_name || '-' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Email</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                            <a v-if="supplier.email" :href="`mailto:${supplier.email}`" class="text-primary-400 hover:underline">
                                                {{ supplier.email }}
                                            </a>
                                            <span v-else>-</span>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Phone</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ supplier.phone || '-' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Website</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                            <a v-if="supplier.website" :href="supplier.website" target="_blank" class="text-primary-400 hover:underline">
                                                {{ supplier.website }}
                                            </a>
                                            <span v-else>-</span>
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        <!-- Address -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Address</h3>
                            </div>
                            <div class="p-6">
                                <p class="text-sm text-gray-900 dark:text-gray-100">
                                    <template v-if="supplier.address || supplier.city || supplier.state || supplier.zip_code || supplier.country">
                                        <span v-if="supplier.address">{{ supplier.address }}<br></span>
                                        <span v-if="supplier.city">{{ supplier.city }}, </span>
                                        <span v-if="supplier.state">{{ supplier.state }} </span>
                                        <span v-if="supplier.zip_code">{{ supplier.zip_code }}<br></span>
                                        <span v-if="supplier.country">{{ supplier.country }}</span>
                                    </template>
                                    <template v-else>
                                        <span class="text-gray-500 dark:text-gray-400">No address provided</span>
                                    </template>
                                </p>
                            </div>
                        </div>

                        <!-- Products -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Products ({{ supplier.products?.length || 0 }})</h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table v-if="supplier.products?.length > 0" class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                                    <thead class="bg-gray-50 dark:bg-dark-bg">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Product</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">SKU</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Supplier SKU</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Cost Price</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-dark-card divide-y divide-gray-200 dark:divide-dark-border">
                                        <tr v-for="product in supplier.products" :key="product.id">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                                <Link :href="route('products.show', product.id)" class="text-primary-400 hover:underline">
                                                    {{ product.name }}
                                                </Link>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ product.sku }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ product.pivot?.supplier_sku || '-' }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ formatCurrency(product.pivot?.cost_price) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div v-else class="p-6 text-center text-gray-500 dark:text-gray-400">
                                    No products linked to this supplier
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6">
                        <!-- Status Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Status</h3>
                            </div>
                            <div class="p-6">
                                <span
                                    :class="[
                                        'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium',
                                        supplier.is_active
                                            ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
                                            : 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400'
                                    ]"
                                >
                                    {{ supplier.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                        </div>

                        <!-- Business Details Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Business Details</h3>
                            </div>
                            <div class="p-6 space-y-4">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Supplier Code</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ supplier.code || '-' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Payment Terms</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ supplier.payment_terms || '-' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Currency</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ supplier.currency || 'USD' }}</dd>
                                </div>
                            </div>
                        </div>

                        <!-- Notes Card -->
                        <div v-if="supplier.notes" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Notes</h3>
                            </div>
                            <div class="p-6">
                                <p class="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-wrap">{{ supplier.notes }}</p>
                            </div>
                        </div>

                        <!-- Actions Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Actions</h3>
                            </div>
                            <div class="p-6 space-y-3">
                                <Link
                                    :href="route('suppliers.edit', supplier.id)"
                                    class="w-full inline-flex justify-center items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500"
                                >
                                    Edit Supplier
                                </Link>
                                <button
                                    @click="deleteSupplier"
                                    class="w-full inline-flex justify-center items-center px-4 py-2 bg-red-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-600"
                                >
                                    Delete Supplier
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Plugin Slot: Footer -->
                <PluginSlot slot="footer" :components="pluginComponents?.footer" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
