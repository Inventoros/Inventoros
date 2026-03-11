<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <Head :title="t('install.complete.title')" />

    <div class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 flex items-center justify-center p-4">
        <div class="max-w-2xl w-full">
            <!-- Success Card -->
            <div class="bg-white rounded-lg shadow-lg p-8 text-center">
                <!-- Success Icon -->
                <div class="mb-6">
                    <div class="mx-auto w-20 h-20 bg-green-100 rounded-full flex items-center justify-center">
                        <svg class="w-12 h-12 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                    </div>
                </div>

                <!-- Title -->
                <h1 class="text-3xl font-bold text-gray-900 mb-2">{{ t('install.complete.title') }}</h1>
                <p class="text-lg text-gray-600 mb-8">
                    {{ t('install.complete.description') }}
                </p>

                <!-- Success Details -->
                <div class="bg-green-50 border border-green-200 rounded-lg p-6 mb-8 text-left">
                    <h2 class="text-lg font-semibold text-green-900 mb-4">{{ t('install.complete.whatsNext') }}</h2>
                    <ul class="space-y-3">
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-green-600 mt-0.5 mr-3" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                            <span class="text-gray-700">{{ t('install.complete.step1') }}</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-green-600 mt-0.5 mr-3" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                            <span class="text-gray-700">{{ t('install.complete.step2') }}</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-green-600 mt-0.5 mr-3" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                            <span class="text-gray-700">{{ t('install.complete.step3') }}</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-green-600 mt-0.5 mr-3" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                            <span class="text-gray-700">{{ t('install.complete.step4') }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Resources -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-8 text-left">
                    <h2 class="text-lg font-semibold text-blue-900 mb-4">{{ t('install.complete.resources') }}</h2>
                    <ul class="space-y-2 text-sm">
                        <li>
                            <a href="https://github.com/inventoros/inventoros" target="_blank" class="text-blue-700 hover:text-blue-900 hover:underline">
                                {{ t('install.complete.docs') }}
                            </a>
                        </li>
                        <li>
                            <a href="https://github.com/inventoros/inventoros/discussions" target="_blank" class="text-blue-700 hover:text-blue-900 hover:underline">
                                {{ t('install.complete.community') }}
                            </a>
                        </li>
                        <li>
                            <a href="https://github.com/inventoros/inventoros/issues" target="_blank" class="text-blue-700 hover:text-blue-900 hover:underline">
                                {{ t('install.complete.issues') }}
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Login Button -->
                <div>
                    <Link
                        :href="route('login')"
                        class="inline-flex items-center px-8 py-3 bg-gray-900 text-white font-semibold rounded-lg hover:bg-gray-800 transition"
                    >
                        {{ t('install.complete.goToLogin') }}
                        <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                    </Link>
                </div>

                <!-- Footer Note -->
                <p class="mt-8 text-sm text-gray-500">
                    {{ t('install.complete.thankYou') }}
                </p>
            </div>
        </div>
    </div>
</template>
