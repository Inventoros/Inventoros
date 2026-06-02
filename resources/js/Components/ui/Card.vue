<script setup>
import { cn } from '@/lib/utils';

defineProps({
    padded: { type: Boolean, default: true },
    hoverable: { type: Boolean, default: false },
});
</script>

<template>
    <div
        :class="cn(
            'rounded-lg border border-border-subtle bg-surface-raised shadow-xs',
            'transition-colors',
            padded && 'p-5',
            hoverable && 'hover:border-border-strong cursor-pointer',
            $attrs.class,
        )"
    >
        <slot />
    </div>
</template>
