<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft } from '@lucide/vue';

const { t } = useI18n();

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

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('customers.create.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('customers.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('customers.index')" class="text-text-tertiary hover:text-text-primary">{{ t('customers.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('customers.create.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('customers.create.title')" description="Add a customer and their billing, shipping, and business details.">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('customers.index')">
                    <ArrowLeft :size="14" />
                    {{ t('customers.create.backToCustomers') }}
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6 space-y-4">
            <!-- Basic Information -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('customers.create.basicInfo') }}</h3></div>
                <div class="p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label for="name" :class="fieldLabel">{{ t('customers.create.customerName') }}</label>
                            <input id="name" v-model="form.name" type="text" :class="fieldInput" required autofocus />
                            <p v-if="form.errors.name" :class="fieldError">{{ form.errors.name }}</p>
                        </div>

                        <div>
                            <label for="code" :class="fieldLabel">{{ t('customers.create.customerCode') }}</label>
                            <input id="code" v-model="form.code" type="text" :class="fieldInput" :placeholder="t('customers.create.codePlaceholder')" />
                            <p v-if="form.errors.code" :class="fieldError">{{ form.errors.code }}</p>
                        </div>

                        <div>
                            <label for="company_name" :class="fieldLabel">{{ t('customers.create.companyName') }}</label>
                            <input id="company_name" v-model="form.company_name" type="text" :class="fieldInput" />
                            <p v-if="form.errors.company_name" :class="fieldError">{{ form.errors.company_name }}</p>
                        </div>

                        <div>
                            <label for="contact_name" :class="fieldLabel">{{ t('customers.create.contactPerson') }}</label>
                            <input id="contact_name" v-model="form.contact_name" type="text" :class="fieldInput" />
                            <p v-if="form.errors.contact_name" :class="fieldError">{{ form.errors.contact_name }}</p>
                        </div>

                        <div>
                            <label for="email" :class="fieldLabel">{{ t('common.email') }}</label>
                            <input id="email" v-model="form.email" type="email" :class="fieldInput" />
                            <p v-if="form.errors.email" :class="fieldError">{{ form.errors.email }}</p>
                        </div>

                        <div>
                            <label for="phone" :class="fieldLabel">{{ t('common.phone') }}</label>
                            <input id="phone" v-model="form.phone" type="text" :class="fieldInput" />
                            <p v-if="form.errors.phone" :class="fieldError">{{ form.errors.phone }}</p>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Billing Address -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('customers.create.billingAddress') }}</h3></div>
                <div class="p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label for="billing_address" :class="fieldLabel">{{ t('customers.create.streetAddress') }}</label>
                            <input id="billing_address" v-model="form.billing_address" type="text" :class="fieldInput" />
                            <p v-if="form.errors.billing_address" :class="fieldError">{{ form.errors.billing_address }}</p>
                        </div>

                        <div>
                            <label for="billing_city" :class="fieldLabel">{{ t('common.city') }}</label>
                            <input id="billing_city" v-model="form.billing_city" type="text" :class="fieldInput" />
                            <p v-if="form.errors.billing_city" :class="fieldError">{{ form.errors.billing_city }}</p>
                        </div>

                        <div>
                            <label for="billing_state" :class="fieldLabel">{{ t('common.stateProvince') }}</label>
                            <input id="billing_state" v-model="form.billing_state" type="text" :class="fieldInput" />
                            <p v-if="form.errors.billing_state" :class="fieldError">{{ form.errors.billing_state }}</p>
                        </div>

                        <div>
                            <label for="billing_zip_code" :class="fieldLabel">{{ t('common.zipPostalCode') }}</label>
                            <input id="billing_zip_code" v-model="form.billing_zip_code" type="text" :class="fieldInput" />
                            <p v-if="form.errors.billing_zip_code" :class="fieldError">{{ form.errors.billing_zip_code }}</p>
                        </div>

                        <div>
                            <label for="billing_country" :class="fieldLabel">{{ t('common.country') }}</label>
                            <input id="billing_country" v-model="form.billing_country" type="text" :class="fieldInput" />
                            <p v-if="form.errors.billing_country" :class="fieldError">{{ form.errors.billing_country }}</p>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Shipping Address -->
            <Card :padded="false">
                <div class="flex items-center justify-between px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('customers.create.shippingAddress') }}</h3>
                    <label class="flex items-center">
                        <input type="checkbox" v-model="sameAsShipping" class="rounded border-border-subtle bg-surface-canvas text-brand ds-focus-ring" />
                        <span class="ml-2 text-sm text-text-secondary">{{ t('customers.create.sameAsBilling') }}</span>
                    </label>
                </div>
                <div class="p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label for="shipping_address" :class="fieldLabel">{{ t('customers.create.streetAddress') }}</label>
                            <input id="shipping_address" v-model="form.shipping_address" type="text" :class="fieldInput" :disabled="sameAsShipping" />
                            <p v-if="form.errors.shipping_address" :class="fieldError">{{ form.errors.shipping_address }}</p>
                        </div>

                        <div>
                            <label for="shipping_city" :class="fieldLabel">{{ t('common.city') }}</label>
                            <input id="shipping_city" v-model="form.shipping_city" type="text" :class="fieldInput" :disabled="sameAsShipping" />
                            <p v-if="form.errors.shipping_city" :class="fieldError">{{ form.errors.shipping_city }}</p>
                        </div>

                        <div>
                            <label for="shipping_state" :class="fieldLabel">{{ t('common.stateProvince') }}</label>
                            <input id="shipping_state" v-model="form.shipping_state" type="text" :class="fieldInput" :disabled="sameAsShipping" />
                            <p v-if="form.errors.shipping_state" :class="fieldError">{{ form.errors.shipping_state }}</p>
                        </div>

                        <div>
                            <label for="shipping_zip_code" :class="fieldLabel">{{ t('common.zipPostalCode') }}</label>
                            <input id="shipping_zip_code" v-model="form.shipping_zip_code" type="text" :class="fieldInput" :disabled="sameAsShipping" />
                            <p v-if="form.errors.shipping_zip_code" :class="fieldError">{{ form.errors.shipping_zip_code }}</p>
                        </div>

                        <div>
                            <label for="shipping_country" :class="fieldLabel">{{ t('common.country') }}</label>
                            <input id="shipping_country" v-model="form.shipping_country" type="text" :class="fieldInput" :disabled="sameAsShipping" />
                            <p v-if="form.errors.shipping_country" :class="fieldError">{{ form.errors.shipping_country }}</p>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Business Details -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('customers.show.businessDetails') }}</h3></div>
                <div class="p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label for="tax_id" :class="fieldLabel">{{ t('customers.create.taxIdVat') }}</label>
                            <input id="tax_id" v-model="form.tax_id" type="text" :class="fieldInput" />
                            <p v-if="form.errors.tax_id" :class="fieldError">{{ form.errors.tax_id }}</p>
                        </div>

                        <div>
                            <label for="payment_terms" :class="fieldLabel">{{ t('customers.create.paymentTerms') }}</label>
                            <input id="payment_terms" v-model="form.payment_terms" type="text" :class="fieldInput" :placeholder="t('customers.create.paymentTermsPlaceholder')" />
                            <p v-if="form.errors.payment_terms" :class="fieldError">{{ form.errors.payment_terms }}</p>
                        </div>

                        <div>
                            <label for="credit_limit" :class="fieldLabel">{{ t('customers.create.creditLimit') }}</label>
                            <input id="credit_limit" v-model="form.credit_limit" type="number" step="0.01" min="0" :class="fieldInput" />
                            <p v-if="form.errors.credit_limit" :class="fieldError">{{ form.errors.credit_limit }}</p>
                        </div>

                        <div>
                            <label for="currency" :class="fieldLabel">{{ t('common.currency') }}</label>
                            <select id="currency" v-model="form.currency" :class="fieldInput">
                                <option value="AUD">{{ t('purchaseOrders.currencies.aud') }}</option>
                                <option value="BDT">{{ t('purchaseOrders.currencies.bdt') }}</option>
                                <option value="CAD">{{ t('purchaseOrders.currencies.cad') }}</option>
                                <option value="EUR">{{ t('purchaseOrders.currencies.eur') }}</option>
                                <option value="GBP">{{ t('purchaseOrders.currencies.gbp') }}</option>
                                <option value="USD">{{ t('purchaseOrders.currencies.usd') }}</option>
                            </select>
                            <p v-if="form.errors.currency" :class="fieldError">{{ form.errors.currency }}</p>
                        </div>

                        <div class="md:col-span-2">
                            <label for="notes" :class="fieldLabel">{{ t('common.notes') }}</label>
                            <textarea id="notes" v-model="form.notes" rows="3" :class="fieldArea"></textarea>
                            <p v-if="form.errors.notes" :class="fieldError">{{ form.errors.notes }}</p>
                        </div>

                        <div>
                            <label class="flex items-center">
                                <input type="checkbox" v-model="form.is_active" class="rounded border-border-subtle bg-surface-canvas text-brand ds-focus-ring" />
                                <span class="ml-2 text-sm text-text-secondary">{{ t('common.active') }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" as="Link" :href="route('customers.index')">{{ t('common.cancel') }}</Button>
                <Button type="submit" variant="default" :loading="form.processing" :disabled="form.processing">
                    {{ t('customers.create.createCustomer') }}
                </Button>
            </div>
        </form>
    </AppLayout>
</template>

