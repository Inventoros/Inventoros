<script setup>
import Pagination from '@/Components/ui/Pagination.vue';
defineProps({ paginator: { type: Object, required: true } });
</script>
<template><Pagination :paginator="paginator" /></template>
