<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { taxFromRate } from '@/composables/useOrderTotals';

/**
 * Tax as an amount, which is what orders and purchase orders store, plus an
 * optional rate in percent that fills the amount in from the taxable
 * subtotal (after discounts). While a rate is set the amount follows the
 * subtotal as lines change; typing an amount by hand clears the rate. The
 * rate itself is never submitted.
 */
const props = defineProps({
    id: { type: String, default: 'tax' },
    base: { type: Number, default: 0 },
    error: { type: String, default: '' },
});

const amount = defineModel({ default: 0 });
const rate = ref(null);

const { t } = useI18n();

const apply = () => {
    if (rate.value !== null) amount.value = taxFromRate(props.base, rate.value);
};

const onRateInput = (event) => {
    rate.value = event.target.value === '' ? null : event.target.value;
    apply();
};

watch(() => props.base, apply);

const control = 'h-9 rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
</script>

<template>
    <div>
        <label :for="id" class="mb-1 block text-sm text-text-secondary">{{ t('tax.amount') }}</label>
        <div class="flex gap-2">
            <input
                :id="id"
                v-model.number="amount"
                type="number"
                step="0.01"
                min="0"
                :class="[control, 'min-w-0 flex-1']"
                @input="rate = null"
            />
            <div class="relative w-28 shrink-0">
                <input
                    :id="`${id}_rate`"
                    :value="rate ?? ''"
                    type="number"
                    step="0.001"
                    min="0"
                    max="100"
                    :placeholder="t('tax.rate')"
                    :aria-label="t('tax.rate')"
                    :class="[control, 'w-full pr-7']"
                    @input="onRateInput"
                />
                <span class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-sm text-text-tertiary">%</span>
            </div>
        </div>
        <p class="mt-1 text-xs text-text-tertiary">{{ t('tax.rateHint') }}</p>
        <p v-if="error" class="mt-1 text-xs text-status-danger">{{ error }}</p>
    </div>
</template>
