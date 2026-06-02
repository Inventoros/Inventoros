<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
    eyebrow: { type: String, default: null },
});
</script>

<template>
    <header class="flex flex-col gap-4 pb-6 border-b border-border-subtle md:flex-row md:items-center md:justify-between">
        <div class="min-w-0">
            <p v-if="eyebrow" class="text-xs font-medium uppercase tracking-wider text-text-tertiary">
                {{ eyebrow }}
            </p>
            <h1 class="mt-0.5 text-xl font-semibold text-text-primary tracking-tight">
                {{ title }}
            </h1>
            <p v-if="description" class="mt-1 text-sm text-text-secondary">
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.actions" class="flex items-center gap-2 shrink-0">
            <slot name="actions" />
        </div>
    </header>
</template>
