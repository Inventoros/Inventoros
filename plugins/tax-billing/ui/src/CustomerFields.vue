<script setup>
import { computed } from 'vue';
const props = defineProps({ form: Object, mode: String });
const fields = computed(() => props.form.plugin_fields.tax_billing);
</script>
<template>
    <div v-if="fields" class="tb-row">
        <p class="tb-help">{{ mode === 'india_gst' ? 'Enter the customer GSTIN in the Tax ID/VAT field above. Leave it blank for an unregistered customer.' : 'Use the Tax ID/VAT field for the customer’s local tax registration.' }}</p>
        <label class="tb-field">Default place-of-supply region/state code<input v-model="fields.region_code" class="tb-control" :maxlength="mode === 'india_gst' ? 2 : 80" /></label>
        <p v-if="form.errors['plugin_fields.tax_billing.region_code']" class="tb-errors">{{ form.errors['plugin_fields.tax_billing.region_code'] }}</p>
    </div>
</template>
