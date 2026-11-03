<script setup>
import { t } from './i18n.js';
defineProps({ customer: Object });
</script>
<template><div v-if="customer?.metadata?.tax_billing?.region_code" class="tb-row"><p class="tb-help">{{ t('defaultSupplyRegion') }}</p><p>{{ customer.metadata.tax_billing.region_code }}</p></div></template>
