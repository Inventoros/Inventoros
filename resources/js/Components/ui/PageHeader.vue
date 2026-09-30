<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
    eyebrow: { type: String, default: null },
});
</script>

<template>
    <header class="flex flex-wrap items-start justify-between gap-x-6 gap-y-4 pb-6 border-b border-border-subtle">
        <!--
            The title and the actions share a row while both fit. The title never
            shrinks below 16rem: when the actions need more room than is left they
            wrap onto their own line, and the actions themselves wrap, so a long
            row of buttons (or longer translated labels) never pushes the page
            wider than the viewport or squeezes the title to a word per line.
        -->
        <div class="min-w-0 flex-[1_1_16rem]">
            <p v-if="eyebrow" class="text-xs font-medium uppercase tracking-wider text-text-tertiary">
                {{ eyebrow }}
            </p>
            <h1 class="mt-0.5 text-xl font-semibold text-text-primary tracking-tight break-words">
                {{ title }}
            </h1>
            <p v-if="description" class="mt-1 text-sm text-text-secondary">
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.actions" class="flex min-w-0 max-w-full flex-wrap items-center gap-2 md:self-center">
            <slot name="actions" />
        </div>
    </header>
</template>
