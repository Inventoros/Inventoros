<script setup>
import { useI18n } from 'vue-i18n';

/**
 * A discount as a type (none / percent / fixed) plus its value, for order
 * lines and the order itself. Clearing the type clears the value, so "no
 * discount" never submits a stray number.
 */
const props = defineProps({
    id: { type: String, required: true },
    label: { type: String, default: '' },
    error: { type: String, default: '' },
    compact: { type: Boolean, default: false },
});

const type = defineModel('type', { default: '' });
const value = defineModel('value', { default: null });

const { t } = useI18n();

const onTypeChange = (event) => {
    type.value = event.target.value;
    if (!type.value) value.value = null;
};

const control = 'h-9 rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
</script>

<template>
    <div>
        <label v-if="label" :for="`${id}_type`" :class="compact ? 'mb-1 block text-[11px] text-text-tertiary' : 'mb-1 block text-sm text-text-secondary'">{{ label }}</label>
        <div class="flex gap-2">
            <select :id="`${id}_type`" :value="type ?? ''" :class="[control, 'min-w-0 flex-1']" :aria-label="label || t('discounts.discount')" @change="onTypeChange">
                <option value="">{{ t('discounts.none') }}</option>
                <option value="percent">{{ t('discounts.percent') }}</option>
                <option value="fixed">{{ t('discounts.fixed') }}</option>
            </select>
            <input
                :id="`${id}_value`"
                v-model.number="value"
                type="number"
                step="0.01"
                min="0"
                :max="type === 'percent' ? 100 : undefined"
                :disabled="!type"
                :aria-label="t('discounts.value')"
                :class="[control, 'w-24 disabled:opacity-50']"
            />
        </div>
        <p v-if="error" class="mt-1 text-xs text-status-danger">{{ error }}</p>
    </div>
</template>
