<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    delta: { type: [String, Number], default: null },
    deltaTone: {
        type: String,
        default: 'neutral',
        validator: (t) => ['neutral', 'up', 'down'].includes(t),
    },
    hint: { type: String, default: null },
});

const deltaClass = computed(() => ({
    up: 'text-status-success',
    down: 'text-status-danger',
    neutral: 'text-text-tertiary',
}[props.deltaTone]));
</script>

<template>
    <div class="rounded-lg border border-border-subtle bg-surface-raised p-4">
        <div class="flex items-center justify-between gap-2">
            <p class="text-xs font-medium uppercase tracking-wider text-text-tertiary">
                {{ label }}
            </p>
            <slot name="icon" />
        </div>
        <p class="mt-2 text-2xl font-semibold text-text-primary tracking-tight tabular-nums">
            {{ value }}
        </p>
        <p v-if="delta !== null || hint" :class="cn('mt-1 text-xs flex items-center gap-1.5', deltaClass)">
            <span v-if="delta !== null">{{ delta }}</span>
            <span v-if="hint" class="text-text-tertiary">{{ hint }}</span>
        </p>
    </div>
</template>
