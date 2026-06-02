<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    user: Object,
});

const activeTab = ref('profile');

// Profile form
const profileForm = useForm({
    name: props.user.name || '',
    email: props.user.email || '',
});

const submitProfile = () => {
    profileForm.patch(route('settings.account.update.profile'), {
        preserveScroll: true,
    });
};

// Password form
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submitPassword = () => {
    passwordForm.patch(route('settings.account.update.password'), {
        preserveScroll: true,
        onSuccess: () => {
            passwordForm.reset();
        },
    });
};

// Notification preferences
const notificationPrefs = props.user.notification_preferences || {};
const notificationForm = useForm({
    email_notifications: notificationPrefs.email_notifications ?? true,
    low_stock_alerts: notificationPrefs.low_stock_alerts ?? true,
    order_notifications: notificationPrefs.order_notifications ?? true,
    system_notifications: notificationPrefs.system_notifications ?? true,
});

const submitNotifications = () => {
    notificationForm.patch(route('settings.account.update.notifications'), {
        preserveScroll: true,
    });
};

// User preferences
const userPrefs = notificationPrefs.preferences || {};
const preferencesForm = useForm({
    theme: userPrefs.theme || 'dark',
    language: userPrefs.language || 'en',
    items_per_page: userPrefs.items_per_page || 25,
});

const submitPreferences = () => {
    preferencesForm.patch(route('settings.account.update.preferences'), {
        preserveScroll: true,
    });
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring disabled:cursor-not-allowed disabled:opacity-60';
const fieldError = 'mt-1 text-xs text-status-danger';

const toggles = [
    { key: 'email_notifications', label: 'settings.account.emailNotifications', desc: 'settings.account.emailNotificationsDesc' },
    { key: 'low_stock_alerts', label: 'settings.account.lowStockAlerts', desc: 'settings.account.lowStockAlertsDesc' },
    { key: 'order_notifications', label: 'settings.account.orderNotifications', desc: 'settings.account.orderNotificationsDesc' },
    { key: 'system_notifications', label: 'settings.account.systemNotifications', desc: 'settings.account.systemNotificationsDesc' },
];
</script>

<template>
    <Head :title="t('settings.account.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('settings.account.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('settings.account.index')" class="text-text-tertiary hover:text-text-primary">Settings</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">Account</span>
            </div>
        </template>

        <PageHeader :title="t('settings.account.title')" description="Manage your profile, password, notifications, and preferences." />

        <!-- Tabs -->
        <div class="mt-6 border-b border-border-subtle">
            <nav class="-mb-px flex gap-8">
                <button
                    @click="activeTab = 'profile'"
                    :class="[
                        'border-b-2 px-1 py-3 text-sm font-medium transition-colors',
                        activeTab === 'profile'
                            ? 'border-brand text-brand'
                            : 'border-transparent text-text-tertiary hover:border-border-strong hover:text-text-secondary'
                    ]"
                >
                    {{ t('settings.account.profile') }}
                </button>
                <button
                    @click="activeTab = 'password'"
                    :class="[
                        'border-b-2 px-1 py-3 text-sm font-medium transition-colors',
                        activeTab === 'password'
                            ? 'border-brand text-brand'
                            : 'border-transparent text-text-tertiary hover:border-border-strong hover:text-text-secondary'
                    ]"
                >
                    {{ t('settings.account.password') }}
                </button>
                <button
                    @click="activeTab = 'notifications'"
                    :class="[
                        'border-b-2 px-1 py-3 text-sm font-medium transition-colors',
                        activeTab === 'notifications'
                            ? 'border-brand text-brand'
                            : 'border-transparent text-text-tertiary hover:border-border-strong hover:text-text-secondary'
                    ]"
                >
                    {{ t('settings.account.notifications') }}
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
                    {{ t('settings.account.preferences') }}
                </button>
            </nav>
        </div>

        <!-- Profile Tab -->
        <div v-show="activeTab === 'profile'" class="mt-6">
            <Card :padded="false">
                <form @submit.prevent="submitProfile">
                    <div class="px-5 pt-5">
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('settings.account.profileInfo') }}</h3>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-1 gap-5">
                            <div>
                                <label for="name" :class="fieldLabel">{{ t('common.name') }}</label>
                                <input
                                    id="name"
                                    v-model="profileForm.name"
                                    type="text"
                                    :class="fieldInput"
                                    required
                                />
                                <p v-if="profileForm.errors.name" :class="fieldError">{{ profileForm.errors.name }}</p>
                            </div>
                            <div>
                                <label for="email" :class="fieldLabel">{{ t('common.email') }}</label>
                                <input
                                    id="email"
                                    v-model="profileForm.email"
                                    type="email"
                                    :class="fieldInput"
                                    required
                                />
                                <p v-if="profileForm.errors.email" :class="fieldError">{{ profileForm.errors.email }}</p>
                            </div>
                        </div>

                        <div class="mt-6 flex justify-end">
                            <Button type="submit" variant="default" :loading="profileForm.processing" :disabled="profileForm.processing">
                                {{ t('common.saveChanges') }}
                            </Button>
                        </div>
                    </div>
                </form>
            </Card>
        </div>

        <!-- Password Tab -->
        <div v-show="activeTab === 'password'" class="mt-6">
            <Card :padded="false">
                <form @submit.prevent="submitPassword">
                    <div class="px-5 pt-5">
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('settings.account.changePassword') }}</h3>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-1 gap-5">
                            <div>
                                <label for="current_password" :class="fieldLabel">{{ t('settings.account.currentPassword') }}</label>
                                <input
                                    id="current_password"
                                    v-model="passwordForm.current_password"
                                    type="password"
                                    :class="fieldInput"
                                    required
                                    autocomplete="current-password"
                                />
                                <p v-if="passwordForm.errors.current_password" :class="fieldError">{{ passwordForm.errors.current_password }}</p>
                            </div>
                            <div>
                                <label for="password" :class="fieldLabel">{{ t('settings.account.newPassword') }}</label>
                                <input
                                    id="password"
                                    v-model="passwordForm.password"
                                    type="password"
                                    :class="fieldInput"
                                    required
                                    autocomplete="new-password"
                                />
                                <p v-if="passwordForm.errors.password" :class="fieldError">{{ passwordForm.errors.password }}</p>
                            </div>
                            <div>
                                <label for="password_confirmation" :class="fieldLabel">{{ t('settings.account.confirmPassword') }}</label>
                                <input
                                    id="password_confirmation"
                                    v-model="passwordForm.password_confirmation"
                                    type="password"
                                    :class="fieldInput"
                                    required
                                    autocomplete="new-password"
                                />
                                <p v-if="passwordForm.errors.password_confirmation" :class="fieldError">{{ passwordForm.errors.password_confirmation }}</p>
                            </div>
                        </div>

                        <div class="mt-6 flex justify-end">
                            <Button type="submit" variant="default" :loading="passwordForm.processing" :disabled="passwordForm.processing">
                                {{ t('settings.account.updatePassword') }}
                            </Button>
                        </div>
                    </div>
                </form>
            </Card>
        </div>

        <!-- Notifications Tab -->
        <div v-show="activeTab === 'notifications'" class="mt-6">
            <Card :padded="false">
                <form @submit.prevent="submitNotifications">
                    <div class="px-5 pt-5">
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('settings.account.notificationPreferences') }}</h3>
                    </div>
                    <div class="p-5">
                        <div class="divide-y divide-border-subtle">
                            <div
                                v-for="toggle in toggles"
                                :key="toggle.key"
                                class="flex items-start justify-between gap-4 py-4 first:pt-0 last:pb-0"
                            >
                                <div class="min-w-0">
                                    <label :for="toggle.key" class="block text-sm font-medium text-text-primary">
                                        {{ t(toggle.label) }}
                                    </label>
                                    <p class="mt-0.5 text-sm text-text-secondary">{{ t(toggle.desc) }}</p>
                                </div>
                                <button
                                    :id="toggle.key"
                                    type="button"
                                    role="switch"
                                    :aria-checked="notificationForm[toggle.key]"
                                    @click="notificationForm[toggle.key] = !notificationForm[toggle.key]"
                                    :class="[
                                        'relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors ds-focus-ring',
                                        notificationForm[toggle.key] ? 'bg-brand' : 'bg-surface-sunken border border-border-subtle'
                                    ]"
                                >
                                    <span
                                        :class="[
                                            'inline-block h-3.5 w-3.5 transform rounded-full bg-surface-raised shadow-xs transition-transform',
                                            notificationForm[toggle.key] ? 'translate-x-4' : 'translate-x-1'
                                        ]"
                                    />
                                </button>
                            </div>
                        </div>

                        <div class="mt-6 flex justify-end">
                            <Button type="submit" variant="default" :loading="notificationForm.processing" :disabled="notificationForm.processing">
                                {{ t('settings.account.savePreferences') }}
                            </Button>
                        </div>
                    </div>
                </form>
            </Card>
        </div>

        <!-- Preferences Tab -->
        <div v-show="activeTab === 'preferences'" class="mt-6">
            <Card :padded="false">
                <form @submit.prevent="submitPreferences">
                    <div class="px-5 pt-5">
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('settings.account.userPreferences') }}</h3>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                            <div>
                                <label for="theme" :class="fieldLabel">{{ t('settings.account.theme') }}</label>
                                <select
                                    id="theme"
                                    v-model="preferencesForm.theme"
                                    :class="fieldInput"
                                >
                                    <option value="light">{{ t('settings.account.light') }}</option>
                                    <option value="dark">{{ t('settings.account.dark') }}</option>
                                    <option value="auto">{{ t('settings.account.auto') }}</option>
                                </select>
                                <p v-if="preferencesForm.errors.theme" :class="fieldError">{{ preferencesForm.errors.theme }}</p>
                            </div>
                            <div>
                                <label for="language" :class="fieldLabel">{{ t('settings.account.language') }}</label>
                                <select
                                    id="language"
                                    v-model="preferencesForm.language"
                                    :class="fieldInput"
                                >
                                    <option value="en">English</option>
                                    <option value="es">Spanish</option>
                                    <option value="fr">French</option>
                                </select>
                                <p v-if="preferencesForm.errors.language" :class="fieldError">{{ preferencesForm.errors.language }}</p>
                            </div>
                            <div>
                                <label for="items_per_page" :class="fieldLabel">{{ t('settings.account.itemsPerPage') }}</label>
                                <input
                                    id="items_per_page"
                                    v-model="preferencesForm.items_per_page"
                                    type="number"
                                    :class="fieldInput"
                                    min="10"
                                    max="100"
                                />
                                <p v-if="preferencesForm.errors.items_per_page" :class="fieldError">{{ preferencesForm.errors.items_per_page }}</p>
                            </div>
                        </div>

                        <div class="mt-6 flex justify-end">
                            <Button type="submit" variant="default" :loading="preferencesForm.processing" :disabled="preferencesForm.processing">
                                {{ t('settings.account.savePreferences') }}
                            </Button>
                        </div>
                    </div>
                </form>
            </Card>
        </div>
    </AppLayout>
</template>
