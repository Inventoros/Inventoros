<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Eye } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    supplier: Object,
    pluginComponents: Object,
});

const form = useForm({
    name: props.supplier.name || '',
    code: props.supplier.code || '',
    contact_name: props.supplier.contact_name || '',
    email: props.supplier.email || '',
    phone: props.supplier.phone || '',
    address: props.supplier.address || '',
    city: props.supplier.city || '',
    state: props.supplier.state || '',
    zip_code: props.supplier.zip_code || '',
    country: props.supplier.country || '',
    website: props.supplier.website || '',
    payment_terms: props.supplier.payment_terms || '',
    currency: props.supplier.currency || 'USD',
    notes: props.supplier.notes || '',
    is_active: props.supplier.is_active ?? true,
});

const submit = () => {
    form.put(route('suppliers.update', props.supplier.id));
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('suppliers.edit.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('suppliers.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('suppliers.index')" class="text-text-tertiary hover:text-text-primary">{{ t('suppliers.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ supplier.name }}</span>
            </div>
        </template>

        <PageHeader :title="t('suppliers.edit.title')" :description="supplier.name">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('suppliers.show', supplier.id)">
                    <Eye :size="14" />
                    {{ t('common.view') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('suppliers.index')">
                    <ArrowLeft :size="14" />
                    {{ t('suppliers.create.backToSuppliers') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <form @submit.prevent="submit" class="mt-6 space-y-4">
            <!-- Basic Information -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('suppliers.create.basicInfo') }}</h3></div>
                <div class="p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label for="name" :class="fieldLabel">{{ t('suppliers.create.supplierName') }}</label>
                            <input id="name" v-model="form.name" type="text" :class="fieldInput" required />
                            <p v-if="form.errors.name" :class="fieldError">{{ form.errors.name }}</p>
                        </div>

                        <div>
                            <label for="code" :class="fieldLabel">{{ t('suppliers.create.supplierCode') }}</label>
                            <input id="code" v-model="form.code" type="text" :class="fieldInput" :placeholder="t('suppliers.create.codePlaceholder')" />
                            <p v-if="form.errors.code" :class="fieldError">{{ form.errors.code }}</p>
                        </div>

                        <div>
                            <label for="contact_name" :class="fieldLabel">{{ t('suppliers.create.contactPerson') }}</label>
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

                        <div>
                            <label for="website" :class="fieldLabel">{{ t('suppliers.create.website') }}</label>
                            <input id="website" v-model="form.website" type="url" :class="fieldInput" :placeholder="t('suppliers.create.websitePlaceholder')" />
                            <p v-if="form.errors.website" :class="fieldError">{{ form.errors.website }}</p>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Address -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('common.address') }}</h3></div>
                <div class="p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label for="address" :class="fieldLabel">{{ t('suppliers.create.streetAddress') }}</label>
                            <input id="address" v-model="form.address" type="text" :class="fieldInput" />
                            <p v-if="form.errors.address" :class="fieldError">{{ form.errors.address }}</p>
                        </div>

                        <div>
                            <label for="city" :class="fieldLabel">{{ t('common.city') }}</label>
                            <input id="city" v-model="form.city" type="text" :class="fieldInput" />
                            <p v-if="form.errors.city" :class="fieldError">{{ form.errors.city }}</p>
                        </div>

                        <div>
                            <label for="state" :class="fieldLabel">{{ t('common.stateProvince') }}</label>
                            <input id="state" v-model="form.state" type="text" :class="fieldInput" />
                            <p v-if="form.errors.state" :class="fieldError">{{ form.errors.state }}</p>
                        </div>

                        <div>
                            <label for="zip_code" :class="fieldLabel">{{ t('common.zipPostalCode') }}</label>
                            <input id="zip_code" v-model="form.zip_code" type="text" :class="fieldInput" />
                            <p v-if="form.errors.zip_code" :class="fieldError">{{ form.errors.zip_code }}</p>
                        </div>

                        <div>
                            <label for="country" :class="fieldLabel">{{ t('common.country') }}</label>
                            <input id="country" v-model="form.country" type="text" :class="fieldInput" />
                            <p v-if="form.errors.country" :class="fieldError">{{ form.errors.country }}</p>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Business Details -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('suppliers.create.businessDetails') }}</h3></div>
                <div class="p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label for="payment_terms" :class="fieldLabel">{{ t('suppliers.create.paymentTerms') }}</label>
                            <input id="payment_terms" v-model="form.payment_terms" type="text" :class="fieldInput" :placeholder="t('suppliers.create.paymentTermsPlaceholder')" />
                            <p v-if="form.errors.payment_terms" :class="fieldError">{{ form.errors.payment_terms }}</p>
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
                            <label class="flex items-center gap-2">
                                <input type="checkbox" v-model="form.is_active" class="rounded border-border-subtle text-brand ds-focus-ring" />
                                <span class="text-sm text-text-secondary">{{ t('common.active') }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-2 pt-2">
                <Button variant="secondary" as="Link" :href="route('suppliers.index')">{{ t('common.cancel') }}</Button>
                <Button type="submit" variant="default" :loading="form.processing" :disabled="form.processing">
                    {{ t('suppliers.edit.updateSupplier') }}
                </Button>
            </div>
        </form>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
    </AppLayout>
</template>

