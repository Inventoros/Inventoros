<script setup>
import { ref } from 'vue';

defineProps({
    version: {
        type: String,
        default: '',
    },
});

const dismissed = ref(false);
</script>

<template>
    <div v-if="!dismissed" class="hw-banner" role="status">
        <div class="hw-banner__body">
            <p class="hw-banner__title">
                Hello from the Hello World plugin
                <span class="hw-banner__badge">Plugin active</span>
            </p>
            <p class="hw-banner__text">
                This banner was loaded at runtime from the plugin's own pre-built bundle, so it works on
                installs that never run npm. Turn it off on the <a href="/plugins">Plugins page</a>.
            </p>
            <p v-if="version" class="hw-banner__meta">Version {{ version }}</p>
        </div>
        <button type="button" class="hw-banner__dismiss" aria-label="Dismiss" @click="dismissed = true">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>
</template>

<style scoped>
/* The app's design tokens are HSL triplets on :root, swapped in dark mode. */
.hw-banner {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding: 1rem 1.25rem;
    border: 1px solid hsl(var(--border-subtle));
    border-left: 3px solid hsl(var(--accent));
    border-radius: 0.5rem;
    background: hsl(var(--surface-raised));
    color: hsl(var(--text-primary));
}

.hw-banner__title {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    margin: 0 0 0.25rem;
    font-size: 0.875rem;
    font-weight: 600;
}

.hw-banner__badge {
    padding: 0.125rem 0.5rem;
    border-radius: 9999px;
    background: hsl(var(--accent-soft));
    color: hsl(var(--accent));
    font-size: 0.75rem;
    font-weight: 500;
}

.hw-banner__text {
    margin: 0;
    font-size: 0.875rem;
    color: hsl(var(--text-secondary));
}

.hw-banner__text a {
    color: hsl(var(--accent));
    text-decoration: underline;
}

.hw-banner__meta {
    margin: 0.5rem 0 0;
    font-size: 0.75rem;
    color: hsl(var(--text-tertiary));
}

.hw-banner__dismiss {
    flex-shrink: 0;
    padding: 0.375rem;
    border: 0;
    border-radius: 0.375rem;
    background: transparent;
    color: hsl(var(--text-tertiary));
    cursor: pointer;
}

.hw-banner__dismiss:hover {
    background: hsl(var(--surface-sunken));
    color: hsl(var(--text-primary));
}

.hw-banner__dismiss:focus-visible {
    outline: 2px solid hsl(var(--ring));
    outline-offset: 2px;
}
</style>
