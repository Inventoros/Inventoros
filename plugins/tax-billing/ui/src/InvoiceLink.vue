<script setup>
const { ui: { Button } } = window.Inventoros;
defineProps({ url: String });
</script>
<template><Button v-if="url" as="Link" variant="secondary" size="sm" :href="url">Tax invoice</Button></template>
