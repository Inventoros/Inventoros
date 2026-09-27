<script setup>
// A tab on the product detail page. Its props are the placement's `data`.
defineProps({
    sku: { type: String, default: '' },
});
</script>

<template>
    <div class="hw-tab">
        <p class="hw-tab__title">Hello, {{ sku || 'product' }}</p>
        <p class="hw-tab__text">
            This tab comes from the Hello World plugin. It appears because the plugin placed a component in the
            product page's <code>tabs</code> slot; the product details stay on the Overview tab.
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
