<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    AlertTriangle,
    AlertCircle,
    ClipboardList,
    RefreshCw,
    Truck,
    PackageCheck,
    Bell,
    ArrowRight,
} from 'lucide-vue-next';

const props = defineProps({
    notifications: Object,
    stats: Object,
    currentFilter: String,
});

const { t } = useI18n();

const filterNotifications = (filter) => {
    router.get(route('notifications.index'), { filter }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const markAsRead = (notification) => {
    router.post(route('notifications.mark-as-read', notification.id), {}, {
        preserveState: true,
        preserveScroll: true,
    });
};

const markAllAsRead = () => {
    if (confirm('Mark all notifications as read?')) {
        router.post(route('notifications.mark-all-read'), {}, {
            preserveState: true,
            preserveScroll: true,
        });
    }
};

const deleteNotification = (notification) => {
    if (confirm('Delete this notification?')) {
        router.delete(route('notifications.destroy', notification.id), {
            preserveState: true,
            preserveScroll: true,
        });
    }
};

const clearRead = () => {
    if (confirm('Clear all read notifications?')) {
        router.delete(route('notifications.clear-read'), {
            preserveState: true,
            preserveScroll: true,
        });
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
    return new Date(date).toLocaleString();
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
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('nav.notifications') }}</span>
            </div>
        </template>

        <PageHeader :title="t('nav.notifications')" description="Manage your notifications and alerts.">
            <template #actions>
                <Button v-if="stats.unread > 0" variant="default" size="sm" @click="markAllAsRead">
                    Mark All Read
                </Button>
                <Button v-if="stats.read > 0" variant="secondary" size="sm" @click="clearRead">
                    Clear Read
                </Button>
            </template>
        </PageHeader>

        <!-- Stats Cards -->
        <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
            <Card>
                <p class="text-xs font-medium text-text-tertiary">{{ t('common.total') }}</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-text-primary">{{ stats.total }}</p>
            </Card>
            <Card>
                <p class="text-xs font-medium text-text-tertiary">Unread</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-brand">{{ stats.unread }}</p>
            </Card>
            <Card>
                <p class="text-xs font-medium text-text-tertiary">Read</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-text-primary">{{ stats.read }}</p>
            </Card>
        </div>

        <!-- Filter Tabs -->
        <div class="mt-4 inline-flex items-center gap-1 rounded-lg border border-border-subtle bg-surface-raised p-1">
            <button type="button" :class="filterTabClass('all')" @click="filterNotifications('all')">
                All ({{ stats.total }})
            </button>
            <button type="button" :class="filterTabClass('unread')" @click="filterNotifications('unread')">
                Unread ({{ stats.unread }})
            </button>
            <button type="button" :class="filterTabClass('read')" @click="filterNotifications('read')">
                Read ({{ stats.read }})
            </button>
        </div>

        <!-- Notifications List -->
        <Card :padded="false" class="mt-4 overflow-hidden">
            <div v-if="notifications.data.length > 0" class="divide-y divide-border-subtle">
                <div
                    v-for="notification in notifications.data"
                    :key="notification.id"
                    :class="[
                        'p-5 transition-colors hover:bg-surface-overlay',
                        notification.read_at ? 'opacity-75' : 'bg-brand-soft/40',
                    ]"
                >
                    <div class="flex items-start gap-4">
                        <!-- Icon -->
                        <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', getTypeColor(notification.type)]">
                            <component :is="getTypeIcon(notification.type)" :size="18" />
                        </div>

                        <!-- Content -->
                        <div class="min-w-0 flex-1">
                            <div class="mb-2 flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <h3 class="text-sm font-semibold text-text-primary">
                                        {{ notification.title }}
                                    </h3>
                                    <p class="mt-1 text-sm text-text-secondary">
                                        {{ notification.message }}
                                    </p>
                                </div>
                                <Badge :variant="getPriorityVariant(notification.priority)" size="sm" class="shrink-0 capitalize">
                                    {{ notification.priority }}
                                </Badge>
                            </div>

                            <div class="mt-3 flex items-center gap-3">
                                <p class="text-xs text-text-tertiary">
                                    {{ formatDate(notification.created_at) }}
                                </p>
                                <Badge v-if="!notification.read_at" variant="brand" size="sm" dot>
                                    New
                                </Badge>
                            </div>

                            <!-- Actions -->
                            <div class="mt-4 flex items-center gap-2">
                                <Button
                                    v-if="notification.action_url"
                                    variant="link"
                                    size="sm"
                                    as="Link"
                                    :href="notification.action_url"
                                    @click="markAsRead(notification)"
                                >
                                    View Details
                                    <ArrowRight :size="14" />
                                </Button>
                                <Button
                                    v-if="!notification.read_at"
                                    variant="ghost"
                                    size="sm"
                                    @click="markAsRead(notification)"
                                >
                                    Mark as Read
                                </Button>
                                <Button variant="ghost" size="sm" @click="deleteNotification(notification)">
                                    {{ t('common.delete') }}
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="flex flex-col items-center gap-3 px-6 py-16 text-center">
                <Bell :size="28" class="text-text-tertiary" />
                <p class="text-sm font-medium text-text-primary">{{ t('notifications.noNotifications') }}</p>
                <p class="text-sm text-text-tertiary">You're all caught up!</p>
            </div>

            <!-- Pagination -->
            <div v-if="notifications.links && notifications.links.length > 3" class="border-t border-border-subtle px-5 py-4">
                <nav class="inline-flex w-full items-center justify-center gap-1">
                    <template v-for="(link, index) in notifications.links" :key="index">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            :class="[
                                'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-colors',
                                link.active
                                    ? 'border-brand bg-brand text-brand-foreground'
                                    : 'border-border-subtle bg-surface-canvas text-text-secondary hover:bg-surface-overlay',
                            ]"
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-2.5 text-xs text-text-tertiary opacity-50"
                            v-html="link.label"
                        />
                    </template>
                </nav>
            </div>
        </Card>
    </AppLayout>
</template>
