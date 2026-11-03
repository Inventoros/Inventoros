<script setup>
import { computed } from 'vue';
import { t } from './i18n.js';
const props = defineProps({ form: Object, mode: String });
const fields = computed(() => props.form.plugin_fields.tax_billing);
</script>
<template>
    <div v-if="fields" class="tb-row">
        <p class="tb-help">{{ mode === 'india_gst' ? t('customerGstHelp') : t('customerTaxHelp') }}</p>
        <label class="tb-field">{{ t('defaultSupplyRegionCode') }}<input v-model="fields.region_code" class="tb-control" :maxlength="mode === 'india_gst' ? 2 : 80" /></label>
        <p v-if="form.errors['plugin_fields.tax_billing.region_code']" class="tb-errors">{{ form.errors['plugin_fields.tax_billing.region_code'] }}</p>
    </div>
</template>
