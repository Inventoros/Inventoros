<script setup>
import { t, formatNumber } from './i18n.js';
import { computed } from 'vue';
// A full page supplied by the plugin bundle. Layout, UI building blocks and
// Inertia helpers come from the app (window.Inventoros), so the page matches
// core pages and shares the current Inertia page state.
const {
    layouts: { AppLayout },
    ui: { PageHeader, Card },
    Inertia: { Head, Link },
} = window.Inventoros;

const props = defineProps({
    title: { type: String, default: 'Hello World' },
    name: { type: String, default: '' },
    productCount: { type: Number, default: null },
});
const pageTitle = computed(() => props.title === 'Hello World' ? t('title') : props.title);
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout>
        <template #header>
            <div class="hw-crumbs">
                <span>{{ t('plugins') }}</span>
                <span>/</span>
                <strong>{{ pageTitle }}</strong>
            </div>
        </template>

        <div>
            <PageHeader :title="pageTitle" :description="t('pageDescription')" />

            <Card class="hw-page-card">
                <p class="hw-page-lead">{{ name ? t('pageGreeting', { name }) : t('anonymousGreeting') }}</p>
                <p class="hw-page-text">
                    {{ t('registrationDescription', { phpRegistration: 'register_page()', bundleRegistration: 'plugin.registerPage()' }) }}
                </p>
                <p v-if="productCount !== null" class="hw-page-text">
                    {{ t('organizationProducts', { count: productCount, total: formatNumber(productCount) }) }}
                </p>
                <p class="hw-page-text">
                    <Link href="/dashboard">{{ t('backToDashboard') }}</Link>
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
