<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import EmailConfiguration from './Partials/EmailConfiguration.vue';
import NotificationPreferences from './Partials/NotificationPreferences.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    emailConfig: Object,
    userPreferences: Object,
});

const activeTab = ref('configuration');
</script>

<template>
    <Head :title="t('settings.email.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('settings.account.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('settings.account.index')" class="text-text-tertiary hover:text-text-primary">Settings</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('settings.email.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('settings.email.title')" description="Configure how your workspace sends email and notifications." />

        <!-- Tabs -->
        <div class="mt-6 border-b border-border-subtle">
            <nav class="-mb-px flex gap-8">
                <button
                    @click="activeTab = 'configuration'"
                    :class="[
                        'border-b-2 px-1 py-3 text-sm font-medium transition-colors',
                        activeTab === 'configuration'
                            ? 'border-brand text-brand'
                            : 'border-transparent text-text-tertiary hover:border-border-strong hover:text-text-secondary'
                    ]"
                >
                    {{ t('settings.email.configuration') }}
                </button>
                <button
                    @click="activeTab = 'preferences'"
                    :class="[
                        'border-b-2 px-1 py-3 text-sm font-medium transition-colors',
                        activeTab === 'preferences'
                            ? 'border-brand text-brand'
                            : 'border-transparent text-text-tertiary hover:border-border-strong hover:text-text-secondary'
                    ]"
                >
                    {{ t('settings.notificationPreferences.title') }}
                </button>
            </nav>
        </div>

        <!-- Tab Content -->
        <div v-show="activeTab === 'configuration'" class="mt-6">
            <EmailConfiguration :email-config="emailConfig" />
        </div>

        <div v-show="activeTab === 'preferences'" class="mt-6">
            <NotificationPreferences :preferences="userPreferences" />
        </div>
    </AppLayout>
</template>
