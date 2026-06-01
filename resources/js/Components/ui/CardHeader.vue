<script setup>
defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
});
</script>

<template>
    <div class="flex items-start justify-between gap-4 pb-4 border-b border-border-subtle">
        <div class="min-w-0">
            <h3 v-if="title" class="text-sm font-semibold text-text-primary tracking-tight">
                {{ title }}
            </h3>
            <p v-if="subtitle" class="mt-0.5 text-xs text-text-secondary">
                {{ subtitle }}
            </p>
            <slot name="title" />
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <slot name="actions" />
        </div>
    </div>
</template>
