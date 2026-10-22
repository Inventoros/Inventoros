<script setup>
import { computed } from 'vue';
import { useCurrency } from '@/composables/useCurrency';

const model = defineModel({ type: String });
const { organizationCurrency, supportedCurrencies } = useCurrency();
// Keep legacy and record-specific currencies selectable as well.
const currencies = computed(() => [...new Set([
    organizationCurrency.value, ...supportedCurrencies.value, model.value,
].filter(Boolean))]);
</script>

<template>
    <select v-model="model">
        <option v-for="currency in currencies" :key="currency" :value="currency">{{ currency }}</option>
    </select>
</template>
