<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const props = defineProps({
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'secondary', 'ghost', 'outline', 'danger', 'link'].includes(v),
    },
    size: {
        type: String,
        default: 'md',
        validator: (s) => ['xs', 'sm', 'md', 'lg', 'icon'].includes(s),
    },
    as: {
        type: String,
        default: 'button',
        validator: (a) => ['button', 'a', 'Link'].includes(a),
    },
    href: { type: [String, Object], default: null },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    type: { type: String, default: 'button' },
});

const button = cva(
    'inline-flex items-center justify-center gap-1.5 whitespace-nowrap font-medium ' +
    'transition-colors duration-100 ds-focus-ring ' +
    'disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                default:
                    'bg-brand text-brand-foreground hover:bg-brand-hover ' +
                    'shadow-xs',
                secondary:
                    'bg-surface-overlay text-text-primary hover:bg-surface-sunken ' +
                    'border border-border-subtle',
                ghost:
                    'text-text-secondary hover:bg-surface-overlay hover:text-text-primary',
                outline:
                    'border border-border-strong bg-surface-canvas text-text-primary ' +
                    'hover:bg-surface-overlay',
                danger:
                    'bg-status-danger text-white hover:opacity-90 shadow-xs',
                link:
                    'text-brand hover:underline underline-offset-4 px-0',
            },
            size: {
                xs: 'h-7 px-2 text-xs rounded-md',
                sm: 'h-8 px-3 text-xs rounded-md',
                md: 'h-9 px-3.5 text-sm rounded-md',
                lg: 'h-10 px-5 text-sm rounded-lg',
                icon: 'h-8 w-8 rounded-md',
            },
        },
        defaultVariants: { variant: 'default', size: 'md' },
    }
);

const classes = computed(() => cn(button({ variant: props.variant, size: props.size })));

const ComponentTag = computed(() => {
    if (props.as === 'Link') return Link;
    return props.as;
});
</script>

<template>
    <component
        :is="ComponentTag"
        :href="href"
        :type="as === 'button' ? type : undefined"
        :disabled="disabled || loading"
        :class="classes"
    >
        <svg
            v-if="loading"
            class="h-3.5 w-3.5 animate-spin"
            fill="none"
            viewBox="0 0 24 24"
        >
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" />
            <path
                fill="currentColor"
                class="opacity-75"
                d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
            />
        </svg>
        <slot />
    </component>
</template>
