<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Star } from 'lucide-vue-next';

/**
 * 1-5 supplier rating. Read-only by default; pass `editable` with v-model to
 * pick a rating (clicking the current rating again clears it).
 */
const props = defineProps({
    modelValue: { type: [Number, String, null], default: null },
    editable: { type: Boolean, default: false },
    size: { type: Number, default: 14 },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();

const value = computed(() => {
    const n = parseInt(props.modelValue, 10);
    return Number.isInteger(n) && n >= 1 && n <= 5 ? n : null;
});

const pick = (n) => {
    emit('update:modelValue', value.value === n ? null : n);
};
</script>

<template>
    <div
        v-if="editable"
        class="flex items-center gap-1"
        role="radiogroup"
        :aria-label="t('suppliers.rating.label')"
    >
        <button
            v-for="n in 5"
            :key="n"
            type="button"
            role="radio"
            :aria-checked="value === n"
            :aria-label="t('suppliers.rating.stars', { count: n })"
            class="rounded p-0.5 ds-focus-ring"
            @click="pick(n)"
        >
            <Star
                :size="size + 4"
                :class="value && n <= value ? 'fill-status-warning text-status-warning' : 'text-text-tertiary'"
            />
        </button>
        <span class="ml-2 text-xs text-text-tertiary">
            {{ value ? t('suppliers.rating.stars', { count: value }) : t('suppliers.rating.none') }}
        </span>
    </div>
    <span
        v-else-if="value"
        class="inline-flex items-center gap-0.5"
        :title="t('suppliers.rating.stars', { count: value })"
        :aria-label="t('suppliers.rating.stars', { count: value })"
    >
        <Star
            v-for="n in 5"
            :key="n"
            :size="size"
            :class="n <= value ? 'fill-status-warning text-status-warning' : 'text-text-tertiary'"
        />
    </span>
    <span v-else class="text-xs text-text-tertiary">{{ t('suppliers.rating.none') }}</span>
</template>
