<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Button from '@/Components/ui/Button.vue';
import '../../../../css/settings.css';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { availableLocales } from '@/i18n';
import { UserCircle, KeyRound, Bell, SlidersHorizontal } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    user: Object,
});

// Deep-linkable tabs (e.g. the settings hub links to ?tab=notifications).
const tabs = ['profile', 'password', 'notifications', 'preferences'];
const requestedTab = new URLSearchParams(window.location.search).get('tab');
const activeTab = ref(tabs.includes(requestedTab) ? requestedTab : 'profile');
const tabId = `account-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;
const tabButtons = ref({});
const verticalTabs = ref(false);
let tabMedia;
const updateTabOrientation = () => { verticalTabs.value = tabMedia.matches; };
onMounted(() => {
    tabMedia = window.matchMedia('(min-width: 1024px)');
    updateTabOrientation();
    tabMedia.addEventListener('change', updateTabOrientation);
});
onBeforeUnmount(() => tabMedia?.removeEventListener('change', updateTabOrientation));
const tabItems = computed(() => [
    { key: 'profile', label: t('settings.account.profile'), icon: UserCircle },
    { key: 'password', label: t('settings.account.password'), icon: KeyRound },
    { key: 'notifications', label: t('settings.account.notifications'), icon: Bell },
    { key: 'preferences', label: t('settings.account.preferences'), icon: SlidersHorizontal },
]);
const handleTabKeydown = async (event) => {
    if (event.altKey || event.ctrlKey || event.metaKey) return;
    if (!['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const rtl = document.documentElement.dir === 'rtl';
    let offset = ['ArrowUp', 'ArrowLeft'].includes(event.key) ? -1 : 1;
    if (['ArrowRight', 'ArrowLeft'].includes(event.key) && rtl) offset *= -1;
    if (!verticalTabs.value && ['ArrowDown', 'ArrowUp'].includes(event.key)) offset *= 2;
    const index = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (tabs.indexOf(activeTab.value) + offset + tabs.length) % tabs.length;
    activeTab.value = tabs[index];
    await nextTick();
    tabButtons.value[activeTab.value]?.focus();
};

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
    // Admin-only, opt-in: email about new users, promotions to admin, and
    // repeated failed sign-ins.
    user_activity_alerts: notificationPrefs.user_activity_alerts ?? false,
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
    language: userPrefs.language || usePage().props.locale || 'en',
    items_per_page: userPrefs.items_per_page || 25,
});

const submitPreferences = () => {
    preferencesForm.patch(route('settings.account.update.preferences'), {
        preserveScroll: true,
    });
};

const fieldLabel = 'settings-field-label';
const fieldInput = 'settings-field-input ds-focus-ring';
const fieldError = 'settings-field-error';

const toggles = [
    { key: 'email_notifications', label: 'settings.account.emailNotifications', desc: 'settings.account.emailNotificationsDesc' },
    { key: 'low_stock_alerts', label: 'settings.account.lowStockAlerts', desc: 'settings.account.lowStockAlertsDesc' },
    { key: 'order_notifications', label: 'settings.account.orderNotifications', desc: 'settings.account.orderNotificationsDesc' },
    { key: 'system_notifications', label: 'settings.account.systemNotifications', desc: 'settings.account.systemNotificationsDesc' },
    ...(props.user.role === 'admin'
        ? [{ key: 'user_activity_alerts', label: 'settings.account.userActivityAlerts', desc: 'settings.account.userActivityAlertsDesc' }]
        : []),
];
</script>

<template>
    <Head :title="t('settings.account.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('settings.account.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('settings.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.settings') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('nav.account') }}</span>
            </div>
        </template>

        <div class="settings-workspace">
        <PageHeader :title="t('settings.account.title')" :description="t('settings.account.description')" />

        <!-- Tabs -->
        <div class="settings-account-shell">
            <nav class="settings-section-nav" role="tablist" :aria-label="t('settings.account.title')" :aria-orientation="verticalTabs ? 'vertical' : 'horizontal'" @keydown="handleTabKeydown">
                <button v-for="tab in tabItems" :key="tab.key" :ref="element => tabButtons[tab.key] = element" :id="`${tabId}-tab-${tab.key}`" type="button" role="tab" :aria-selected="activeTab === tab.key" :aria-controls="`${tabId}-panel-${tab.key}`" :tabindex="activeTab === tab.key ? 0 : -1" class="settings-section-tab ds-focus-ring" :class="{ 'is-active': activeTab === tab.key }" @click="activeTab = tab.key">
                    <component :is="tab.icon" :size="16" aria-hidden="true" />
                    <span>{{ tab.label }}</span>
                </button>
            </nav>

        <div class="settings-account-panels">

        <!-- Profile Tab -->
        <div v-show="activeTab === 'profile'" :id="`${tabId}-panel-profile`" role="tabpanel" :aria-labelledby="`${tabId}-tab-profile`" class="settings-account-panel">
            <section class="settings-form-surface">
                <form @submit.prevent="submitProfile">
                    <div class="settings-form-heading">
                        <h2>{{ t('settings.account.profileInfo') }}</h2>
                    </div>
                    <div class="settings-form-body">
                        <div class="settings-fields">
                            <div class="settings-field">
                                <label for="name" :class="fieldLabel">{{ t('common.name') }}</label>
                                <input
                                    id="name"
                                    :aria-invalid="!!profileForm.errors.name"
                                    :aria-describedby="profileForm.errors.name ? 'account-name-error' : undefined"
                                    v-model="profileForm.name"
                                    type="text"
                                    autocomplete="name"
                                    :class="fieldInput"
                                    required
                                />
                                <p v-if="profileForm.errors.name" id="account-name-error" :class="fieldError" role="alert">{{ profileForm.errors.name }}</p>
                            </div>
                            <div class="settings-field">
                                <label for="email" :class="fieldLabel">{{ t('common.email') }}</label>
                                <input
                                    id="email"
                                    :aria-invalid="!!profileForm.errors.email"
                                    :aria-describedby="profileForm.errors.email ? 'account-email-error' : undefined"
                                    v-model="profileForm.email"
                                    type="email"
                                    autocomplete="email"
                                    :class="fieldInput"
                                    required
                                />
                                <p v-if="profileForm.errors.email" id="account-email-error" :class="fieldError" role="alert">{{ profileForm.errors.email }}</p>
                            </div>
                        </div>

                        <div class="settings-form-actions">
                            <Button type="submit" variant="default" :loading="profileForm.processing" :disabled="profileForm.processing">
                                {{ t('common.saveChanges') }}
                            </Button>
                        </div>
                    </div>
                </form>
            </section>
        </div>

        <!-- Password Tab -->
        <div v-show="activeTab === 'password'" :id="`${tabId}-panel-password`" role="tabpanel" :aria-labelledby="`${tabId}-tab-password`" class="settings-account-panel">
            <section class="settings-form-surface">
                <form @submit.prevent="submitPassword">
                    <div class="settings-form-heading">
                        <h2>{{ t('settings.account.changePassword') }}</h2>
                    </div>
                    <div class="settings-form-body">
                        <div class="settings-fields">
                            <div class="settings-field">
                                <label for="current_password" :class="fieldLabel">{{ t('settings.account.currentPassword') }}</label>
                                <input
                                    id="current_password"
                                    :aria-invalid="!!passwordForm.errors.current_password"
                                    :aria-describedby="passwordForm.errors.current_password ? 'account-current_password-error' : undefined"
                                    v-model="passwordForm.current_password"
                                    type="password"
                                    :class="fieldInput"
                                    required
                                    autocomplete="current-password"
                                />
                                <p v-if="passwordForm.errors.current_password" id="account-current_password-error" :class="fieldError" role="alert">{{ passwordForm.errors.current_password }}</p>
                            </div>
                            <div class="settings-field">
                                <label for="password" :class="fieldLabel">{{ t('settings.account.newPassword') }}</label>
                                <input
                                    id="password"
                                    :aria-invalid="!!passwordForm.errors.password"
                                    :aria-describedby="passwordForm.errors.password ? 'account-password-error' : undefined"
                                    v-model="passwordForm.password"
                                    type="password"
                                    :class="fieldInput"
                                    required
                                    autocomplete="new-password"
                                />
                                <p v-if="passwordForm.errors.password" id="account-password-error" :class="fieldError" role="alert">{{ passwordForm.errors.password }}</p>
                            </div>
                            <div class="settings-field">
                                <label for="password_confirmation" :class="fieldLabel">{{ t('settings.account.confirmPassword') }}</label>
                                <input
                                    id="password_confirmation"
                                    :aria-invalid="!!passwordForm.errors.password_confirmation"
                                    :aria-describedby="passwordForm.errors.password_confirmation ? 'account-password_confirmation-error' : undefined"
                                    v-model="passwordForm.password_confirmation"
                                    type="password"
                                    :class="fieldInput"
                                    required
                                    autocomplete="new-password"
                                />
                                <p v-if="passwordForm.errors.password_confirmation" id="account-password_confirmation-error" :class="fieldError" role="alert">{{ passwordForm.errors.password_confirmation }}</p>
                            </div>
                        </div>

                        <div class="settings-form-actions">
                            <Button type="submit" variant="default" :loading="passwordForm.processing" :disabled="passwordForm.processing">
                                {{ t('settings.account.updatePassword') }}
                            </Button>
                        </div>
                    </div>
                </form>
            </section>
        </div>

        <!-- Notifications Tab -->
        <div v-show="activeTab === 'notifications'" :id="`${tabId}-panel-notifications`" role="tabpanel" :aria-labelledby="`${tabId}-tab-notifications`" class="settings-account-panel">
            <section class="settings-form-surface">
                <form @submit.prevent="submitNotifications">
                    <div class="settings-form-heading">
                        <h2>{{ t('settings.account.notificationPreferences') }}</h2>
                    </div>
                    <div class="settings-form-body">
                        <div class="settings-notification-list">
                            <div
                                v-for="toggle in toggles"
                                :key="toggle.key"
                                class="settings-notification-row"
                            >
                                <div class="min-w-0">
                                    <label :for="toggle.key" class="settings-toggle-label">
                                        {{ t(toggle.label) }}
                                    </label>
                                    <p :id="`${toggle.key}-description`" class="settings-toggle-description">{{ t(toggle.desc) }}</p>
                                    <p v-if="notificationForm.errors[toggle.key]" :class="fieldError" role="alert">{{ notificationForm.errors[toggle.key] }}</p>
                                </div>
                                <button
                                    :id="toggle.key"
                                    type="button"
                                    role="switch"
                                    :aria-checked="notificationForm[toggle.key]"
                                    :aria-describedby="`${toggle.key}-description`"
                                    @click="notificationForm[toggle.key] = !notificationForm[toggle.key]"
                                    class="settings-toggle ds-focus-ring"
                                    :class="{ 'is-on': notificationForm[toggle.key] }"
                                >
                                    <span class="settings-toggle-thumb" aria-hidden="true" />
                                </button>
                            </div>
                        </div>

                        <div class="settings-form-actions">
                            <Button type="submit" variant="default" :loading="notificationForm.processing" :disabled="notificationForm.processing">
                                {{ t('settings.account.savePreferences') }}
                            </Button>
                        </div>
                    </div>
                </form>
            </section>
        </div>

        <!-- Preferences Tab -->
        <div v-show="activeTab === 'preferences'" :id="`${tabId}-panel-preferences`" role="tabpanel" :aria-labelledby="`${tabId}-tab-preferences`" class="settings-account-panel">
            <section class="settings-form-surface">
                <form @submit.prevent="submitPreferences">
                    <div class="settings-form-heading">
                        <h2>{{ t('settings.account.userPreferences') }}</h2>
                    </div>
                    <div class="settings-form-body">
                        <div class="settings-fields">
                            <div class="settings-field">
                                <label for="theme" :class="fieldLabel">{{ t('settings.account.theme') }}</label>
                                <select
                                    id="theme"
                                    :aria-invalid="!!preferencesForm.errors.theme"
                                    :aria-describedby="preferencesForm.errors.theme ? 'account-theme-error' : undefined"
                                    v-model="preferencesForm.theme"
                                    :class="fieldInput"
                                >
                                    <option value="light">{{ t('settings.account.light') }}</option>
                                    <option value="dark">{{ t('settings.account.dark') }}</option>
                                    <option value="auto">{{ t('settings.account.auto') }}</option>
                                </select>
                                <p v-if="preferencesForm.errors.theme" id="account-theme-error" :class="fieldError" role="alert">{{ preferencesForm.errors.theme }}</p>
                            </div>
                            <div class="settings-field">
                                <label for="language" :class="fieldLabel">{{ t('settings.account.language') }}</label>
                                <select
                                    id="language"
                                    :aria-invalid="!!preferencesForm.errors.language"
                                    :aria-describedby="preferencesForm.errors.language ? 'account-language-error' : undefined"
                                    v-model="preferencesForm.language"
                                    :class="fieldInput"
                                >
                                    <option v-for="loc in availableLocales" :key="loc.code" :value="loc.code">{{ loc.name }}</option>
                                </select>
                                <p v-if="preferencesForm.errors.language" id="account-language-error" :class="fieldError" role="alert">{{ preferencesForm.errors.language }}</p>
                            </div>
                            <div class="settings-field">
                                <label for="items_per_page" :class="fieldLabel">{{ t('settings.account.itemsPerPage') }}</label>
                                <input
                                    id="items_per_page"
                                    :aria-invalid="!!preferencesForm.errors.items_per_page"
                                    :aria-describedby="preferencesForm.errors.items_per_page ? 'account-items_per_page-error' : undefined"
                                    v-model="preferencesForm.items_per_page"
                                    type="number"
                                    :class="fieldInput"
                                    min="10"
                                    max="100"
                                />
                                <p v-if="preferencesForm.errors.items_per_page" id="account-items_per_page-error" :class="fieldError" role="alert">{{ preferencesForm.errors.items_per_page }}</p>
                            </div>
                        </div>

                        <div class="settings-form-actions">
                            <Button type="submit" variant="default" :loading="preferencesForm.processing" :disabled="preferencesForm.processing">
                                {{ t('settings.account.savePreferences') }}
                            </Button>
                        </div>
                    </div>
                </form>
            </section>
        </div>
        </div>
        </div>
        </div>
    </AppLayout>
</template>
