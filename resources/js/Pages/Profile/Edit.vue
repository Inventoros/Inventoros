<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <Head :title="t('profile.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('dashboard')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('profile.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('profile.title')" description="Manage your account information, password, and account removal." />

        <div class="mt-6 space-y-4">
            <Card>
                <UpdateProfileInformationForm
                    :must-verify-email="mustVerifyEmail"
                    :status="status"
                    class="max-w-xl"
                />
            </Card>

            <Card>
                <UpdatePasswordForm class="max-w-xl" />
            </Card>

            <Card class="border-status-danger/40">
                <DeleteUserForm class="max-w-xl" />
            </Card>
        </div>
    </AppLayout>
</template>
