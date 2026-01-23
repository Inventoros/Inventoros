<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    customer: Object,
});

const deleteCustomer = () => {
    if (confirm(`Are you sure you want to delete "${props.customer.name}"?`)) {
        router.delete(route('customers.destroy', props.customer.id));
    }
};
</script>

<template>
    <Head :title="customer.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    {{ customer.name }}
                </h2>
                <div class="flex items-center gap-3">
                    <Link
                        :href="route('customers.edit', customer.id)"
                        class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500"
                    >
                        Edit
                    </Link>
                    <Link
                        :href="route('customers.index')"
                        class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                    >
                        Back to Customers
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Status Badge -->
                <div class="flex items-center gap-3">
                    <span
                        :class="[
                            'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium',
                            customer.is_active
                                ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
                                : 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400'
                        ]"
                    >
                        {{ customer.is_active ? 'Active' : 'Inactive' }}
                    </span>
                    <span v-if="customer.code" class="text-gray-500 dark:text-gray-400">
                        Code: {{ customer.code }}
                    </span>
                </div>

                <!-- Basic Information -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Basic Information</h3>
                    </div>
                    <div class="p-6">
                        <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                            <div>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Customer Name</dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ customer.name }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Company Name</dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ customer.company_name || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Contact Person</dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ customer.contact_name || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Email</dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                    <a v-if="customer.email" :href="`mailto:${customer.email}`" class="text-primary-400 hover:underline">
                                        {{ customer.email }}
                                    </a>
                                    <span v-else>-</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Phone</dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ customer.phone || '-' }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- Addresses -->
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <!-- Billing Address -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Billing Address</h3>
                        </div>
                        <div class="p-6">
                            <address class="not-italic text-sm text-gray-900 dark:text-gray-100">
                                <template v-if="customer.billing_address || customer.billing_city">
                                    <div v-if="customer.billing_address">{{ customer.billing_address }}</div>
                                    <div v-if="customer.billing_city || customer.billing_state || customer.billing_zip_code">
                                        {{ [customer.billing_city, customer.billing_state, customer.billing_zip_code].filter(Boolean).join(', ') }}
                                    </div>
                                    <div v-if="customer.billing_country">{{ customer.billing_country }}</div>
                                </template>
                                <span v-else class="text-gray-500 dark:text-gray-400">No billing address</span>
                            </address>
                        </div>
                    </div>

                    <!-- Shipping Address -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Shipping Address</h3>
                        </div>
                        <div class="p-6">
                            <address class="not-italic text-sm text-gray-900 dark:text-gray-100">
                                <template v-if="customer.shipping_address || customer.shipping_city">
                                    <div v-if="customer.shipping_address">{{ customer.shipping_address }}</div>
                                    <div v-if="customer.shipping_city || customer.shipping_state || customer.shipping_zip_code">
                                        {{ [customer.shipping_city, customer.shipping_state, customer.shipping_zip_code].filter(Boolean).join(', ') }}
                                    </div>
                                    <div v-if="customer.shipping_country">{{ customer.shipping_country }}</div>
                                </template>
                                <span v-else class="text-gray-500 dark:text-gray-400">No shipping address</span>
                            </address>
                        </div>
                    </div>
                </div>

                <!-- Business Details -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Business Details</h3>
                    </div>
                    <div class="p-6">
                        <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                            <div>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Tax ID / VAT Number</dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ customer.tax_id || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Payment Terms</dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ customer.payment_terms || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Credit Limit</dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                    {{ customer.credit_limit ? `${customer.currency} ${Number(customer.credit_limit).toLocaleString()}` : '-' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Currency</dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ customer.currency }}</dd>
                            </div>
                            <div class="sm:col-span-2" v-if="customer.notes">
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Notes</dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 whitespace-pre-wrap">{{ customer.notes }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- Orders -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Recent Orders</h3>
                    </div>
                    <div class="p-6">
                        <div v-if="customer.orders && customer.orders.length > 0" class="space-y-3">
                            <div v-for="order in customer.orders.slice(0, 5)" :key="order.id" class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-dark-border last:border-0">
                                <div>
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ order.order_number }}</span>
                                    <span class="text-sm text-gray-500 dark:text-gray-400 ml-2">{{ new Date(order.created_at).toLocaleDateString() }}</span>
                                </div>
                                <Link :href="route('orders.show', order.id)" class="text-primary-400 hover:underline text-sm">
                                    View
                                </Link>
                            </div>
                        </div>
                        <p v-else class="text-gray-500 dark:text-gray-400 text-sm">No orders yet</p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end gap-4">
                    <button
                        @click="deleteCustomer"
                        class="inline-flex items-center px-4 py-2 bg-red-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-600"
                    >
                        Delete Customer
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
