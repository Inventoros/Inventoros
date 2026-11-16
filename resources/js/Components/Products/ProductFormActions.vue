<script setup>
import FormActions from '@/Components/ui/FormActions.vue';

defineProps({
    label: { type: String, required: true },
    cancelHref: { type: String, required: true },
    processing: { type: Boolean, default: false },
});
</script>

<template>
    <FormActions class="product-form-actions" :label="label" :cancel-href="cancelHref" :processing="processing" />
</template>
