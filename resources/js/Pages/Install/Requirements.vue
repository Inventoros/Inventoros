<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    requirements: Array,
    allMet: Boolean,
});
</script>

<template>
    <Head :title="t('install.requirements.title')" />

    <div class="min-h-screen bg-surface-canvas flex items-center justify-center p-4">
        <div class="max-w-3xl w-full">
            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-text-primary mb-2">{{ t('install.requirements.title') }}</h1>
                <p class="text-text-secondary">{{ t('install.requirements.subtitle') }}</p>
            </div>

            <!-- Requirements Card -->
            <div class="bg-surface-base border border-border-subtle rounded-lg shadow-sm p-8">
                <!-- Status Banner -->
                <div v-if="allMet" class="bg-status-success-soft border border-status-success/20 rounded-lg p-4 mb-6">
                    <div class="flex">
                        <svg class="w-6 h-6 text-status-success" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                        <div class="ml-3">
                            <h3 class="text-sm font-semibold text-text-primary">{{ t('install.requirements.allMet') }}</h3>
                            <p class="text-sm text-text-secondary mt-1">{{ t('install.requirements.allMetDesc') }}</p>
                        </div>
                    </div>
                </div>

                <div v-else class="bg-status-danger-soft border border-status-danger/20 rounded-lg p-4 mb-6">
                    <div class="flex">
                        <svg class="w-6 h-6 text-status-danger" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                        </svg>
                        <div class="ml-3">
                            <h3 class="text-sm font-semibold text-text-primary">{{ t('install.requirements.notMet') }}</h3>
                            <p class="text-sm text-text-secondary mt-1">{{ t('install.requirements.notMetDesc') }}</p>
                        </div>
                    </div>
                </div>

                <!-- Requirements List -->
                <div class="space-y-3">
                    <div
                        v-for="(requirement, index) in requirements"
                        :key="index"
                        class="flex items-center justify-between p-4 border rounded-lg"
                        :class="requirement.met ? 'border-status-success/20 bg-status-success-soft' : 'border-status-danger/20 bg-status-danger-soft'"
                    >
                        <div class="flex items-center flex-1">
                            <div
                                class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center"
                                :class="requirement.met ? 'bg-status-success-soft' : 'bg-status-danger-soft'"
                            >
                                <svg
                                    v-if="requirement.met"
                                    class="w-6 h-6 text-status-success"
                                    fill="currentColor"
                                    viewBox="0 0 20 20"
                                >
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                                <svg
                                    v-else
                                    class="w-6 h-6 text-status-danger"
                                    fill="currentColor"
                                    viewBox="0 0 20 20"
                                >
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-4">
                                <h3 class="font-semibold" :class="requirement.met ? 'text-status-success' : 'text-status-danger'">
                                    {{ requirement.name }}
                                </h3>
                                <div class="text-sm mt-1">
                                    <span :class="requirement.met ? 'text-status-success' : 'text-status-danger'">
                                        {{ t('install.requirements.current') }}: {{ requirement.current }}
                                    </span>
                                    <span class="text-text-tertiary mx-2">•</span>
                                    <span class="text-text-secondary">
                                        {{ t('install.requirements.required') }}: {{ requirement.required }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex justify-between mt-8 pt-6 border-t border-border-subtle">
                    <Link
                        :href="route('install.index')"
                        class="inline-flex items-center px-4 py-2 text-text-secondary bg-surface-base border border-border-subtle rounded-lg hover:bg-surface-overlay transition"
                    >
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                        {{ t('common.back') }}
                    </Link>

                    <Link
                        v-if="allMet"
                        :href="route('install.database')"
                        class="inline-flex items-center px-6 py-2 bg-brand text-brand-foreground font-semibold rounded-lg hover:bg-brand-hover transition"
                    >
                        {{ t('install.requirements.continue') }}
                        <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                    </Link>

                    <button
                        v-else
                        disabled
                        class="inline-flex items-center px-6 py-2 bg-surface-sunken text-text-tertiary font-semibold rounded-lg cursor-not-allowed"
                    >
                        {{ t('install.requirements.fixFirst') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
