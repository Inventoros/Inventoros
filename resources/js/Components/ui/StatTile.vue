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
    // When set, the #icon slot renders inside a filled gradient chip.
    iconTone: {
        type: String,
        default: null,
        validator: (t) => t === null || ['brand', 'success', 'warning', 'violet', 'info'].includes(t),
    },
});

const deltaClass = computed(() => ({
    up: 'text-status-success',
    down: 'text-status-danger',
    neutral: 'text-text-tertiary',
}[props.deltaTone]));

const chipClass = computed(() => ({
    brand: 'bg-gradient-to-br from-brand to-brand-hover text-brand-foreground',
    success: 'bg-gradient-to-br from-status-success to-status-success text-white',
    warning: 'bg-gradient-to-br from-status-warning to-status-warning text-white',
    violet: 'bg-gradient-to-br from-violet-500 to-violet-600 text-white',
    info: 'bg-gradient-to-br from-status-info to-status-info text-white',
}[props.iconTone]));
</script>

<template>
    <div class="rounded-lg border border-border-subtle bg-surface-raised p-4 transition-colors hover:border-border-strong">
        <div class="flex items-start justify-between gap-2">
            <p class="text-xs font-medium uppercase tracking-wider text-text-tertiary">
                {{ label }}
            </p>
            <span
                v-if="iconTone"
                :class="cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-lg shadow-sm', chipClass)"
            >
                <slot name="icon" />
            </span>
            <slot v-else name="icon" />
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
