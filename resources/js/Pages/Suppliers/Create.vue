<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    pluginComponents: Object,
});

const form = useForm({
    name: '',
    code: '',
    contact_name: '',
    email: '',
    phone: '',
    address: '',
    city: '',
    state: '',
    zip_code: '',
    country: '',
    website: '',
    payment_terms: '',
    currency: 'USD',
    notes: '',
    is_active: true,
});

const submit = () => {
    form.post(route('suppliers.store'));
};
</script>

<template>
    <Head :title="t('suppliers.create.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    {{ t('suppliers.create.title') }}
                </h2>
                <Link
                    :href="route('suppliers.index')"
                    class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                >
                    {{ t('suppliers.create.backToSuppliers') }}
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <!-- Plugin Slot: Header -->
                <PluginSlot slot="header" :components="pluginComponents?.header" />

                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <form @submit.prevent="submit" class="p-6 space-y-6">
                        <!-- Basic Information -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{{ t('suppliers.create.basicInfo') }}</h3>
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel for="name" :value="t('suppliers.create.supplierName')" />
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
                                    <InputLabel for="code" :value="t('suppliers.create.supplierCode')" />
                                    <TextInput
                                        id="code"
                                        v-model="form.code"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :placeholder="t('suppliers.create.codePlaceholder')"
                                    />
                                    <InputError :message="form.errors.code" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="contact_name" :value="t('suppliers.create.contactPerson')" />
                                    <TextInput
                                        id="contact_name"
                                        v-model="form.contact_name"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.contact_name" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="email" :value="t('common.email')" />
                                    <TextInput
                                        id="email"
                                        v-model="form.email"
                                        type="email"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.email" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="phone" :value="t('common.phone')" />
                                    <TextInput
                                        id="phone"
                                        v-model="form.phone"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.phone" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="website" :value="t('suppliers.create.website')" />
                                    <TextInput
                                        id="website"
                                        v-model="form.website"
                                        type="url"
                                        class="mt-1 block w-full"
                                        :placeholder="t('suppliers.create.websitePlaceholder')"
                                    />
                                    <InputError :message="form.errors.website" class="mt-2" />
                                </div>
                            </div>
                        </div>

                        <!-- Address -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{{ t('common.address') }}</h3>
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div class="md:col-span-2">
                                    <InputLabel for="address" :value="t('suppliers.create.streetAddress')" />
                                    <TextInput
                                        id="address"
                                        v-model="form.address"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.address" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="city" :value="t('common.city')" />
                                    <TextInput
                                        id="city"
                                        v-model="form.city"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.city" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="state" :value="t('common.stateProvince')" />
                                    <TextInput
                                        id="state"
                                        v-model="form.state"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.state" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="zip_code" :value="t('common.zipPostalCode')" />
                                    <TextInput
                                        id="zip_code"
                                        v-model="form.zip_code"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.zip_code" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="country" :value="t('common.country')" />
                                    <TextInput
                                        id="country"
                                        v-model="form.country"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.country" class="mt-2" />
                                </div>
                            </div>
                        </div>

                        <!-- Business Details -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{{ t('suppliers.create.businessDetails') }}</h3>
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel for="payment_terms" :value="t('suppliers.create.paymentTerms')" />
                                    <TextInput
                                        id="payment_terms"
                                        v-model="form.payment_terms"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :placeholder="t('suppliers.create.paymentTermsPlaceholder')"
                                    />
                                    <InputError :message="form.errors.payment_terms" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="currency" :value="t('common.currency')" />
                                    <select
                                        id="currency"
                                        v-model="form.currency"
                                        class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    >
                                        <option value="USD">{{ t('purchaseOrders.currencies.usd') }}</option>
                                        <option value="EUR">{{ t('purchaseOrders.currencies.eur') }}</option>
                                        <option value="GBP">{{ t('purchaseOrders.currencies.gbp') }}</option>
                                        <option value="CAD">{{ t('purchaseOrders.currencies.cad') }}</option>
                                        <option value="AUD">{{ t('purchaseOrders.currencies.aud') }}</option>
                                    </select>
                                    <InputError :message="form.errors.currency" class="mt-2" />
                                </div>

                                <div class="md:col-span-2">
                                    <InputLabel for="notes" :value="t('common.notes')" />
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
                                        <span class="ml-2 text-sm text-gray-600 dark:text-gray-300">{{ t('common.active') }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center justify-end gap-4 pt-6 border-t border-gray-200 dark:border-dark-border">
                            <Link
                                :href="route('suppliers.index')"
                                class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                            >
                                {{ t('common.cancel') }}
                            </Link>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 focus:bg-primary-500 active:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-400 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50"
                            >
                                {{ t('suppliers.create.createSupplier') }}
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Plugin Slot: Footer -->
                <PluginSlot slot="footer" :components="pluginComponents?.footer" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
