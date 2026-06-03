<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const form = useForm({
    organization_name: '',
    admin_name: '',
    admin_email: '',
    admin_password: '',
    admin_password_confirmation: '',
});

const creating = ref(false);
const error = ref(null);

const createAdmin = async () => {
    creating.value = true;
    error.value = null;

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const response = await fetch(route('install.admin.create'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(form.data()),
        });

        const data = await response.json();

        if (data.success) {
            window.location.href = route('install.complete');
        } else {
            error.value = data.message;
        }
    } catch (err) {
        error.value = 'Failed to create admin account: ' + err.message;
    } finally {
        creating.value = false;
    }
};
</script>

<template>
    <Head :title="t('install.admin.title')" />

    <div class="min-h-screen bg-surface-canvas flex items-center justify-center p-4">
        <div class="max-w-2xl w-full">
            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-text-primary mb-2">{{ t('install.admin.title') }}</h1>
                <p class="text-text-secondary">{{ t('install.admin.subtitle') }}</p>
            </div>

            <!-- Admin Form Card -->
            <div class="bg-surface-base border border-border-subtle rounded-lg shadow-sm p-8">
                <!-- Error Alert -->
                <div v-if="error" class="mb-6 bg-status-danger-soft border border-status-danger/20 rounded-lg p-4">
                    <div class="flex">
                        <svg class="w-5 h-5 text-status-danger" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                        </svg>
                        <div class="ml-3">
                            <p class="text-sm font-semibold text-text-primary">{{ error }}</p>
                        </div>
                    </div>
                </div>

                <form @submit.prevent="createAdmin" class="space-y-6">
                    <!-- Organization Name -->
                    <div>
                        <label for="organization_name" class="block text-sm font-medium text-text-secondary mb-2">
                            {{ t('install.admin.orgName') }}
                        </label>
                        <input
                            id="organization_name"
                            v-model="form.organization_name"
                            type="text"
                            required
                            class="w-full px-4 py-2 border border-border-subtle rounded-lg bg-surface-canvas text-text-primary placeholder:text-text-tertiary focus:ring-2 focus:ring-brand focus:border-transparent"
                            placeholder="Acme Corporation"
                        />
                        <p class="mt-1 text-sm text-text-tertiary">{{ t('install.admin.orgNameHint') }}</p>
                    </div>

                    <div class="pt-4 border-t border-border-subtle">
                        <h3 class="text-lg font-semibold text-text-primary mb-4">{{ t('install.admin.adminDetails') }}</h3>

                        <!-- Admin Name -->
                        <div class="mb-6">
                            <label for="admin_name" class="block text-sm font-medium text-text-secondary mb-2">
                                {{ t('install.admin.fullName') }}
                            </label>
                            <input
                                id="admin_name"
                                v-model="form.admin_name"
                                type="text"
                                required
                                class="w-full px-4 py-2 border border-border-subtle rounded-lg bg-surface-canvas text-text-primary placeholder:text-text-tertiary focus:ring-2 focus:ring-brand focus:border-transparent"
                                placeholder="John Doe"
                            />
                        </div>

                        <!-- Admin Email -->
                        <div class="mb-6">
                            <label for="admin_email" class="block text-sm font-medium text-text-secondary mb-2">
                                {{ t('install.admin.email') }}
                            </label>
                            <input
                                id="admin_email"
                                v-model="form.admin_email"
                                type="email"
                                required
                                class="w-full px-4 py-2 border border-border-subtle rounded-lg bg-surface-canvas text-text-primary placeholder:text-text-tertiary focus:ring-2 focus:ring-brand focus:border-transparent"
                                placeholder="admin@example.com"
                            />
                        </div>

                        <!-- Admin Password -->
                        <div class="mb-6">
                            <label for="admin_password" class="block text-sm font-medium text-text-secondary mb-2">
                                {{ t('install.admin.password') }}
                            </label>
                            <input
                                id="admin_password"
                                v-model="form.admin_password"
                                type="password"
                                required
                                minlength="8"
                                class="w-full px-4 py-2 border border-border-subtle rounded-lg bg-surface-canvas text-text-primary placeholder:text-text-tertiary focus:ring-2 focus:ring-brand focus:border-transparent"
                                :placeholder="t('install.admin.passwordHint')"
                            />
                        </div>

                        <!-- Confirm Password -->
                        <div>
                            <label for="admin_password_confirmation" class="block text-sm font-medium text-text-secondary mb-2">
                                {{ t('install.admin.confirmPassword') }}
                            </label>
                            <input
                                id="admin_password_confirmation"
                                v-model="form.admin_password_confirmation"
                                type="password"
                                required
                                minlength="8"
                                class="w-full px-4 py-2 border border-border-subtle rounded-lg bg-surface-canvas text-text-primary placeholder:text-text-tertiary focus:ring-2 focus:ring-brand focus:border-transparent"
                                :placeholder="t('install.admin.confirmPasswordHint')"
                            />
                        </div>
                    </div>

                    <!-- Info Box -->
                    <div class="bg-status-info-soft border border-status-info/20 rounded-lg p-4">
                        <div class="flex">
                            <svg class="w-5 h-5 text-status-info mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                            </svg>
                            <div class="ml-3">
                                <p class="text-sm text-text-secondary">
                                    {{ t('install.admin.accessNote') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation -->
                    <div class="flex justify-between pt-6 border-t border-border-subtle">
                        <Link
                            :href="route('install.database')"
                            class="inline-flex items-center px-4 py-2 text-text-secondary bg-surface-base border border-border-subtle rounded-lg hover:bg-surface-overlay transition"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                            {{ t('common.back') }}
                        </Link>

                        <button
                            type="submit"
                            :disabled="creating"
                            class="inline-flex items-center px-6 py-2 bg-brand text-brand-foreground font-semibold rounded-lg hover:bg-brand-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span v-if="creating">{{ t('install.admin.creatingAccount') }}</span>
                            <span v-else>{{ t('install.admin.completeInstallation') }}</span>
                            <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                            </svg>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
