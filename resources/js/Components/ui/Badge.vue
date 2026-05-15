<script setup>
import { computed } from 'vue';
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const props = defineProps({
    variant: {
        type: String,
        default: 'neutral',
        validator: (v) => ['neutral', 'brand', 'success', 'warning', 'danger', 'info'].includes(v),
    },
    size: {
        type: String,
        default: 'md',
        validator: (s) => ['sm', 'md'].includes(s),
    },
    dot: { type: Boolean, default: false },
});

const badge = cva(
    'inline-flex items-center gap-1.5 rounded-md font-medium border tracking-tight',
    {
        variants: {
            variant: {
                neutral: 'bg-surface-overlay text-text-secondary border-border-subtle',
                brand: 'bg-brand-soft text-brand border-brand/20',
                success: 'bg-status-success-soft text-status-success border-status-success/20',
                warning: 'bg-status-warning-soft text-status-warning border-status-warning/20',
                danger: 'bg-status-danger-soft text-status-danger border-status-danger/20',
                info: 'bg-status-info-soft text-status-info border-status-info/20',
            },
            size: {
                sm: 'h-5 px-1.5 text-[10px]',
                md: 'h-6 px-2 text-xs',
            },
        },
    }
);

const dotColor = computed(() => ({
    neutral: 'bg-text-tertiary',
    brand: 'bg-brand',
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    danger: 'bg-status-danger',
    info: 'bg-status-info',
})[props.variant]);
</script>

<template>
    <span :class="cn(badge({ variant, size }))">
        <span v-if="dot" :class="['h-1.5 w-1.5 rounded-full', dotColor]" />
        <slot />
    </span>
</template>
