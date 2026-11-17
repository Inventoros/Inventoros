<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatMoney } from '@/lib/money';
import { displayCalendarDate } from '@/lib/dates';

/**
 * An amount in another currency shown in the organization's currency, below
 * the original: "≈ 4,875.00 HUF" and the date of the stored rate used. A
 * stale or fallback rate says so; with no stored rate only a short hint is
 * shown and no amount is made up.
 *
 * `conversion` comes from OrganizationCurrencyConverter::toOrganizationCurrency():
 * { currency, status: 'ok'|'stale'|'unavailable', amount, rate,
 *   effective_date, is_fallback, provider_name }. Null renders nothing.
 */
const props = defineProps({
    conversion: { type: Object, default: null },
    // The currency of the original amount, for the rate tooltip.
    from: { type: String, default: '' },
});

const { t } = useI18n();

const hasAmount = computed(() => props.conversion && props.conversion.status !== 'unavailable' && props.conversion.amount != null);

const rateTitle = computed(() => {
    if (!hasAmount.value || !props.from || !props.conversion.rate) return undefined;
    const rate = t('productSuppliers.rateTitle', { from: props.from, rate: props.conversion.rate, to: props.conversion.currency });
    return props.conversion.provider_name ? `${rate} (${props.conversion.provider_name})` : rate;
});

const rateLabel = computed(() => {
    const date = displayCalendarDate(props.conversion?.effective_date);
    if (props.conversion?.status === 'stale') return t('productSuppliers.staleRateOf', { date });
    if (props.conversion?.is_fallback) return t('productSuppliers.fallbackRateOf', { date });
    return t('productSuppliers.rateOf', { date });
});
</script>

<template>
    <span v-if="hasAmount" class="block text-xs text-text-tertiary" :title="rateTitle" data-converted="amount">
        <span class="whitespace-nowrap">{{ t('productSuppliers.approximately', { amount: formatMoney(conversion.amount, conversion.currency) }) }}</span>
        <span
            class="block"
            :class="conversion.status === 'stale' || conversion.is_fallback ? 'text-status-warning' : ''"
        >{{ rateLabel }}</span>
    </span>
    <span v-else-if="conversion" class="block text-xs text-text-tertiary" data-converted="no-rate">
        {{ t('productSuppliers.noRate', { currency: conversion.currency }) }}
    </span>
</template>
