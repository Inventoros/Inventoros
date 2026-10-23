<script setup>defineProps({ customer: Object });</script>
<template><div v-if="customer?.metadata?.tax_billing?.region_code" class="tb-row"><p class="tb-help">Default place-of-supply region/state</p><p>{{ customer.metadata.tax_billing.region_code }}</p></div></template>
