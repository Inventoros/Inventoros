<script setup>
// A full page supplied by the plugin bundle. Layout, UI building blocks and
// Inertia helpers come from the app (window.Inventoros), so the page matches
// core pages and shares the current Inertia page state.
const {
    layouts: { AppLayout },
    ui: { PageHeader, Card },
    Inertia: { Head, Link },
} = window.Inventoros;

defineProps({
    title: { type: String, default: 'Hello World' },
    name: { type: String, default: '' },
    productCount: { type: Number, default: null },
});
</script>

<template>
    <Head :title="title" />

    <AppLayout>
        <template #header>
            <div class="hw-crumbs">
                <span>Plugins</span>
                <span>/</span>
                <strong>{{ title }}</strong>
            </div>
        </template>

        <div>
            <PageHeader :title="title" description="A page rendered from the Hello World plugin's runtime bundle." />

            <Card class="hw-page-card">
                <p class="hw-page-lead">Hello, {{ name || 'there' }}.</p>
                <p class="hw-page-text">
                    This route was registered with <code>register_page()</code> in the plugin's PHP, and this page was
                    registered with <code>plugin.registerPage()</code> in its pre-built bundle.
                </p>
                <p v-if="productCount !== null" class="hw-page-text">
                    Your organization has {{ productCount }} products.
                </p>
                <p class="hw-page-text">
                    <Link href="/dashboard">Back to the dashboard</Link>
                </p>
            </Card>
        </div>
    </AppLayout>
</template>

<style scoped>
.hw-crumbs {
    display: flex;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: hsl(var(--text-tertiary));
}

.hw-crumbs strong {
    font-weight: 500;
    color: hsl(var(--text-primary));
}

.hw-page-card {
    margin-top: 1.5rem;
}

.hw-page-lead {
    margin: 0 0 0.5rem;
    font-size: 1rem;
    font-weight: 600;
    color: hsl(var(--text-primary));
}

.hw-page-text {
    margin: 0.5rem 0 0;
    font-size: 0.875rem;
    color: hsl(var(--text-secondary));
}

.hw-page-text code {
    font-size: 0.8125rem;
    color: hsl(var(--text-primary));
}

.hw-page-text a {
    color: hsl(var(--accent));
    text-decoration: underline;
}
</style>
