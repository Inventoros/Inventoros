<script setup>
// A dashboard widget. Its props are the widget's `data`, which the server
// computes only for users who hold the widget's permission.
const {
    Inertia: { Link },
} = window.Inventoros;

defineProps({
    productCount: { type: Number, default: 0 },
});
</script>

<template>
    <div class="hw-widget">
        <p class="hw-widget__value">{{ productCount }}</p>
        <p class="hw-widget__label">products say hello</p>
        <Link href="/p/hello-world" class="hw-widget__link">Open the Hello World page</Link>
    </div>
</template>

<style scoped>
.hw-widget__value {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: hsl(var(--text-primary));
}

.hw-widget__label {
    margin: 0.25rem 0 0.75rem;
    font-size: 0.75rem;
    color: hsl(var(--text-secondary));
}

.hw-widget__link {
    font-size: 0.75rem;
    color: hsl(var(--accent));
    text-decoration: underline;
}
</style>
