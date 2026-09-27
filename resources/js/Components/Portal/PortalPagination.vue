<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    paginator: { type: Object, required: true },
});
</script>

<template>
    <div v-if="paginator.links && paginator.links.length > 3" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
        <p class="text-xs text-text-tertiary">
            {{ paginator.from }}-{{ paginator.to }} / {{ paginator.total }}
        </p>
        <nav class="inline-flex flex-wrap items-center gap-1">
            <template v-for="link in paginator.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    :class="[
                        'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-colors ds-focus-ring',
                        link.active
                            ? 'border-brand bg-brand text-brand-foreground'
                            : 'border-border-subtle bg-surface-canvas text-text-secondary hover:bg-surface-overlay',
                    ]"
                    v-html="link.label"
                />
                <span
                    v-else
                    class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-2.5 text-xs text-text-tertiary opacity-50"
                    v-html="link.label"
                />
            </template>
        </nav>
    </div>
</template>
