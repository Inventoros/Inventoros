<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Eye } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    customer: Object,
});

const form = useForm({
    name: props.customer.name,
    code: props.customer.code || '',
    company_name: props.customer.company_name || '',
    contact_name: props.customer.contact_name || '',
    email: props.customer.email || '',
    phone: props.customer.phone || '',
    billing_address: props.customer.billing_address || '',
    billing_city: props.customer.billing_city || '',
    billing_state: props.customer.billing_state || '',
    billing_zip_code: props.customer.billing_zip_code || '',
    billing_country: props.customer.billing_country || '',
    shipping_address: props.customer.shipping_address || '',
    shipping_city: props.customer.shipping_city || '',
    shipping_state: props.customer.shipping_state || '',
    shipping_zip_code: props.customer.shipping_zip_code || '',
    shipping_country: props.customer.shipping_country || '',
    tax_id: props.customer.tax_id || '',
    payment_terms: props.customer.payment_terms || '',
    credit_limit: props.customer.credit_limit || '',
    currency: props.customer.currency || 'USD',
    notes: props.customer.notes || '',
    is_active: props.customer.is_active,
});

const addressesMatch = computed(() => {
    return form.billing_address === form.shipping_address &&
        form.billing_city === form.shipping_city &&
        form.billing_state === form.shipping_state &&
        form.billing_zip_code === form.shipping_zip_code &&
        form.billing_country === form.shipping_country;
});

const sameAsShipping = ref(addressesMatch.value);

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
    form.put(route('customers.update', props.customer.id));
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('customers.edit.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('customers.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('customers.index')" class="text-text-tertiary hover:text-text-primary">{{ t('customers.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ props.customer.name }}</span>
            </div>
        </template>

        <PageHeader :title="t('customers.edit.title')" :description="props.customer.name">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('customers.index')">
                    <ArrowLeft :size="14" />
                    {{ t('customers.create.backToCustomers') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('customers.show', props.customer.id)">
                    <Eye :size="14" />
                    {{ t('common.view') }}
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
                            <input id="name" v-model="form.name" type="text" :class="fieldInput" required />
                            <p v-if="form.errors.name" :class="fieldError">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label for="code" :class="fieldLabel">{{ t('customers.create.customerCode') }}</label>
                            <input id="code" v-model="form.code" type="text" :class="fieldInput" />
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
                        </div>
                        <div>
                            <label for="billing_state" :class="fieldLabel">{{ t('common.stateProvince') }}</label>
                            <input id="billing_state" v-model="form.billing_state" type="text" :class="fieldInput" />
                        </div>
                        <div>
                            <label for="billing_zip_code" :class="fieldLabel">{{ t('common.zipPostalCode') }}</label>
                            <input id="billing_zip_code" v-model="form.billing_zip_code" type="text" :class="fieldInput" />
                        </div>
                        <div>
                            <label for="billing_country" :class="fieldLabel">{{ t('common.country') }}</label>
                            <input id="billing_country" v-model="form.billing_country" type="text" :class="fieldInput" />
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
                        </div>
                        <div>
                            <label for="shipping_city" :class="fieldLabel">{{ t('common.city') }}</label>
                            <input id="shipping_city" v-model="form.shipping_city" type="text" :class="fieldInput" :disabled="sameAsShipping" />
                        </div>
                        <div>
                            <label for="shipping_state" :class="fieldLabel">{{ t('common.stateProvince') }}</label>
                            <input id="shipping_state" v-model="form.shipping_state" type="text" :class="fieldInput" :disabled="sameAsShipping" />
                        </div>
                        <div>
                            <label for="shipping_zip_code" :class="fieldLabel">{{ t('common.zipPostalCode') }}</label>
                            <input id="shipping_zip_code" v-model="form.shipping_zip_code" type="text" :class="fieldInput" :disabled="sameAsShipping" />
                        </div>
                        <div>
                            <label for="shipping_country" :class="fieldLabel">{{ t('common.country') }}</label>
                            <input id="shipping_country" v-model="form.shipping_country" type="text" :class="fieldInput" :disabled="sameAsShipping" />
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
                        </div>
                        <div>
                            <label for="payment_terms" :class="fieldLabel">{{ t('customers.create.paymentTerms') }}</label>
                            <input id="payment_terms" v-model="form.payment_terms" type="text" :class="fieldInput" :placeholder="t('customers.create.paymentTermsPlaceholder')" />
                        </div>
                        <div>
                            <label for="credit_limit" :class="fieldLabel">{{ t('customers.create.creditLimit') }}</label>
                            <input id="credit_limit" v-model="form.credit_limit" type="number" step="0.01" min="0" :class="fieldInput" />
                        </div>
                        <div>
                            <label for="currency" :class="fieldLabel">{{ t('common.currency') }}</label>
                            <select id="currency" v-model="form.currency" :class="fieldInput">
                                <option value="USD">{{ t('purchaseOrders.currencies.usd') }}</option>
                                <option value="EUR">{{ t('purchaseOrders.currencies.eur') }}</option>
                                <option value="GBP">{{ t('purchaseOrders.currencies.gbp') }}</option>
                                <option value="CAD">{{ t('purchaseOrders.currencies.cad') }}</option>
                                <option value="AUD">{{ t('purchaseOrders.currencies.aud') }}</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label for="notes" :class="fieldLabel">{{ t('common.notes') }}</label>
                            <textarea id="notes" v-model="form.notes" rows="3" :class="fieldArea"></textarea>
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
            <div class="flex items-center justify-end gap-2 border-t border-border-subtle pt-4">
                <Button variant="secondary" as="Link" :href="route('customers.index')">{{ t('common.cancel') }}</Button>
                <Button type="submit" variant="default" :loading="form.processing" :disabled="form.processing">
                    {{ t('customers.edit.updateCustomer') }}
                </Button>
            </div>
        </form>
    </AppLayout>
</template>

