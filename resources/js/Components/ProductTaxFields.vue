<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { grossFromNet, netFromGross, rateValue } from '@/lib/lineTax';

/**
 * A product's tax rate next to its selling price. The stored price is net
 * (before tax); the price including tax is worked out from it, and typing a
 * price including tax works the net price out instead, so either can be
 * entered. Only the net price and the rate are saved.
 */
const props = defineProps({
    symbol: { type: String, default: '' },
    error: { type: String, default: '' },
});

const price = defineModel('price', { default: '' });
const taxRate = defineModel('taxRate', { default: '' });

const { t } = useI18n();

// Shown with cents, as the price is stored.
const grossText = () => grossFromNet(price.value, taxRate.value)?.toFixed(2) ?? '';

const gross = ref(grossText());
// While the price including tax is being typed, keep what was typed rather
// than replacing it with the figure the rounded net price gives back.
const typingGross = ref(false);

watch([price, taxRate], () => {
    if (!typingGross.value) gross.value = grossText();
});

const onGrossInput = (event) => {
    typingGross.value = true;
    gross.value = event.target.value;
    const net = netFromGross(gross.value, taxRate.value);
    if (net !== null) price.value = net.toFixed(2);
};

const onGrossBlur = () => {
    typingGross.value = false;
    gross.value = grossText();
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-text-tertiary';
</script>

<template>
    <div class="grid grid-cols-1 gap-4 @sm:grid-cols-2">
        <div>
            <label for="tax_rate" :class="fieldLabel">{{ t('tax.productRate') }}</label>
            <div class="relative">
                <input
                    id="tax_rate"
                    v-model="taxRate"
                    type="number"
                    step="0.001"
                    min="0"
                    max="100"
                    :class="[fieldInput, 'pe-8']"
                    :aria-invalid="error ? true : undefined"
                    aria-describedby="tax_rate-hint"
                />
                <div class="pointer-events-none absolute inset-y-0 end-0 flex items-center pe-3">
                    <span class="text-sm text-text-tertiary" aria-hidden="true">%</span>
                </div>
            </div>
            <p id="tax_rate-hint" class="mt-1 text-xs text-text-tertiary">{{ t('tax.productRateHint') }}</p>
            <p v-if="error" class="mt-1 text-xs text-status-danger">{{ error }}</p>
        </div>

        <div>
            <label for="price_gross" :class="fieldLabel">{{ t('tax.grossPrice') }}</label>
            <div class="relative">
                <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3">
                    <span class="text-sm text-text-tertiary">{{ props.symbol }}</span>
                </div>
                <input
                    id="price_gross"
                    :value="gross"
                    type="number"
                    step="0.01"
                    min="0"
                    :disabled="rateValue(taxRate) === null"
                    :class="[fieldInput, 'ps-8']"
                    aria-describedby="price_gross-hint"
                    @input="onGrossInput"
                    @blur="onGrossBlur"
                />
            </div>
            <p id="price_gross-hint" class="mt-1 text-xs text-text-tertiary">
                {{ rateValue(taxRate) === null ? t('tax.grossNeedsRate') : t('tax.grossPriceHint') }}
            </p>
        </div>
    </div>
</template>
