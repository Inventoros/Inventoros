<script setup>
import { ref, useId, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';

import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const page = usePage();
const panelId = useId();
const unreadCount = ref(0);
const notificationsOpen = ref(false);
const notifications = ref([]);
const loadingNotifications = ref(false);
const loadError = ref(false);
const actionError = ref(false);
const pendingAction = ref(null);
const trigger = ref(null);
const dropdown = ref(null);
const requests = new AbortController();
let unreadRequest;
let recentRequest;
let unreadRevision = 0;
let disposed = false;
let unreadInterval;

const fetchUnreadCount = () => {
    if (unreadRequest || disposed) return unreadRequest;
    const revision = unreadRevision;
    unreadRequest = axios.get(route('notifications.unread-count'), { signal: requests.signal })
        .then(({ data }) => {
            if (!disposed && revision === unreadRevision) unreadCount.value = data.count;
        })
        .catch(() => {
            // Keep the last known count when a background refresh is unavailable.
        })
        .finally(() => { unreadRequest = null; });
    return unreadRequest;
};

const fetchRecentNotifications = () => {
    if (recentRequest || disposed) return recentRequest;
    loadingNotifications.value = true;
    loadError.value = false;
    recentRequest = axios.get(route('notifications.index'), {
        params: { filter: 'unread' },
        signal: requests.signal,
        headers: {
            'X-Inertia': true,
            'X-Inertia-Version': page.version ?? '',
            'X-Inertia-Partial-Component': 'Notifications/Index',
            'X-Inertia-Partial-Data': 'notifications',
        },
    }).then(({ data }) => {
        const records = data.props?.notifications?.data;
        if (!Array.isArray(records)) throw new Error('Invalid notification response');
        if (!disposed) {
            notifications.value = records.slice(0, 5);
            const total = data.props.notifications.total;
            if (typeof total === 'number') {
                unreadRevision++;
                unreadCount.value = total;
            }
        }
    }).catch((error) => {
        if (!disposed && !axios.isCancel(error)) loadError.value = true;
    }).finally(() => {
        loadingNotifications.value = false;
        recentRequest = null;
    });
    return recentRequest;
};

const toggleNotifications = async () => {
    notificationsOpen.value = !notificationsOpen.value;
    if (notificationsOpen.value) {
        actionError.value = false;
        await fetchRecentNotifications();
    }
};

const restoreActionFocus = async (id) => {
    await nextTick();
    if (disposed || !notificationsOpen.value) return;
    const items = [...dropdown.value.querySelectorAll('[data-notification-id]')];
    const target = items.find(item => item.dataset.notificationId === String(id)) || items[0] || trigger.value;
    target?.focus();
};

const retryNotifications = async () => {
    await fetchRecentNotifications();
    if (!loadError.value) await restoreActionFocus();
};

const markAsRead = async (notification) => {
    if (pendingAction.value || loadingNotifications.value) return;
    pendingAction.value = notification.id;
    actionError.value = false;
    try {
        if (notification.action_url) {
            await new Promise((resolve) => {
                const showError = () => {
                    actionError.value = true;
                    return false;
                };
                // The server redirects only after marking the record as read.
                // A separate Axios redirect followed by router.visit loads the destination twice.
                router.post(route('notifications.mark-as-read', notification.id), {}, {
                    preserveScroll: true,
                    onError: showError,
                    onHttpException: showError,
                    onNetworkError: showError,
                    onSuccess: () => { notificationsOpen.value = false; },
                    onFinish: resolve,
                });
            });
            return;
        }
        await axios.post(route('notifications.mark-as-read', notification.id), {}, { signal: requests.signal });
        if (disposed) return;
        unreadRevision++;
        unreadCount.value = Math.max(0, unreadCount.value - 1);
        notifications.value = notifications.value.filter(({ id }) => id !== notification.id);

        await fetchRecentNotifications();
    } catch (error) {
        if (!disposed && !axios.isCancel(error)) actionError.value = true;
    } finally {
        pendingAction.value = null;
        await restoreActionFocus(notification.id);
    }
};

const markAllAsRead = async () => {
    if (pendingAction.value || loadingNotifications.value) return;
    pendingAction.value = 'all';
    actionError.value = false;
    try {
        await axios.post(route('notifications.mark-all-read'), {}, { signal: requests.signal });
        if (disposed) return;
        unreadRevision++;
        unreadCount.value = 0;
        notifications.value = [];
        loadError.value = false;
    } catch (error) {
        if (!disposed && !axios.isCancel(error)) actionError.value = true;
    } finally {
        pendingAction.value = null;
        await restoreActionFocus();
    }
};

const closeDropdown = () => {
    notificationsOpen.value = false;
    trigger.value?.focus();
};

const handleFocusOut = (event) => {
    if (event.relatedTarget && !dropdown.value?.contains(event.relatedTarget)) {
        notificationsOpen.value = false;
    }
};

const getTypeIcon = (type) => {
    const icons = {
        'low_stock': 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
        'out_of_stock': 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'order_created': 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
        'order_status_updated': 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
        'order_shipped': 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4',
        'order_delivered': 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
    };
    return icons[type] || icons.order_created;
};

const getTypeColor = (type) => {
    const colors = {
        'low_stock': 'text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10',
        'out_of_stock': 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10',
        'order_created': 'text-brand dark:text-brand bg-brand-soft dark:bg-brand/10',
        'order_status_updated': 'text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-500/10',
        'order_shipped': 'text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/10',
        'order_delivered': 'text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-500/10',
    };
    return colors[type] || colors.order_created;
};

const formatDate = (date) => {
    const now = new Date();
    const notifDate = new Date(date);
    const diffInMinutes = Math.floor((now - notifDate) / 1000 / 60);

    if (diffInMinutes < 1) return t('components.relativeTime.justNow');
    if (diffInMinutes < 60) return t('components.relativeTime.minutesAgo', { count: diffInMinutes });
    if (diffInMinutes < 1440) return t('components.relativeTime.hoursAgo', { count: Math.floor(diffInMinutes / 60) });
    return t('components.relativeTime.daysAgo', { count: Math.floor(diffInMinutes / 1440) });
};

onMounted(() => {
    fetchUnreadCount();
    unreadInterval = setInterval(fetchUnreadCount, 30000);
});
onBeforeUnmount(() => {
    disposed = true;
    clearInterval(unreadInterval);
    requests.abort();
});

defineExpose({ closeDropdown });
</script>

<template>
    <div ref="dropdown" class="sm:relative" @keydown.esc.stop.prevent="closeDropdown" @focusout="handleFocusOut">
        <button
            ref="trigger"
            type="button"
            :aria-label="`${t('nav.notifications')}: ${unreadCount}`"
            :aria-expanded="notificationsOpen"
            :aria-controls="panelId"
            @click="toggleNotifications"
            class="relative p-2 text-gray-500 hover:text-brand dark:text-slate-400 dark:hover:text-brand hover:bg-gray-100 dark:hover:bg-surface-raised rounded-lg transition"
            :class="{ 'z-40': notificationsOpen }"
            :title="t('nav.notifications')"
        >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <span
                v-if="unreadCount > 0"
                class="absolute -top-0.5 -end-0.5 flex items-center justify-center w-5 h-5 text-[10px] font-bold text-white bg-action-danger rounded-full ring-2 ring-white dark:ring-surface-canvas"
            >
                {{ unreadCount > 9 ? '9+' : unreadCount }}
            </span>
        </button>

        <!-- Dropdown Panel -->
        <div
            :id="panelId"
            v-show="notificationsOpen"
            role="region"
            :aria-labelledby="`${panelId}-title`"
            @click.stop
            class="absolute inset-x-4 top-full mt-1 flex max-h-[calc(100dvh-5rem)] flex-col sm:inset-x-auto sm:end-0 sm:top-auto sm:mt-2 sm:w-96 bg-white dark:bg-surface-raised border border-gray-200 dark:border-border-subtle rounded-xl shadow-xl overflow-hidden z-50"
        >
            <!-- Header -->
            <div class="flex shrink-0 flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-2 border-b border-gray-100 dark:border-border-subtle">
                <h3 :id="`${panelId}-title`" class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ t('nav.notifications') }}</h3>
                <div class="flex flex-wrap items-center gap-x-3">
                    <button
                        v-if="unreadCount > 0"
                        type="button"
                        :disabled="!!pendingAction || loadingNotifications"
                        :aria-busy="pendingAction === 'all' || undefined"
                        @click="markAllAsRead"
                        class="min-h-8 text-xs text-brand dark:text-brand hover:text-brand dark:hover:text-brand font-medium disabled:opacity-50"
                    >
                        <span v-if="pendingAction === 'all'" class="inline-block me-1 h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true"></span>
                        {{ t('notifications.markAllRead') }}
                    </button>
                    <Link
                        :href="route('notifications.index')"
                        class="inline-flex min-h-8 items-center text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 font-medium"
                        @click="notificationsOpen = false"
                    >
                        {{ t('notifications.viewAllNotifications') }}
                    </Link>
                </div>
            </div>

            <div v-if="loadError || actionError" role="alert" class="shrink-0 border-b border-border-subtle bg-status-danger-soft px-4 py-3 text-sm text-status-danger">
                <p>{{ t(loadError ? 'notifications.loadFailed' : 'notifications.actionFailed') }}</p>
                <button v-if="loadError" type="button" class="mt-1 min-h-8 font-semibold underline underline-offset-4 disabled:opacity-50" :disabled="loadingNotifications || !!pendingAction" @click="retryNotifications">
                    {{ t('common.retry') }}
                </button>
            </div>

            <!-- Notifications List -->
            <div class="min-h-0 max-h-96 overflow-y-auto" :aria-busy="loadingNotifications || !!pendingAction">
                <div v-if="loadingNotifications && notifications.length === 0" role="status" class="p-8 text-center">
                    <span class="sr-only">{{ t('common.loading') }}</span>
                    <svg aria-hidden="true" class="animate-spin h-6 w-6 text-brand mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>

                <div v-else-if="!loadError && notifications.length === 0" role="status" class="p-8 text-center">
                    <svg class="w-10 h-10 text-gray-300 dark:text-gray-600 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('notifications.noNewNotifications') }}</p>
                </div>

                <button
                    v-else
                    v-for="notification in notifications"
                    :key="notification.id"
                    :data-notification-id="notification.id"
                    type="button"
                    :disabled="!!pendingAction || loadingNotifications"
                    :aria-busy="pendingAction === notification.id || undefined"
                    @click="markAsRead(notification)"
                    class="w-full flex items-start gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-slate-800/50 transition border-b border-gray-50 dark:border-border-subtle last:border-b-0 text-start disabled:cursor-wait disabled:opacity-60"
                >
                    <div :class="['shrink-0 w-9 h-9 rounded-lg flex items-center justify-center', getTypeColor(notification.type)]">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getTypeIcon(notification.type)" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="break-words text-sm font-medium text-gray-900 dark:text-gray-100">{{ notification.title }}</p>
                        <p class="break-words text-xs text-gray-500 dark:text-gray-400 mt-0.5 line-clamp-2">{{ notification.message }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ formatDate(notification.created_at) }}</p>
                    </div>
                    <div class="shrink-0 mt-1.5">
                        <span v-if="pendingAction === notification.id" class="block h-3 w-3 animate-spin rounded-full border-2 border-brand border-t-transparent" aria-hidden="true"></span>
                        <div v-else class="w-2 h-2 bg-brand rounded-full"></div>
                    </div>
                </button>
            </div>
        </div>
    </div>

    <!-- Click outside overlay -->
    <div
        v-show="notificationsOpen"
        @click="closeDropdown"
        class="fixed inset-0 z-30"
    ></div>
</template>
