<script setup>
const { layouts: { AppLayout }, ui: { PageHeader, Card, Button }, Inertia: { Head, useForm } } = window.Inventoros;
const props = defineProps({ profile: Object, saveUrl: String });
const form = useForm(JSON.parse(JSON.stringify(props.profile)));
function setMode() {
    if (form.mode === 'india_gst') { form.country = 'IN'; form.tax_id_label = 'GSTIN'; }
}
</script>

<template>
    <Head title="Tax billing" />
    <AppLayout>
        <PageHeader title="Tax billing" description="Tax-registration details and invoice presentation for this organization." />
        <form class="tb-stack" @submit.prevent="form.post(saveUrl, { preserveScroll: true })">
            <Card>
                <p class="tb-help">Set the tax treatment that applies to your business. Recorded order tax must match the selected rates before an invoice can be issued. This module does not choose tax rates, file returns or obtain government e-invoice acknowledgements.</p>
                <div class="tb-grid">
                    <label class="tb-field">Profile<select v-model="form.mode" class="tb-control" @change="setMode"><option value="generic">Custom country profile</option><option value="india_gst">India GST: domestic supplies</option></select></label>
                    <label class="tb-field">Country code (ISO 2-letter)<input v-model="form.country" class="tb-control" maxlength="2" placeholder="IN, GB, CA" @input="form.country = form.country.toUpperCase()" /></label>
                    <label class="tb-field">Supplier name<input v-model="form.issuer_name" class="tb-control" autocomplete="organization" /></label>
                    <label class="tb-field">Supplier tax registration<input v-model="form.issuer_tax_id" class="tb-control" /></label>
                    <label class="tb-field">Supplier region/state code<input v-model="form.region_code" class="tb-control" /></label>
                    <label class="tb-field">Tax identifier label<input v-model="form.tax_id_label" class="tb-control" placeholder="GSTIN, VAT number, TIN" /></label>
                    <label class="tb-field tb-wide">Supplier address<textarea v-model="form.issuer_address" class="tb-control" rows="3" autocomplete="street-address" /></label>
                    <label class="tb-field">Variant option for size grouping<input v-model="form.size_option" class="tb-control" /></label>
                </div>
                <label class="tb-check"><input v-model="form.shipping_taxable" type="checkbox" /> Include shipping in the taxable base</label>
            </Card>
            <Card v-if="form.mode === 'india_gst'">
                <h2 class="tb-title">India GST</h2>
                <p class="tb-help">CGST plus SGST/UTGST applies when supplier and place-of-supply state codes match; IGST is used when they differ. Enter the applicable total GST rate. Exports, SEZ, reverse charge, cess and e-invoicing need dedicated treatment.</p>
                <div class="tb-grid">
                    <label class="tb-field">Total GST rate (%)<input v-model="form.gst_rate" class="tb-control" type="number" min="0" max="100" step="0.0001" /></label>
                    <label class="tb-field">Local component<select v-model="form.local_tax_label" class="tb-control"><option>SGST</option><option>UTGST</option></select></label>
                </div>
            </Card>
            <Card v-else>
                <h2 class="tb-title">Tax components</h2>
                <p class="tb-help">Configure additive taxes such as VAT, GST/PST or sales tax. Mixed-rate, compound-tax and country filing rules are not automated.</p>
                <div v-for="(component, index) in form.components" :key="index" class="tb-grid tb-row">
                    <label class="tb-field">Component name<input v-model="component.label" class="tb-control" /></label>
                    <label class="tb-field">Rate (%)<input v-model="component.rate" class="tb-control" type="number" min="0" max="100" step="0.0001" /></label>
                    <Button v-if="form.components.length > 1" variant="ghost" @click="form.components.splice(index, 1)">Remove component</Button>
                </div>
                <Button v-if="form.components.length < 10" variant="secondary" @click="form.components.push({ label: '', rate: '0' })">Add tax component</Button>
            </Card>
            <div v-if="Object.keys(form.errors).length" role="alert" class="tb-errors"><p v-for="(error, key) in form.errors" :key="key">{{ error }}</p></div>
            <Button type="submit" :loading="form.processing">Save tax billing profile</Button>
        </form>
    </AppLayout>
</template>
