<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

onMounted(() => {
    // Apply dark mode based on localStorage
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark' || !savedTheme) {
        document.documentElement.classList.add('dark');
    }
});
</script>

<template>
    <Head :title="t('errors.403.title')" />

    <div class="min-h-screen bg-surface-canvas flex items-center justify-center px-4">
        <div class="max-w-md w-full">
            <!-- Card -->
            <div class="bg-surface-base rounded-lg shadow-sm border border-border-subtle overflow-hidden">
                <!-- Header with icon -->
                <div class="bg-status-danger-soft border-b border-status-danger/20 px-6 py-8 text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-status-danger-soft rounded-full mb-4">
                        <svg class="w-8 h-8 text-status-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <h1 class="text-3xl font-bold text-text-primary mb-2">{{ t('errors.403.heading') }}</h1>
                    <p class="text-status-danger font-semibold">{{ t('errors.403.code') }}</p>
                </div>

                <!-- Content -->
                <div class="px-6 py-8">
                    <p class="text-text-secondary text-center mb-6">
                        {{ t('errors.403.description') }}
                    </p>

                    <!-- Info box -->
                    <div class="bg-status-info-soft border border-status-info/20 rounded-lg p-4 mb-6">
                        <div class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-status-info flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <div class="text-sm">
                                <p class="font-semibold text-text-primary mb-1">{{ t('errors.403.needAccess') }}</p>
                                <p class="text-text-secondary">
                                    {{ t('errors.403.contactAdmin') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex flex-col gap-3">
                        <Link
                            :href="route('dashboard')"
                            class="w-full inline-flex items-center justify-center px-4 py-3 bg-brand hover:bg-brand-hover text-brand-foreground font-semibold rounded-lg transition"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                            </svg>
                            {{ t('errors.403.goToDashboard') }}
                        </Link>
                        <button
                            @click="$inertia.visit($page.props.previousUrl || route('dashboard'))"
                            class="w-full inline-flex items-center justify-center px-4 py-3 bg-surface-overlay hover:bg-surface-sunken text-text-secondary font-medium rounded-lg transition border border-border-subtle"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                            {{ t('errors.403.goBack') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
