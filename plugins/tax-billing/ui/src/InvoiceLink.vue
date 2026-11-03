<script setup>
import { t } from './i18n.js';
const { ui: { Button } } = window.Inventoros;
defineProps({ url: String });
</script>
<template><Button v-if="url" as="Link" variant="secondary" size="sm" :href="url">{{ t('taxInvoice') }}</Button></template>
