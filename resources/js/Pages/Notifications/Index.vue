<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    notifications: Object,
    stats: Object,
    currentFilter: String,
});

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
        'low_stock': `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />`,
        'out_of_stock': `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />`,
        'order_created': `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />`,
        'order_status_updated': `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />`,
        'order_shipped': `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />`,
        'order_delivered': `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />`,
    };
    return icons[type] || icons.order_created;
};

const getTypeColor = (type) => {
    const colors = {
        'low_stock': 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
        'out_of_stock': 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
        'order_created': 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        'order_status_updated': 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300',
        'order_shipped': 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-800 dark:text-indigo-300',
        'order_delivered': 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
    };
    return colors[type] || colors.order_created;
};

const getPriorityBadge = (priority) => {
    const badges = {
        'low': 'bg-gray-100 dark:bg-gray-900/30 text-gray-600 dark:text-gray-400',
        'normal': 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
        'high': 'bg-orange-100 dark:bg-orange-900/30 text-orange-600 dark:text-orange-400',
        'urgent': 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
    };
    return badges[priority] || badges.normal;
};

const formatDate = (date) => {
    return new Date(date).toLocaleString();
};
</script>

<template>
    <Head title="Notifications" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Notifications</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage your notifications and alerts</p>
                </div>
                <div class="flex gap-3">
                    <button
                        v-if="stats.unread > 0"
                        @click="markAllAsRead"
                        class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                    >
                        Mark All Read
                    </button>
                    <button
                        v-if="stats.read > 0"
                        @click="clearRead"
                        class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                    >
                        Clear Read
                    </button>
                </div>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Total</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ stats.total }}</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Unread</p>
                        <p class="text-3xl font-bold text-primary-400">{{ stats.unread }}</p>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Read</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ stats.read }}</p>
                    </div>
                </div>

                <!-- Filter Tabs -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm mb-6">
                    <div class="border-b border-gray-200 dark:border-dark-border">
                        <nav class="flex -mb-px">
                            <button
                                @click="filterNotifications('all')"
                                :class="[
                                    'px-6 py-4 text-sm font-medium border-b-2 transition',
                                    currentFilter === 'all'
                                        ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                                        : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300'
                                ]"
                            >
                                All ({{ stats.total }})
                            </button>
                            <button
                                @click="filterNotifications('unread')"
                                :class="[
                                    'px-6 py-4 text-sm font-medium border-b-2 transition',
                                    currentFilter === 'unread'
                                        ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                                        : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300'
                                ]"
                            >
                                Unread ({{ stats.unread }})
                            </button>
                            <button
                                @click="filterNotifications('read')"
                                :class="[
                                    'px-6 py-4 text-sm font-medium border-b-2 transition',
                                    currentFilter === 'read'
                                        ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                                        : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300'
                                ]"
                            >
                                Read ({{ stats.read }})
                            </button>
                        </nav>
                    </div>
                </div>

                <!-- Notifications List -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm overflow-hidden">
                    <div v-if="notifications.data.length > 0" class="divide-y divide-gray-200 dark:divide-dark-border">
                        <div
                            v-for="notification in notifications.data"
                            :key="notification.id"
                            :class="[
                                'p-6 hover:bg-gray-50 dark:hover:bg-dark-bg/50 transition',
                                notification.read_at ? 'opacity-75' : 'bg-blue-50/30 dark:bg-blue-900/10'
                            ]"
                        >
                            <div class="flex items-start gap-4">
                                <!-- Icon -->
                                <div :class="['flex-shrink-0 w-12 h-12 rounded-lg flex items-center justify-center', getTypeColor(notification.type)]">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="getTypeIcon(notification.type)"></svg>
                                </div>

                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-4 mb-2">
                                        <div>
                                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                                {{ notification.title }}
                                            </h3>
                                            <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">
                                                {{ notification.message }}
                                            </p>
                                        </div>
                                        <span :class="['px-2 py-1 text-xs font-semibold rounded-full capitalize whitespace-nowrap', getPriorityBadge(notification.priority)]">
                                            {{ notification.priority }}
                                        </span>
                                    </div>

                                    <div class="flex items-center gap-4 mt-3">
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ formatDate(notification.created_at) }}
                                        </p>
                                        <span v-if="!notification.read_at" class="px-2 py-1 bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 text-xs font-semibold rounded-full">
                                            New
                                        </span>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex items-center gap-3 mt-4">
                                        <Link
                                            v-if="notification.action_url"
                                            :href="notification.action_url"
                                            @click="markAsRead(notification)"
                                            class="text-sm text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium"
                                        >
                                            View Details ’
                                        </Link>
                                        <button
                                            v-if="!notification.read_at"
                                            @click="markAsRead(notification)"
                                            class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 font-medium"
                                        >
                                            Mark as Read
                                        </button>
                                        <button
                                            @click="deleteNotification(notification)"
                                            class="text-sm text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 font-medium"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div v-else class="p-12 text-center">
                        <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                        <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">No notifications</p>
                        <p class="text-gray-400 dark:text-gray-500 text-sm mt-1">You're all caught up!</p>
                    </div>

                    <!-- Pagination -->
                    <div v-if="notifications.links && notifications.links.length > 3" class="px-6 py-4 border-t border-gray-200 dark:border-dark-border">
                        <nav class="flex justify-center gap-2">
                            <Link
                                v-for="(link, index) in notifications.links"
                                :key="index"
                                :href="link.url"
                                :class="[
                                    'px-3 py-2 rounded-md text-sm font-medium transition',
                                    link.active
                                        ? 'bg-primary-500 text-white'
                                        : link.url
                                        ? 'bg-gray-100 dark:bg-dark-bg text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-dark-bg/80'
                                        : 'bg-gray-100 dark:bg-dark-bg/50 text-gray-400 cursor-not-allowed opacity-50'
                                ]"
                                :disabled="!link.url"
                                v-html="link.label"
                            />
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
