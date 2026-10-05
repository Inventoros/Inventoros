<script setup>
import PluginSlot from '@/Components/PluginSlot.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
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
    ChevronRight,
} from 'lucide-vue-next';

defineProps({
    pluginComponents: Object,
});

const { t } = useI18n();
const page = usePage();
const { hasPermission } = usePermissions();

const isAdmin = computed(() => page.props.auth?.user?.role === 'admin');

/**
 * Every settings page. `perm` mirrors the route middleware; `adminOnly`
 * mirrors controllers that additionally require the admin role.
 */
const allSections = [
    {
        href: route('settings.account.index'),
        icon: UserCircle,
        title: t('settings.account.title'),
        description: t('settings.hub.accountDescription'),
    },
    {
        href: route('settings.account.index', { tab: 'notifications' }),
        icon: Bell,
        title: t('nav.notifications'),
        description: t('settings.hub.notificationsDescription'),
    },
    {
        href: route('two-factor.setup'),
        icon: ShieldCheck,
        title: t('settings.hub.twoFactorTitle'),
        description: t('settings.hub.twoFactorDescription'),
    },
    {
        href: route('settings.api-tokens.index'),
        icon: KeyRound,
        title: t('settings.apiTokens.title'),
        description: t('settings.apiTokens.hubDescription'),
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
        adminOnly: true,
    },
];

const settingsSections = computed(() =>
    allSections.filter((s) => (!s.perm || hasPermission(s.perm)) && (!s.adminOnly || isAdmin.value))
);
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

        <PageHeader
            :title="t('settings.title')"
            :description="t('settings.description')"
        />

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <!-- Settings sections -->
        <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="section in settingsSections"
                :key="section.href"
                :href="section.href"
            >
                <Card hoverable>
                    <div class="flex items-start gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand">
                            <component :is="section.icon" :size="18" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-sm font-semibold text-text-primary">{{ section.title }}</h3>
                            <p class="mt-1 text-sm text-text-secondary">{{ section.description }}</p>
                        </div>
                        <ChevronRight :size="16" class="mt-0.5 shrink-0 text-text-tertiary" />
                    </div>
                </Card>
            </Link>

            <!-- Plugin Slot: Sections (each component is one card in the grid) -->
            <PluginSlot slot="sections" :components="pluginComponents?.sections" />
        </div>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
    </AppLayout>
</template>
