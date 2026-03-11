<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
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
</script>

<template>
    <Head :title="t('settings.account.title')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                {{ t('settings.account.title') }}
            </h2>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Tabs -->
                <div class="mb-6 border-b border-gray-200 dark:border-dark-border">
                    <nav class="-mb-px flex space-x-8">
                        <button
                            @click="activeTab = 'profile'"
                            :class="[
                                'py-4 px-1 border-b-2 font-medium text-sm transition',
                                activeTab === 'profile'
                                    ? 'border-primary-400 text-primary-400'
                                    : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-600'
                            ]"
                        >
                            {{ t('settings.account.profile') }}
                        </button>
                        <button
                            @click="activeTab = 'password'"
                            :class="[
                                'py-4 px-1 border-b-2 font-medium text-sm transition',
                                activeTab === 'password'
                                    ? 'border-primary-400 text-primary-400'
                                    : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-600'
                            ]"
                        >
                            {{ t('settings.account.password') }}
                        </button>
                        <button
                            @click="activeTab = 'notifications'"
                            :class="[
                                'py-4 px-1 border-b-2 font-medium text-sm transition',
                                activeTab === 'notifications'
                                    ? 'border-primary-400 text-primary-400'
                                    : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-600'
                            ]"
                        >
                            {{ t('settings.account.notifications') }}
                        </button>
                        <button
                            @click="activeTab = 'preferences'"
                            :class="[
                                'py-4 px-1 border-b-2 font-medium text-sm transition',
                                activeTab === 'preferences'
                                    ? 'border-primary-400 text-primary-400'
                                    : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-600'
                            ]"
                        >
                            {{ t('settings.account.preferences') }}
                        </button>
                    </nav>
                </div>

                <!-- Profile Tab -->
                <div v-show="activeTab === 'profile'" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <form @submit.prevent="submitProfile" class="p-6 space-y-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ t('settings.account.profileInfo') }}</h3>
                            <div class="grid grid-cols-1 gap-6">
                                <div>
                                    <InputLabel for="name" :value="t('common.name')" />
                                    <TextInput
                                        id="name"
                                        v-model="profileForm.name"
                                        type="text"
                                        class="mt-1 block w-full"
                                        required
                                    />
                                    <InputError class="mt-2" :message="profileForm.errors.name" />
                                </div>
                                <div>
                                    <InputLabel for="email" :value="t('common.email')" />
                                    <TextInput
                                        id="email"
                                        v-model="profileForm.email"
                                        type="email"
                                        class="mt-1 block w-full"
                                        required
                                    />
                                    <InputError class="mt-2" :message="profileForm.errors.email" />
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <PrimaryButton :disabled="profileForm.processing">
                                {{ t('common.saveChanges') }}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>

                <!-- Password Tab -->
                <div v-show="activeTab === 'password'" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <form @submit.prevent="submitPassword" class="p-6 space-y-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ t('settings.account.changePassword') }}</h3>
                            <div class="grid grid-cols-1 gap-6">
                                <div>
                                    <InputLabel for="current_password" :value="t('settings.account.currentPassword')" />
                                    <TextInput
                                        id="current_password"
                                        v-model="passwordForm.current_password"
                                        type="password"
                                        class="mt-1 block w-full"
                                        required
                                        autocomplete="current-password"
                                    />
                                    <InputError class="mt-2" :message="passwordForm.errors.current_password" />
                                </div>
                                <div>
                                    <InputLabel for="password" :value="t('settings.account.newPassword')" />
                                    <TextInput
                                        id="password"
                                        v-model="passwordForm.password"
                                        type="password"
                                        class="mt-1 block w-full"
                                        required
                                        autocomplete="new-password"
                                    />
                                    <InputError class="mt-2" :message="passwordForm.errors.password" />
                                </div>
                                <div>
                                    <InputLabel for="password_confirmation" :value="t('settings.account.confirmPassword')" />
                                    <TextInput
                                        id="password_confirmation"
                                        v-model="passwordForm.password_confirmation"
                                        type="password"
                                        class="mt-1 block w-full"
                                        required
                                        autocomplete="new-password"
                                    />
                                    <InputError class="mt-2" :message="passwordForm.errors.password_confirmation" />
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <PrimaryButton :disabled="passwordForm.processing">
                                {{ t('settings.account.updatePassword') }}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>

                <!-- Notifications Tab -->
                <div v-show="activeTab === 'notifications'" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <form @submit.prevent="submitNotifications" class="p-6 space-y-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ t('settings.account.notificationPreferences') }}</h3>
                            <div class="space-y-4">
                                <div class="flex items-start">
                                    <div class="flex items-center h-5">
                                        <Checkbox
                                            id="email_notifications"
                                            v-model:checked="notificationForm.email_notifications"
                                        />
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="email_notifications" class="font-medium text-gray-700 dark:text-gray-300">
                                            {{ t('settings.account.emailNotifications') }}
                                        </label>
                                        <p class="text-gray-500 dark:text-gray-400">{{ t('settings.account.emailNotificationsDesc') }}</p>
                                    </div>
                                </div>

                                <div class="flex items-start">
                                    <div class="flex items-center h-5">
                                        <Checkbox
                                            id="low_stock_alerts"
                                            v-model:checked="notificationForm.low_stock_alerts"
                                        />
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="low_stock_alerts" class="font-medium text-gray-700 dark:text-gray-300">
                                            {{ t('settings.account.lowStockAlerts') }}
                                        </label>
                                        <p class="text-gray-500 dark:text-gray-400">{{ t('settings.account.lowStockAlertsDesc') }}</p>
                                    </div>
                                </div>

                                <div class="flex items-start">
                                    <div class="flex items-center h-5">
                                        <Checkbox
                                            id="order_notifications"
                                            v-model:checked="notificationForm.order_notifications"
                                        />
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="order_notifications" class="font-medium text-gray-700 dark:text-gray-300">
                                            {{ t('settings.account.orderNotifications') }}
                                        </label>
                                        <p class="text-gray-500 dark:text-gray-400">{{ t('settings.account.orderNotificationsDesc') }}</p>
                                    </div>
                                </div>

                                <div class="flex items-start">
                                    <div class="flex items-center h-5">
                                        <Checkbox
                                            id="system_notifications"
                                            v-model:checked="notificationForm.system_notifications"
                                        />
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="system_notifications" class="font-medium text-gray-700 dark:text-gray-300">
                                            {{ t('settings.account.systemNotifications') }}
                                        </label>
                                        <p class="text-gray-500 dark:text-gray-400">{{ t('settings.account.systemNotificationsDesc') }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <PrimaryButton :disabled="notificationForm.processing">
                                {{ t('settings.account.savePreferences') }}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>

                <!-- Preferences Tab -->
                <div v-show="activeTab === 'preferences'" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <form @submit.prevent="submitPreferences" class="p-6 space-y-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ t('settings.account.userPreferences') }}</h3>
                            <div class="grid grid-cols-1 gap-6">
                                <div>
                                    <InputLabel for="theme" :value="t('settings.account.theme')" />
                                    <select
                                        id="theme"
                                        v-model="preferencesForm.theme"
                                        class="mt-1 block w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                    >
                                        <option value="light">{{ t('settings.account.light') }}</option>
                                        <option value="dark">{{ t('settings.account.dark') }}</option>
                                        <option value="auto">{{ t('settings.account.auto') }}</option>
                                    </select>
                                    <InputError class="mt-2" :message="preferencesForm.errors.theme" />
                                </div>
                                <div>
                                    <InputLabel for="language" :value="t('settings.account.language')" />
                                    <select
                                        id="language"
                                        v-model="preferencesForm.language"
                                        class="mt-1 block w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                    >
                                        <option value="en">English</option>
                                        <option value="es">Spanish</option>
                                        <option value="fr">French</option>
                                    </select>
                                    <InputError class="mt-2" :message="preferencesForm.errors.language" />
                                </div>
                                <div>
                                    <InputLabel for="items_per_page" :value="t('settings.account.itemsPerPage')" />
                                    <TextInput
                                        id="items_per_page"
                                        v-model="preferencesForm.items_per_page"
                                        type="number"
                                        class="mt-1 block w-full"
                                        min="10"
                                        max="100"
                                    />
                                    <InputError class="mt-2" :message="preferencesForm.errors.items_per_page" />
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <PrimaryButton :disabled="preferencesForm.processing">
                                {{ t('settings.account.savePreferences') }}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
