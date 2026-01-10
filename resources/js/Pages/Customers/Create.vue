<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const form = useForm({
    name: '',
    code: '',
    company_name: '',
    contact_name: '',
    email: '',
    phone: '',
    billing_address: '',
    billing_city: '',
    billing_state: '',
    billing_zip_code: '',
    billing_country: '',
    shipping_address: '',
    shipping_city: '',
    shipping_state: '',
    shipping_zip_code: '',
    shipping_country: '',
    tax_id: '',
    payment_terms: '',
    credit_limit: '',
    currency: 'USD',
    notes: '',
    is_active: true,
});

const sameAsShipping = ref(false);

watch(sameAsShipping, (value) => {
    if (value) {
        form.shipping_address = form.billing_address;
        form.shipping_city = form.billing_city;
        form.shipping_state = form.billing_state;
        form.shipping_zip_code = form.billing_zip_code;
        form.shipping_country = form.billing_country;
    }
});

const submit = () => {
    form.post(route('customers.store'));
};
</script>

<template>
    <Head title="Add Customer" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    Add Customer
                </h2>
                <Link
                    :href="route('customers.index')"
                    class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                >
                    Back to Customers
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <form @submit.prevent="submit" class="p-6 space-y-6">
                        <!-- Basic Information -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Basic Information</h3>
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel for="name" value="Customer Name *" />
                                    <TextInput
                                        id="name"
                                        v-model="form.name"
                                        type="text"
                                        class="mt-1 block w-full"
                                        required
                                        autofocus
                                    />
                                    <InputError :message="form.errors.name" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="code" value="Customer Code" />
                                    <TextInput
                                        id="code"
                                        v-model="form.code"
                                        type="text"
                                        class="mt-1 block w-full"
                                        placeholder="e.g., CUST-001"
                                    />
                                    <InputError :message="form.errors.code" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="company_name" value="Company Name" />
                                    <TextInput
                                        id="company_name"
                                        v-model="form.company_name"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.company_name" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="contact_name" value="Contact Person" />
                                    <TextInput
                                        id="contact_name"
                                        v-model="form.contact_name"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.contact_name" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="email" value="Email" />
                                    <TextInput
                                        id="email"
                                        v-model="form.email"
                                        type="email"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.email" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="phone" value="Phone" />
                                    <TextInput
                                        id="phone"
                                        v-model="form.phone"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.phone" class="mt-2" />
                                </div>
                            </div>
                        </div>

                        <!-- Billing Address -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Billing Address</h3>
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div class="md:col-span-2">
                                    <InputLabel for="billing_address" value="Street Address" />
                                    <TextInput
                                        id="billing_address"
                                        v-model="form.billing_address"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.billing_address" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="billing_city" value="City" />
                                    <TextInput
                                        id="billing_city"
                                        v-model="form.billing_city"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.billing_city" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="billing_state" value="State/Province" />
                                    <TextInput
                                        id="billing_state"
                                        v-model="form.billing_state"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.billing_state" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="billing_zip_code" value="ZIP/Postal Code" />
                                    <TextInput
                                        id="billing_zip_code"
                                        v-model="form.billing_zip_code"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.billing_zip_code" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="billing_country" value="Country" />
                                    <TextInput
                                        id="billing_country"
                                        v-model="form.billing_country"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.billing_country" class="mt-2" />
                                </div>
                            </div>
                        </div>

                        <!-- Shipping Address -->
                        <div>
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Shipping Address</h3>
                                <label class="flex items-center">
                                    <input
                                        type="checkbox"
                                        v-model="sameAsShipping"
                                        class="rounded border-gray-300 dark:border-dark-border text-primary-400 shadow-sm focus:ring-primary-400"
                                    />
                                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-300">Same as billing</span>
                                </label>
                            </div>
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div class="md:col-span-2">
                                    <InputLabel for="shipping_address" value="Street Address" />
                                    <TextInput
                                        id="shipping_address"
                                        v-model="form.shipping_address"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="sameAsShipping"
                                    />
                                    <InputError :message="form.errors.shipping_address" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="shipping_city" value="City" />
                                    <TextInput
                                        id="shipping_city"
                                        v-model="form.shipping_city"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="sameAsShipping"
                                    />
                                    <InputError :message="form.errors.shipping_city" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="shipping_state" value="State/Province" />
                                    <TextInput
                                        id="shipping_state"
                                        v-model="form.shipping_state"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="sameAsShipping"
                                    />
                                    <InputError :message="form.errors.shipping_state" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="shipping_zip_code" value="ZIP/Postal Code" />
                                    <TextInput
                                        id="shipping_zip_code"
                                        v-model="form.shipping_zip_code"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="sameAsShipping"
                                    />
                                    <InputError :message="form.errors.shipping_zip_code" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="shipping_country" value="Country" />
                                    <TextInput
                                        id="shipping_country"
                                        v-model="form.shipping_country"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="sameAsShipping"
                                    />
                                    <InputError :message="form.errors.shipping_country" class="mt-2" />
                                </div>
                            </div>
                        </div>

                        <!-- Business Details -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Business Details</h3>
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel for="tax_id" value="Tax ID / VAT Number" />
                                    <TextInput
                                        id="tax_id"
                                        v-model="form.tax_id"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.tax_id" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="payment_terms" value="Payment Terms" />
                                    <TextInput
                                        id="payment_terms"
                                        v-model="form.payment_terms"
                                        type="text"
                                        class="mt-1 block w-full"
                                        placeholder="e.g., Net 30"
                                    />
                                    <InputError :message="form.errors.payment_terms" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="credit_limit" value="Credit Limit" />
                                    <TextInput
                                        id="credit_limit"
                                        v-model="form.credit_limit"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.credit_limit" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="currency" value="Currency" />
                                    <select
                                        id="currency"
                                        v-model="form.currency"
                                        class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    >
                                        <option value="USD">USD - US Dollar</option>
                                        <option value="EUR">EUR - Euro</option>
                                        <option value="GBP">GBP - British Pound</option>
                                        <option value="CAD">CAD - Canadian Dollar</option>
                                        <option value="AUD">AUD - Australian Dollar</option>
                                    </select>
                                    <InputError :message="form.errors.currency" class="mt-2" />
                                </div>

                                <div class="md:col-span-2">
                                    <InputLabel for="notes" value="Notes" />
                                    <textarea
                                        id="notes"
                                        v-model="form.notes"
                                        rows="3"
                                        class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    ></textarea>
                                    <InputError :message="form.errors.notes" class="mt-2" />
                                </div>

                                <div>
                                    <label class="flex items-center">
                                        <input
                                            type="checkbox"
                                            v-model="form.is_active"
                                            class="rounded border-gray-300 dark:border-dark-border text-primary-400 shadow-sm focus:ring-primary-400"
                                        />
                                        <span class="ml-2 text-sm text-gray-600 dark:text-gray-300">Active</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center justify-end gap-4 pt-6 border-t border-gray-200 dark:border-dark-border">
                            <Link
                                :href="route('customers.index')"
                                class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                            >
                                Cancel
                            </Link>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 focus:bg-primary-500 active:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-400 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50"
                            >
                                Create Customer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
