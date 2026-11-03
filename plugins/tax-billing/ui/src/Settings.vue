<script setup>
import { t } from './i18n.js';
const { layouts: { AppLayout }, ui: { PageHeader, Card, Button }, Inertia: { Head, useForm } } = window.Inventoros;
const props = defineProps({ profile: Object, saveUrl: String });
const form = useForm(JSON.parse(JSON.stringify(props.profile)));
function setMode() {
    if (form.mode === 'india_gst') { form.country = 'IN'; form.tax_id_label = 'GSTIN'; }
}
</script>

<template>
    <Head :title="t('title')" />
    <AppLayout>
        <PageHeader :title="t('title')" :description="t('settingsDescription')" />
        <form class="tb-stack" @submit.prevent="form.post(saveUrl, { preserveScroll: true })">
            <Card>
                <p class="tb-help">{{ t('settingsHelp') }}</p>
                <div class="tb-grid">
                    <label class="tb-field">{{ t('profile') }}<select v-model="form.mode" class="tb-control" @change="setMode"><option value="generic">{{ t('customCountryProfile') }}</option><option value="india_gst">{{ t('indiaProfile') }}</option></select></label>
                    <label class="tb-field">{{ t('countryCode') }}<input v-model="form.country" class="tb-control" maxlength="2" placeholder="IN, GB, CA" @input="form.country = form.country.toUpperCase()" /></label>
                    <label class="tb-field">{{ t('supplierName') }}<input v-model="form.issuer_name" class="tb-control" autocomplete="organization" /></label>
                    <label class="tb-field">{{ t('supplierTaxRegistration') }}<input v-model="form.issuer_tax_id" class="tb-control" /></label>
                    <label class="tb-field">{{ t('supplierRegionCode') }}<input v-model="form.region_code" class="tb-control" /></label>
                    <label class="tb-field">{{ t('taxIdentifierLabel') }}<input v-model="form.tax_id_label" class="tb-control" :placeholder="t('taxIdentifierPlaceholder')" /></label>
                    <label class="tb-field tb-wide">{{ t('supplierAddress') }}<textarea v-model="form.issuer_address" class="tb-control" rows="3" autocomplete="street-address" /></label>
                    <label class="tb-field">{{ t('sizeGroupingOption') }}<input v-model="form.size_option" class="tb-control" /></label>
                </div>
                <label class="tb-check"><input v-model="form.shipping_taxable" type="checkbox" /> {{ t('shippingTaxable') }}</label>
            </Card>
            <Card v-if="form.mode === 'india_gst'">
                <h2 class="tb-title">{{ t('indiaGst') }}</h2>
                <p class="tb-help">{{ t('indiaHelp') }}</p>
                <div class="tb-grid">
                    <label class="tb-field">{{ t('gstRate') }}<input v-model="form.gst_rate" class="tb-control" type="number" min="0" max="100" step="0.0001" /></label>
                    <label class="tb-field">{{ t('localComponent') }}<select v-model="form.local_tax_label" class="tb-control"><option>SGST</option><option>UTGST</option></select></label>
                </div>
            </Card>
            <Card v-else>
                <h2 class="tb-title">{{ t('taxComponents') }}</h2>
                <p class="tb-help">{{ t('componentsHelp') }}</p>
                <div v-for="(component, index) in form.components" :key="index" class="tb-grid tb-row">
                    <label class="tb-field">{{ t('componentName') }}<input v-model="component.label" class="tb-control" /></label>
                    <label class="tb-field">{{ t('rate') }}<input v-model="component.rate" class="tb-control" type="number" min="0" max="100" step="0.0001" /></label>
                    <Button v-if="form.components.length > 1" type="button" variant="ghost" @click="form.components.splice(index, 1)">{{ t('removeComponent') }}</Button>
                </div>
                <Button v-if="form.components.length < 10" type="button" variant="secondary" @click="form.components.push({ label: '', rate: '0' })">{{ t('addComponent') }}</Button>
            </Card>
            <div v-if="Object.keys(form.errors).length" role="alert" class="tb-errors"><p v-for="(error, key) in form.errors" :key="key">{{ error }}</p></div>
            <Button type="submit" :loading="form.processing">{{ t('saveProfile') }}</Button>
        </form>
    </AppLayout>
</template>
