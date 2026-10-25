<script setup>
const { layouts: { AppLayout }, ui: { PageHeader, Card, Button }, Inertia: { Head, useForm } } = window.Inventoros;
const props = defineProps({ order: Object, items: Array, profile: Object, snapshot: Object, canIssue: Boolean, regionCode: String, supplyCountry: String, issueUrl: String, downloadUrl: String });
const form = useForm({ confirmed: false, region_code: props.regionCode || '', supply_country: props.supplyCountry || '', items: Object.fromEntries(props.items.map(item => [item.id, { hsn_code: '', unit: 'NOS' }])) });
</script>

<template>
    <Head :title="`Tax invoice: ${order.order_number}`" />
    <AppLayout>
        <PageHeader :title="`Tax invoice: ${order.order_number}`" description="Review tax details before retaining an issued invoice." />
        <Card v-if="snapshot" class="tb-stack">
            <h2 class="tb-title">{{ snapshot.number }}</h2>
            <p>Issued {{ snapshot.issued_date }} · {{ snapshot.currency }} {{ snapshot.total }}</p>
            <p class="tb-help">These details are retained from issuance. Later edits to the customer, order or tax profile do not rewrite this invoice.</p>
            <p v-for="component in snapshot.components" :key="component.label">{{ component.label }}: {{ component.amount }}</p>
            <Button as="a" :href="downloadUrl">Download invoice PDF</Button>
        </Card>
        <form v-else-if="canIssue" class="tb-stack" @submit.prevent="form.post(issueUrl, { preserveScroll: true })">
            <Card>
                <p class="tb-help">Order total: {{ order.currency }} {{ order.total }}. Recorded tax: {{ order.tax }}. Invoice tax components must reconcile with this amount. Issuing does not change stock or payments.</p>
                <div class="tb-grid">
                    <label class="tb-field">Place-of-supply country code<input v-model="form.supply_country" class="tb-control" maxlength="2" @input="form.supply_country = form.supply_country.toUpperCase()" /></label>
                    <label class="tb-field">Place-of-supply region/state code<input v-model="form.region_code" class="tb-control" /></label>
                </div>
                <p v-if="profile.mode === 'india_gst'" class="tb-help">This profile covers domestic India supplies. Check the place of supply, GSTIN, HSN/SAC and unit codes before issuing.</p>
            </Card>
            <Card>
                <h2 class="tb-title">Line classification</h2>
                <div v-for="item in items" :key="item.id" class="tb-row">
                    <p><strong>{{ item.product_name }}</strong> · {{ item.sku }} · {{ item.quantity }} × {{ item.unit_price }}</p>
                    <div class="tb-grid">
                        <label class="tb-field">HSN/SAC{{ profile.mode === 'india_gst' ? ' (required)' : ' (optional)' }}<input v-model="form.items[item.id].hsn_code" class="tb-control" :aria-label="`HSN/SAC for ${item.sku}`" /></label>
                        <label class="tb-field">Unit code<input v-model="form.items[item.id].unit" class="tb-control" :aria-label="`Unit for ${item.sku}`" /></label>
                    </div>
                </div>
            </Card>
            <div v-if="Object.keys(form.errors).length" role="alert" class="tb-errors"><p v-for="(error, key) in form.errors" :key="key">{{ error }}</p></div>
            <label class="tb-check"><input v-model="form.confirmed" type="checkbox" /> I reviewed the tax registration, place of supply, classification and recorded tax.</label>
            <Button type="submit" :loading="form.processing" :disabled="!form.confirmed">Issue tax invoice</Button>
        </form>
        <Card v-else class="tb-stack"><p>An authorized user must issue the tax invoice before it can be downloaded.</p></Card>
    </AppLayout>
</template>
