<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmailConfiguration from './Partials/EmailConfiguration.vue';
import NotificationPreferences from './Partials/NotificationPreferences.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    emailConfig: Object,
    userPreferences: Object,
});

const activeTab = ref('configuration');
</script>

<template>
    <Head title="Email Settings" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">
                Email Settings
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Tab Navigation -->
                <div class="mb-6 border-b border-gray-200 dark:border-dark-border">
                    <nav class="-mb-px flex space-x-8">
                        <button
                            @click="activeTab = 'configuration'"
                            :class="[
                                activeTab === 'configuration'
                                    ? 'border-primary-400 text-primary-400'
                                    : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300 dark:hover:border-gray-600',
                                'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition'
                            ]"
                        >
                            Email Configuration
                        </button>

                        <button
                            @click="activeTab = 'preferences'"
                            :class="[
                                activeTab === 'preferences'
                                    ? 'border-primary-400 text-primary-400'
                                    : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300 dark:hover:border-gray-600',
                                'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition'
                            ]"
                        >
                            Email Preferences
                        </button>
                    </nav>
                </div>

                <!-- Tab Content -->
                <div v-if="activeTab === 'configuration'">
                    <EmailConfiguration :email-config="emailConfig" />
                </div>

                <div v-if="activeTab === 'preferences'">
                    <NotificationPreferences :preferences="userPreferences" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
