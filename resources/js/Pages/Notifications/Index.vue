<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import { displayDateTime } from '@/lib/dates';
import {
    AlertTriangle,
    AlertCircle,
    ClipboardList,
    RefreshCw,
    Truck,
    PackageCheck,
    Bell,
    ArrowRight,
} from '@lucide/vue';

const props = defineProps({
    notifications: Object,
    stats: Object,
    currentFilter: String,
});

const { t } = useI18n();
const pendingAction = ref(null);
const actionError = ref(null);

const visitOptions = (action) => {
    const activeElement = document.activeElement;
    pendingAction.value = action;
    actionError.value = null;
    const showError = () => {
        actionError.value = action.startsWith('filter:') ? 'notifications.loadFailed' : 'notifications.actionFailed';
        return false;
    };
    return {
        preserveState: true,
        preserveScroll: true,
        onError: showError,
        onHttpException: showError,
        onNetworkError: showError,
        onFinish: async () => {
            pendingAction.value = null;
            await nextTick();
            if (actionError.value && activeElement instanceof HTMLElement && activeElement.isConnected) activeElement.focus();
        },
    };
};

const filterNotifications = (filter) => {
    if (pendingAction.value || currentFilterMatches(filter)) return;
    router.get(route('notifications.index'), { filter }, visitOptions(`filter:${filter}`));
};

const markAsRead = (notification) => {
    if (pendingAction.value) return;
    router.post(route('notifications.mark-as-read', notification.id), {}, visitOptions(`read:${notification.id}`));
};

const viewNotification = (notification) => {
    if (pendingAction.value) return;
    // The read endpoint redirects to action_url after the write succeeds.
    // Starting a Link visit at the same time would cancel that write.
    if (!notification.read_at) markAsRead(notification);
    else router.visit(notification.action_url);
};

const markAllAsRead = () => {
    if (pendingAction.value) return;
    if (confirm(t('notifications.confirmMarkAllRead'))) {
        router.post(route('notifications.mark-all-read'), {}, visitOptions('all'));
    }
};

const deleteNotification = (notification) => {
    if (pendingAction.value) return;
    if (confirm(t('notifications.confirmDelete'))) {
        router.delete(route('notifications.destroy', notification.id), visitOptions(`delete:${notification.id}`));
    }
};

const clearRead = () => {
    if (pendingAction.value) return;
    if (confirm(t('notifications.confirmClearRead'))) {
        router.delete(route('notifications.clear-read'), visitOptions('clear'));
    }
};

const getTypeIcon = (type) => {
    const icons = {
        'low_stock': AlertTriangle,
        'out_of_stock': AlertCircle,
        'order_created': ClipboardList,
        'order_status_updated': RefreshCw,
        'order_shipped': Truck,
        'order_delivered': PackageCheck,
    };
    return icons[type] || icons.order_created;
};

const getTypeColor = (type) => {
    const colors = {
        'low_stock': 'bg-status-warning-soft text-status-warning',
        'out_of_stock': 'bg-status-danger-soft text-status-danger',
        'order_created': 'bg-status-info-soft text-status-info',
        'order_status_updated': 'bg-brand-soft text-brand',
        'order_shipped': 'bg-status-info-soft text-status-info',
        'order_delivered': 'bg-status-success-soft text-status-success',
    };
    return colors[type] || colors.order_created;
};

const getPriorityVariant = (priority) => {
    const variants = {
        'low': 'neutral',
        'normal': 'info',
        'high': 'warning',
        'urgent': 'danger',
    };
    return variants[priority] || variants.normal;
};

const formatDate = (date) => {
    return displayDateTime(date);
};

const filterTabClass = (filter) => [
    'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
    currentFilterMatches(filter)
        ? 'bg-surface-overlay text-text-primary'
        : 'text-text-secondary hover:bg-surface-overlay hover:text-text-primary',
];

const currentFilterMatches = (filter) => props.currentFilter === filter;
</script>

<template>
    <Head :title="t('notifications.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('nav.notifications') }}</span>
            </div>
        </template>

        <PageHeader :title="t('nav.notifications')" :description="t('notifications.subtitle')">
            <template #actions>
                <Button v-if="stats.unread > 0" variant="default" size="sm" :disabled="!!pendingAction" :loading="pendingAction === 'all'" @click="markAllAsRead">
                    {{ t('notifications.markAllRead') }}
                </Button>
                <Button v-if="stats.read > 0" variant="secondary" size="sm" :disabled="!!pendingAction" :loading="pendingAction === 'clear'" @click="clearRead">
                    {{ t('notifications.clearRead') }}
                </Button>
            </template>
        </PageHeader>

        <p v-if="actionError" role="alert" class="mt-4 rounded-lg border border-status-danger/20 bg-status-danger-soft px-4 py-3 text-sm text-status-danger">
            {{ t(actionError) }}
        </p>

        <!-- Stats Cards -->
        <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
            <Card>
                <p class="text-xs font-medium text-text-tertiary">{{ t('common.total') }}</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-text-primary">{{ stats.total }}</p>
            </Card>
            <Card>
                <p class="text-xs font-medium text-text-tertiary">{{ t('notifications.unread') }}</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-brand">{{ stats.unread }}</p>
            </Card>
            <Card>
                <p class="text-xs font-medium text-text-tertiary">{{ t('notifications.read') }}</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-text-primary">{{ stats.read }}</p>
            </Card>
        </div>

        <!-- Filter Tabs -->
        <div role="group" :aria-label="t('common.filters')" :aria-busy="pendingAction?.startsWith('filter:') || undefined" class="mt-4 inline-flex max-w-full flex-wrap items-center gap-1 rounded-lg border border-border-subtle bg-surface-raised p-1">
            <button type="button" :class="filterTabClass('all')" :aria-pressed="currentFilterMatches('all')" :disabled="!!pendingAction" @click="filterNotifications('all')">
                {{ t('notifications.allCount', { count: stats.total }) }}
            </button>
            <button type="button" :class="filterTabClass('unread')" :aria-pressed="currentFilterMatches('unread')" :disabled="!!pendingAction" @click="filterNotifications('unread')">
                {{ t('notifications.unreadCount', { count: stats.unread }) }}
            </button>
            <button type="button" :class="filterTabClass('read')" :aria-pressed="currentFilterMatches('read')" :disabled="!!pendingAction" @click="filterNotifications('read')">
                {{ t('notifications.readCount', { count: stats.read }) }}
            </button>
        </div>

        <!-- Notifications List -->
        <Card :padded="false" class="mt-4 overflow-hidden" :aria-busy="!!pendingAction">
            <div v-if="notifications.data.length > 0" class="divide-y divide-border-subtle">
                <article
                    v-for="notification in notifications.data"
                    :key="notification.id"
                    :class="[
                        'p-5 transition-colors hover:bg-surface-overlay',
                        !notification.read_at && 'bg-brand-soft/40',
                    ]"
                >
                    <div class="flex items-start gap-3 sm:gap-4">
                        <!-- Icon -->
                        <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', getTypeColor(notification.type)]">
                            <component :is="getTypeIcon(notification.type)" :size="18" />
                        </div>

                        <!-- Content -->
                        <div class="min-w-0 flex-1">
                            <div class="mb-2 flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <h2 class="break-words text-sm font-semibold text-text-primary">
                                        {{ notification.title }}
                                    </h2>
                                    <p class="mt-1 break-words text-sm text-text-secondary">
                                        {{ notification.message }}
                                    </p>
                                </div>
                                <Badge :variant="getPriorityVariant(notification.priority)" size="sm" class="shrink-0 capitalize">
                                    {{ notification.priority && t(`notifications.priorities.${notification.priority}`) }}
                                </Badge>
                            </div>

                            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1">
                                <p class="text-xs text-text-tertiary">
                                    {{ formatDate(notification.created_at) }}
                                </p>
                                <Badge v-if="!notification.read_at" variant="brand" size="sm" dot>
                                    {{ t('notifications.new') }}
                                </Badge>
                            </div>

                            <!-- Actions -->
                            <div class="mt-4 flex flex-wrap items-center gap-2">
                                <Button
                                    v-if="notification.action_url"
                                    variant="link"
                                    size="sm"
                                    :disabled="!!pendingAction"
                                    :loading="pendingAction === `read:${notification.id}`"
                                    @click="viewNotification(notification)"
                                >
                                    {{ t('notifications.viewDetailsLabel') }}
                                    <ArrowRight :size="14" />
                                </Button>
                                <Button
                                    v-if="!notification.read_at"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="!!pendingAction"
                                    :loading="pendingAction === `read:${notification.id}`"
                                    @click="markAsRead(notification)"
                                >
                                    {{ t('notifications.markAsRead') }}
                                </Button>
                                <Button variant="ghost" size="sm" :disabled="!!pendingAction" :loading="pendingAction === `delete:${notification.id}`" @click="deleteNotification(notification)">
                                    {{ t('common.delete') }}
                                </Button>
                            </div>
                        </div>
                    </div>
                </article>
            </div>

            <!-- Empty State -->
            <div v-else class="flex flex-col items-center gap-3 px-6 py-16 text-center">
                <Bell :size="28" class="text-text-tertiary" />
                <p class="text-sm font-medium text-text-primary">{{ t('notifications.noNotifications') }}</p>
                <p v-if="currentFilter !== 'read'" class="text-sm text-text-tertiary">{{ t('notifications.allCaughtUp') }}</p>
            </div>

            <!-- Pagination -->
            <div v-if="notifications.links && notifications.links.length > 3" class="border-t border-border-subtle px-5 py-4">
                <nav :aria-label="t('common.pagination')" class="inline-flex w-full flex-wrap items-center justify-center gap-1">
                    <template v-for="(link, index) in notifications.links" :key="index">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            :aria-current="link.active ? 'page' : undefined"
                            :class="[
                                'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-colors',
                                link.active
                                    ? 'border-brand bg-brand text-brand-foreground'
                                    : 'border-border-subtle bg-surface-canvas text-text-secondary hover:bg-surface-overlay',
                            ]"
                            v-html="link.label"
                        />
                        <span role="link" aria-disabled="true"
                            v-else
                            class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-2.5 text-xs text-text-tertiary"
                            v-html="link.label"
                        />
                    </template>
                </nav>
            </div>
        </Card>
    </AppLayout>
</template>
