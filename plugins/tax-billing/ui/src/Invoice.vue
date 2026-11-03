<script setup>
import { t, formatDate, formatMoney, formatNumber } from './i18n.js';
const { layouts: { AppLayout }, ui: { PageHeader, Card, Button }, Inertia: { Head, useForm } } = window.Inventoros;
const props = defineProps({ order: Object, items: Array, profile: Object, snapshot: Object, canIssue: Boolean, regionCode: String, supplyCountry: String, issueUrl: String, downloadUrl: String });
const form = useForm({ confirmed: false, region_code: props.regionCode || '', supply_country: props.supplyCountry || '', items: Object.fromEntries(props.items.map(item => [item.id, { hsn_code: '', unit: 'NOS' }])) });
</script>

<template>
    <Head :title="t('invoiceTitle', { number: order.order_number })" />
    <AppLayout>
        <PageHeader :title="t('invoiceTitle', { number: order.order_number })" :description="t('invoiceDescription')" />
        <Card v-if="snapshot" class="tb-stack">
            <h2 class="tb-title">{{ snapshot.number }}</h2>
            <p>{{ t('issuedSummary', { date: formatDate(snapshot.issued_date), total: formatMoney(snapshot.total, snapshot.currency) }) }}</p>
            <p class="tb-help">{{ t('retainedDetails') }}</p>
            <p v-for="component in snapshot.components" :key="component.label">{{ component.label }}: {{ formatMoney(component.amount, snapshot.currency) }}</p>
            <Button as="a" :href="downloadUrl">{{ t('downloadPdf') }}</Button>
        </Card>
        <form v-else-if="canIssue" class="tb-stack" @submit.prevent="form.post(issueUrl, { preserveScroll: true })">
            <Card>
                <p class="tb-help">{{ t('orderTaxSummary', { total: formatMoney(order.total, order.currency), tax: formatMoney(order.tax, order.currency) }) }}</p>
                <div class="tb-grid">
                    <label class="tb-field">{{ t('supplyCountryCode') }}<input v-model="form.supply_country" class="tb-control" maxlength="2" @input="form.supply_country = form.supply_country.toUpperCase()" /></label>
                    <label class="tb-field">{{ t('supplyRegionCode') }}<input v-model="form.region_code" class="tb-control" /></label>
                </div>
                <p v-if="profile.mode === 'india_gst'" class="tb-help">{{ t('domesticHelp') }}</p>
            </Card>
            <Card>
                <h2 class="tb-title">{{ t('lineClassification') }}</h2>
                <div v-for="item in items" :key="item.id" class="tb-row">
                    <p><strong>{{ item.product_name }}</strong> · {{ item.sku }} · {{ formatNumber(item.quantity) }} × {{ formatMoney(item.unit_price, order.currency) }}</p>
                    <div class="tb-grid">
                        <label class="tb-field">{{ profile.mode === 'india_gst' ? t('hsnRequired') : t('hsnOptional') }}<input v-model="form.items[item.id].hsn_code" class="tb-control" :aria-label="t('hsnFor', { sku: item.sku })" /></label>
                        <label class="tb-field">{{ t('unitCode') }}<input v-model="form.items[item.id].unit" class="tb-control" :aria-label="t('unitFor', { sku: item.sku })" /></label>
                    </div>
                </div>
            </Card>
            <div v-if="Object.keys(form.errors).length" role="alert" class="tb-errors"><p v-for="(error, key) in form.errors" :key="key">{{ error }}</p></div>
            <label class="tb-check"><input v-model="form.confirmed" type="checkbox" /> {{ t('reviewConfirmation') }}</label>
            <Button type="submit" :loading="form.processing" :disabled="!form.confirmed">{{ t('issueInvoice') }}</Button>
        </form>
        <Card v-else class="tb-stack"><p>{{ t('authorizedUserRequired') }}</p></Card>
    </AppLayout>
</template>
