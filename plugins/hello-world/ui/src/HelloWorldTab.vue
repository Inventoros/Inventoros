<script setup>
import { t } from './i18n.js';
// A tab on the product detail page. Its props are the placement's `data`.
defineProps({
    sku: { type: String, default: '' },
});
</script>

<template>
    <div class="hw-tab">
        <p class="hw-tab__title">{{ sku ? t('greeting', { name: sku }) : t('productGreeting') }}</p>
        <p class="hw-tab__text">
            {{ t('tabDescription', { slot: 'tabs' }) }}
        </p>
    </div>
</template>

<style scoped>
.hw-tab {
    padding: 1.25rem;
    border: 1px solid hsl(var(--border-subtle));
    border-radius: 0.5rem;
    background: hsl(var(--surface-raised));
}

.hw-tab__title {
    margin: 0 0 0.25rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: hsl(var(--text-primary));
}

.hw-tab__text {
    margin: 0;
    font-size: 0.875rem;
    color: hsl(var(--text-secondary));
}

.hw-tab__text code {
    color: hsl(var(--text-primary));
}
</style>
