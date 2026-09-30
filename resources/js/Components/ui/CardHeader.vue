<script setup>
defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
});
</script>

<template>
    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3 pb-4 border-b border-border-subtle">
        <!-- Same rule as PageHeader: actions wrap below the title rather than widen the page. -->
        <div class="min-w-0 flex-[1_1_12rem]">
            <h3 v-if="title" class="text-sm font-semibold text-text-primary tracking-tight">
                {{ title }}
            </h3>
            <p v-if="subtitle" class="mt-0.5 text-xs text-text-secondary">
                {{ subtitle }}
            </p>
            <slot name="title" />
        </div>
        <div class="flex min-w-0 max-w-full flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
