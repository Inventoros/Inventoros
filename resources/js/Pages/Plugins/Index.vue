<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import Input from '@/Components/ui/Input.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatMoney } from '@/lib/money';
import { UploadCloud, Loader2, Puzzle, ExternalLink, ShieldCheck, Store } from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({
    plugins: Array,
    uploadsEnabled: { type: Boolean, default: false },
    activeTab: { type: String, default: 'installed' },
    marketplace: { type: Object, default: null },
    canAdministerPlugins: { type: Boolean, default: false },
});

const { hasPermission } = usePermissions();
const hasManagePermission = hasPermission('manage_plugins');
// Plugins are shared by the whole installation: changing them also needs the
// plugin administrator organization.
const canManage = hasManagePermission && props.canAdministerPlugins;
const managedElsewhere = hasManagePermission && !props.canAdministerPlugins;

// Only http(s) links from a plugin's manifest become clickable.
const isWebUrl = (url) => typeof url === 'string' && /^https?:\/\//i.test(url);

const tabClass = (key) => [
    'ds-focus-ring -mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors',
    props.activeTab === key
        ? 'border-brand text-text-primary'
        : 'border-transparent text-text-secondary hover:text-text-primary',
];

const connectForm = useForm({ token: '' });
const busySlug = ref(null);

const connect = () => {
    connectForm.post(route('plugins.marketplace.connect'), {
        preserveScroll: true,
        onSuccess: () => connectForm.reset(),
    });
};

const disconnect = () => {
    if (confirm(t('plugins.marketplace.confirmDisconnect'))) {
        router.delete(route('plugins.marketplace.disconnect'), { preserveScroll: true });
    }
};

const installFromMarketplace = (slug, activate) => {
    busySlug.value = slug;
    router.post(route('plugins.marketplace.install', slug), { activate }, {
        preserveScroll: true,
        onFinish: () => (busySlug.value = null),
    });
};

const updateFromMarketplace = (slug) => {
    busySlug.value = slug;
    router.post(route('plugins.marketplace.update', slug), {}, {
        preserveScroll: true,
        onFinish: () => (busySlug.value = null),
    });
};

const priceLabel = (plugin) => {
    if (plugin.is_free || plugin.pricing_type === 'free') {
        return t('plugins.marketplace.free');
    }
    const price = formatMoney(plugin.price, plugin.currency || 'USD');
    if (plugin.pricing_type === 'subscription') {
        return plugin.billing_interval === 'year'
            ? t('plugins.marketplace.perYear', { price })
            : t('plugins.marketplace.perMonth', { price });
    }
    return price;
};

const { t } = useI18n();
const uploadForm = useForm({
    plugin: null,
});

const fileInput = ref(null);
const isDragging = ref(false);

const handleFileSelect = (event) => {
    const file = event.target.files[0];
    if (file && file.name.endsWith('.zip')) {
        uploadForm.plugin = file;
        submitUpload();
    }
};

const handleDrop = (event) => {
    isDragging.value = false;
    const file = event.dataTransfer.files[0];
    if (file && file.name.endsWith('.zip')) {
        uploadForm.plugin = file;
        submitUpload();
    }
};

const submitUpload = () => {
    uploadForm.post(route('plugins.upload'), {
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset();
            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
};

const activatePlugin = (slug) => {
    router.post(route('plugins.activate', slug), {}, {
        preserveScroll: true,
    });
};

const deactivatePlugin = (slug) => {
    router.post(route('plugins.deactivate', slug), {}, {
        preserveScroll: true,
    });
};

const deletePlugin = (slug, name) => {
    if (confirm(t('plugins.confirmDelete', { name }))) {
        router.delete(route('plugins.destroy', slug), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head :title="t('nav.plugins')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('plugins.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('plugins.title')" :description="t('plugins.subtitle')" />

        <nav class="mt-6 flex gap-1 overflow-x-auto overflow-y-hidden border-b border-border-subtle" :aria-label="t('plugins.pageSections')">
            <Link
                :href="route('plugins.index')"
                :class="tabClass('installed')"
                :aria-current="activeTab === 'installed' ? 'page' : undefined"
            >
                {{ t('plugins.tabs.installed') }}
            </Link>
            <Link
                :href="route('plugins.marketplace')"
                :class="tabClass('marketplace')"
                :aria-current="activeTab === 'marketplace' ? 'page' : undefined"
            >
                {{ t('plugins.tabs.marketplace') }}
            </Link>
        </nav>

        <div v-if="activeTab === 'marketplace' && marketplace">
            <p class="mt-6 flex items-start gap-2 text-sm text-text-secondary">
                <ShieldCheck :size="16" class="mt-0.5 shrink-0 text-status-success" />
                <span>{{ t('plugins.marketplace.intro') }}</span>
            </p>

            <Card v-if="!marketplace.configured" class="mt-4">
                <p class="text-sm text-status-warning">{{ t('plugins.marketplace.notConfigured') }}</p>
            </Card>

            <p v-if="managedElsewhere" class="mt-4 text-sm text-text-tertiary">{{ t('plugins.managedElsewhere') }}</p>
            <p v-else-if="!canManage" class="mt-4 text-sm text-text-tertiary">{{ t('plugins.marketplace.readOnly') }}</p>

            <!-- Account connection -->
            <Card v-if="canManage" class="mt-4">
                <h3 class="text-sm font-semibold text-text-primary">{{ t('plugins.marketplace.account') }}</h3>
                <div v-if="marketplace.connected" class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-text-secondary">
                        {{ t('plugins.marketplace.connectedAs', { name: marketplace.account?.name ?? '', email: marketplace.account?.email ?? '' }) }}
                    </p>
                    <Button variant="secondary" size="sm" @click="disconnect">{{ t('plugins.marketplace.disconnect') }}</Button>
                </div>
                <form v-else class="mt-3" @submit.prevent="connect">
                    <p class="mb-3 text-sm text-text-secondary">{{ t('plugins.marketplace.connectHint') }}</p>
                    <div class="flex flex-wrap items-end gap-2">
                        <label class="min-w-64 flex-1">
                            <span class="mb-1 block text-xs font-medium text-text-secondary">{{ t('plugins.marketplace.tokenLabel') }}</span>
                            <Input
                                v-model="connectForm.token"
                                type="password"
                                :invalid="!!connectForm.errors.token"
                            />
                        </label>
                        <Button type="submit" size="md" :loading="connectForm.processing" :disabled="!connectForm.token">
                            {{ connectForm.processing ? t('plugins.marketplace.connecting') : t('plugins.marketplace.connect') }}
                        </Button>
                    </div>
                    <p v-if="connectForm.errors.token" class="mt-1 text-xs text-status-danger">{{ connectForm.errors.token }}</p>
                </form>
            </Card>

            <Card v-if="marketplace.error" class="mt-4">
                <p class="text-sm font-medium text-text-primary">{{ t('plugins.marketplace.unavailable') }}</p>
                <p class="mt-1 text-sm text-text-secondary">{{ marketplace.error }}</p>
            </Card>

            <!-- Catalog -->
            <div class="mt-4 space-y-4">
                <Card v-for="item in marketplace.plugins" :key="item.slug">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="flex min-w-0 flex-1 gap-3">
                            <img v-if="item.icon" :src="item.icon" alt="" class="h-10 w-10 shrink-0 rounded-md border border-border-subtle object-cover" />
                            <div v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-border-subtle bg-surface-canvas">
                                <Puzzle :size="18" class="text-text-tertiary" />
                            </div>
                            <div class="min-w-0">
                                <div class="mb-1 flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold text-text-primary">{{ item.name }}</h3>
                                    <Badge :variant="item.is_free ? 'success' : 'neutral'" size="sm">{{ priceLabel(item) }}</Badge>
                                    <Badge v-if="item.installed_version && !item.update_available" variant="neutral" size="sm" dot>
                                        {{ t('plugins.marketplace.upToDate') }}
                                    </Badge>
                                </div>
                                <p class="mb-2 text-sm text-text-secondary">{{ item.summary }}</p>
                                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-text-tertiary">
                                    <span>{{ t('plugins.marketplace.latest', { version: item.version }) }}</span>
                                    <span v-if="item.installed_version">{{ t('plugins.marketplace.installedVersion', { version: item.installed_version }) }}</span>
                                    <span v-if="item.requires">{{ t('plugins.requires') }}: {{ item.requires }}</span>
                                    <span v-if="item.author">{{ item.author }}</span>
                                    <a
                                        v-if="item.homepage"
                                        :href="item.homepage"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 text-brand transition-colors hover:text-brand-hover"
                                    >
                                        {{ t('plugins.marketplace.viewOnMarketplace') }}
                                        <ExternalLink :size="11" />
                                    </a>
                                </div>
                                <p v-if="!item.has_access" class="mt-2 text-xs text-status-warning">
                                    {{ marketplace.connected ? t('plugins.marketplace.notOwned') : t('plugins.marketplace.needsPurchase') }}
                                </p>
                            </div>
                        </div>
                        <div v-if="canManage && marketplace.configured && item.has_access" class="flex shrink-0 flex-col gap-2 sm:flex-row">
                            <template v-if="!item.installed_version">
                                <Button size="sm" :loading="busySlug === item.slug" :disabled="busySlug !== null" @click="installFromMarketplace(item.slug, true)">
                                    {{ t('plugins.marketplace.installAndActivate') }}
                                </Button>
                                <Button variant="secondary" size="sm" :disabled="busySlug !== null" @click="installFromMarketplace(item.slug, false)">
                                    {{ t('plugins.marketplace.install') }}
                                </Button>
                            </template>
                            <Button
                                v-else-if="item.update_available"
                                size="sm"
                                :loading="busySlug === item.slug"
                                :disabled="busySlug !== null"
                                @click="updateFromMarketplace(item.slug)"
                            >
                                {{ t('plugins.marketplace.update', { version: item.version }) }}
                            </Button>
                        </div>
                    </div>
                </Card>

                <Card v-if="!marketplace.error && marketplace.plugins.length === 0">
                    <div class="flex flex-col items-center gap-3 py-12 text-center">
                        <Store :size="28" class="text-text-tertiary" />
                        <p class="text-sm text-text-tertiary">{{ t('plugins.marketplace.empty') }}</p>
                    </div>
                </Card>
            </div>
        </div>

        <div v-else>
        <Card v-if="managedElsewhere" class="mt-6">
            <p class="text-sm text-text-secondary">{{ t('plugins.managedElsewhere') }}</p>
        </Card>
        <Card v-else-if="canManage && !uploadsEnabled" class="mt-6">
            <div class="flex items-start gap-2 text-sm text-text-secondary">
                <UploadCloud :size="16" class="mt-0.5 shrink-0 text-text-tertiary" />
                <p>
                    {{ t('plugins.uploadsDisabled') }}
                    <Link :href="route('plugins.marketplace')" class="ds-focus-ring font-medium text-brand hover:text-brand-hover">{{ t('plugins.browseMarketplace') }}</Link>
                </p>
            </div>
        </Card>

        <!-- Upload Section -->
        <Card v-else-if="canManage" class="mt-6">
            <h3 class="text-sm font-semibold text-text-primary">{{ t('plugins.uploadPlugin') }}</h3>
            <div
                @dragover.prevent="isDragging = true"
                @dragleave.prevent="isDragging = false"
                @drop.prevent="handleDrop"
                :class="[
                    'mt-4 rounded-lg border-2 border-dashed p-8 text-center transition-colors',
                    isDragging
                        ? 'border-brand bg-brand-soft'
                        : 'border-border-subtle bg-surface-canvas',
                ]"
            >
                <UploadCloud :size="40" class="mx-auto text-text-tertiary" />
                <div class="mt-4 text-sm">
                    <label
                        for="plugin-upload"
                        class="cursor-pointer font-medium text-brand transition-colors hover:text-brand-hover"
                    >
                        {{ t('plugins.chooseZip') }}
                    </label>
                    <span class="text-text-secondary"> {{ t('plugins.dragDrop') }}</span>
                    <input
                        ref="fileInput"
                        id="plugin-upload"
                        type="file"
                        accept=".zip"
                        class="hidden"
                        @change="handleFileSelect"
                    />
                </div>
                <p class="mt-2 text-xs text-text-tertiary">{{ t('plugins.zipOnly') }}</p>
            </div>
            <div v-if="uploadForm.processing" class="mt-4 text-center">
                <div class="inline-flex items-center gap-2 rounded-md bg-brand-soft px-4 py-2 text-sm text-brand">
                    <Loader2 :size="16" class="animate-spin" />
                    <span>{{ t('plugins.uploading') }}</span>
                </div>
            </div>
        </Card>

        <!-- Plugins List -->
        <div class="mt-6 space-y-4">
            <Card v-for="plugin in plugins" :key="plugin.slug">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="mb-2 flex items-center gap-3">
                            <h3 class="text-base font-semibold text-text-primary">
                                {{ plugin.name }}
                            </h3>
                            <Badge :variant="plugin.is_active ? 'success' : 'neutral'" size="sm" dot>
                                {{ plugin.is_active ? t('common.active') : t('common.inactive') }}
                            </Badge>
                        </div>
                        <p class="mb-3 text-sm text-text-secondary">
                            {{ plugin.description }}
                        </p>
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-text-tertiary">
                            <span>{{ t('plugins.versionLabel', { version: plugin.version }) }}</span>
                            <span class="inline-flex items-center gap-1">{{ t('plugins.authorLabel') }}
                                <a
                                    v-if="isWebUrl(plugin.author_url)"
                                    :href="plugin.author_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 text-brand transition-colors hover:text-brand-hover"
                                >
                                    {{ plugin.author }}
                                    <ExternalLink :size="11" />
                                </a>
                                <span v-else>{{ plugin.author }}</span>
                            </span>
                            <span>{{ t('plugins.requiresLabel', { requires: plugin.requires }) }}</span>
                        </div>
                    </div>
                    <div v-if="canManage" class="flex shrink-0 flex-wrap gap-2">
                        <Button
                            v-if="!plugin.is_active"
                            variant="default"
                            size="sm"
                            @click="activatePlugin(plugin.slug)"
                        >
                            {{ t('plugins.activate') }}
                        </Button>
                        <Button
                            v-else
                            variant="secondary"
                            size="sm"
                            @click="deactivatePlugin(plugin.slug)"
                        >
                            {{ t('plugins.deactivate') }}
                        </Button>
                        <Button
                            variant="danger"
                            size="sm"
                            :disabled="plugin.is_active"
                            @click="deletePlugin(plugin.slug, plugin.name)"
                        >
                            {{ t('common.delete') }}
                        </Button>
                    </div>
                </div>
            </Card>

            <!-- Empty State -->
            <Card v-if="plugins.length === 0">
                <div class="flex flex-col items-center gap-3 py-12 text-center">
                    <Puzzle :size="28" class="text-text-tertiary" />
                    <h3 class="text-sm font-medium text-text-primary">{{ t('plugins.noPlugins') }}</h3>
                    <p class="text-sm text-text-tertiary">
                        {{ t('plugins.getStarted') }}
                    </p>
                </div>
            </Card>
        </div>
        </div>
    </AppLayout>
</template>
