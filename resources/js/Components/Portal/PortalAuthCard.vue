<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
});
</script>

<template>
    <div class="mx-auto w-full max-w-md py-6 md:py-12">
        <div class="rounded-lg border border-border-subtle bg-surface-raised p-6 shadow-xs">
            <h1 class="text-lg font-semibold tracking-tight text-text-primary">{{ title }}</h1>
            <p v-if="description" class="mt-1 text-sm text-text-secondary">{{ description }}</p>
            <div class="mt-6">
                <slot />
            </div>
        </div>
    </div>
</template>
