<script setup>
import PluginSlot from '@/Components/PluginSlot.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import '../../../css/settings.css';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePermissions } from '@/composables/usePermissions';
import {
    Building2,
    UserCircle,
    Bell,
    ShieldCheck,
    KeyRound,
    Mail,
    Webhook,
    Truck,
    RefreshCw,
    HardDrive,
    ChevronRight,
} from '@lucide/vue';

defineProps({
    pluginComponents: Object,
});

const { t } = useI18n();
const page = usePage();
const { hasPermission } = usePermissions();

/**
 * Every settings page. Permissions and installation administration mirror
 * the server's route guards.
 */
const allSections = computed(() => ([
    {
        href: route('settings.storage.index'),
        icon: HardDrive,
        title: t('storage.title'),
        description: t('storage.description'),
        storageOnly: true,
    },
    {
        href: route('settings.account.index'),
        icon: UserCircle,
        title: t('settings.account.title'),
        description: t('settings.hub.accountDescription'),
        group: 'account',
    },
    {
        href: route('settings.account.index', { tab: 'notifications' }),
        icon: Bell,
        title: t('nav.notifications'),
        description: t('settings.hub.notificationsDescription'),
        group: 'account',
    },
    {
        href: route('two-factor.setup'),
        icon: ShieldCheck,
        title: t('settings.hub.twoFactorTitle'),
        description: t('settings.hub.twoFactorDescription'),
        group: 'account',
    },
    {
        href: route('settings.api-tokens.index'),
        icon: KeyRound,
        title: t('settings.apiTokens.title'),
        description: t('settings.apiTokens.hubDescription'),
        group: 'account',
    },
    {
        href: route('settings.organization.index'),
        icon: Building2,
        title: t('settings.organization.title'),
        description: t('settings.organization.description'),
        perm: 'view_settings',
    },
    {
        href: route('settings.email.index'),
        icon: Mail,
        title: t('settings.email.title'),
        description: t('settings.hub.emailDescription'),
        perm: 'manage_organization',
    },
    {
        href: route('settings.shipping.index'),
        icon: Truck,
        title: t('shipping.settings.title'),
        description: t('shipping.settings.description'),
        perm: 'manage_organization',
    },
    {
        href: route('webhooks.index'),
        icon: Webhook,
        title: t('settings.webhooks.title'),
        description: t('settings.hub.webhooksDescription'),
        perm: 'manage_organization',
    },
    {
        href: route('admin.update.index'),
        icon: RefreshCw,
        title: t('nav.updates'),
        description: t('settings.hub.updatesDescription'),
        perm: 'manage_organization',
        updatesOnly: true,
    },
]));

const settingsSections = computed(() =>
    allSections.value.filter((s) => (!s.perm || hasPermission(s.perm)) && (!s.updatesOnly || page.props.auth?.canManageUpdates) && (!s.storageOnly || page.props.auth?.canManageStorage))
);

const settingsGroups = computed(() => [
    { key: 'account', title: t('nav.account'), sections: settingsSections.value.filter(section => section.group === 'account') },
    { key: 'workspace', title: t('nav.sections.workspace'), sections: settingsSections.value.filter(section => section.group !== 'account') },
].filter(group => group.sections.length));
</script>

<template>
    <Head :title="t('settings.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('settings.title') }}</span>
            </div>
        </template>

        <div class="settings-workspace">
        <PageHeader
            :title="t('settings.title')"
            :description="t('settings.description')"
        />

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <div class="settings-directory">
            <section v-for="group in settingsGroups" :key="group.key" class="settings-directory-group" :aria-labelledby="`settings-group-${group.key}`">
                <h2 :id="`settings-group-${group.key}`" class="settings-group-title">{{ group.title }}</h2>
                <div class="settings-directory-list">
                    <Link
                        v-for="section in group.sections"
                        :key="section.href"
                        :href="section.href"
                        class="settings-directory-link ds-focus-ring"
                    >
                        <component :is="section.icon" :size="18" class="settings-directory-icon" aria-hidden="true" />
                        <div class="settings-directory-copy">
                            <h3>{{ section.title }}</h3>
                            <p>{{ section.description }}</p>
                        </div>
                        <ChevronRight :size="15" class="settings-directory-chevron" aria-hidden="true" />
                    </Link>
                </div>
            </section>
        </div>

        <div class="settings-plugin-sections">
            <PluginSlot slot="sections" :components="pluginComponents?.sections" />
        </div>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
        </div>
    </AppLayout>
</template>
