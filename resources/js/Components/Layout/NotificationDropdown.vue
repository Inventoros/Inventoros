<script setup>
import { ref, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';

const unreadCount = ref(0);
const notificationsOpen = ref(false);
const notifications = ref([]);
const loadingNotifications = ref(false);

const fetchUnreadCount = async () => {
    try {
        const response = await axios.get(route('notifications.unread-count'));
        unreadCount.value = response.data.count;
    } catch (error) {
        console.error('Failed to fetch unread count:', error);
    }
};

const fetchRecentNotifications = async () => {
    loadingNotifications.value = true;
    try {
        const response = await axios.get(route('notifications.index'), {
            params: { filter: 'unread', per_page: 5 }
        });
        notifications.value = response.data.notifications.data || [];
    } catch (error) {
        console.error('Failed to fetch notifications:', error);
        notifications.value = [];
    } finally {
        loadingNotifications.value = false;
    }
};

const toggleNotifications = async () => {
    notificationsOpen.value = !notificationsOpen.value;
    if (notificationsOpen.value && notifications.value.length === 0) {
        await fetchRecentNotifications();
    }
};

const markAsRead = async (notification) => {
    try {
        await axios.post(route('notifications.mark-as-read', notification.id));
        fetchUnreadCount();
        fetchRecentNotifications();

        if (notification.action_url) {
            router.visit(notification.action_url);
        }
    } catch (error) {
        console.error('Failed to mark as read:', error);
    }
};

const markAllAsRead = async () => {
    try {
        await axios.post(route('notifications.mark-all-read'));
        fetchUnreadCount();
        fetchRecentNotifications();
        notificationsOpen.value = false;
    } catch (error) {
        console.error('Failed to mark all as read:', error);
    }
};

const closeDropdown = () => {
    notificationsOpen.value = false;
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
        'low_stock': 'text-yellow-600 dark:text-yellow-400',
        'out_of_stock': 'text-red-600 dark:text-red-400',
        'order_created': 'text-blue-600 dark:text-blue-400',
        'order_status_updated': 'text-purple-600 dark:text-purple-400',
        'order_shipped': 'text-indigo-600 dark:text-indigo-400',
        'order_delivered': 'text-green-600 dark:text-green-400',
    };
    return colors[type] || colors.order_created;
};

const formatDate = (date) => {
    const now = new Date();
    const notifDate = new Date(date);
    const diffInMinutes = Math.floor((now - notifDate) / 1000 / 60);

    if (diffInMinutes < 1) return 'Just now';
    if (diffInMinutes < 60) return `${diffInMinutes}m ago`;
    if (diffInMinutes < 1440) return `${Math.floor(diffInMinutes / 60)}h ago`;
    return `${Math.floor(diffInMinutes / 1440)}d ago`;
};

onMounted(() => {
    fetchUnreadCount();
    const interval = setInterval(fetchUnreadCount, 30000);
    return () => clearInterval(interval);
});

defineExpose({ closeDropdown });
</script>

<template>
    <div class="relative">
        <button
            @click="toggleNotifications"
            class="relative p-2 text-gray-400 hover:text-primary-400 hover:bg-gray-100 dark:hover:bg-dark-bg rounded-lg transition"
            title="Notifications"
        >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <span
                v-if="unreadCount > 0"
                class="absolute top-0 right-0 flex items-center justify-center w-5 h-5 text-[10px] font-bold text-white bg-red-500 rounded-full"
            >
                {{ unreadCount > 9 ? '9+' : unreadCount }}
            </span>
        </button>

        <!-- Dropdown Panel -->
        <div
            v-show="notificationsOpen"
            @click.stop
            class="absolute right-0 mt-2 w-96 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-2xl overflow-hidden z-50"
        >
            <!-- Header -->
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-dark-border">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Notifications</h3>
                <div class="flex items-center gap-2">
                    <button
                        v-if="unreadCount > 0"
                        @click="markAllAsRead"
                        class="text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium"
                    >
                        Mark all read
                    </button>
                    <Link
                        :href="route('notifications.index')"
                        class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 font-medium"
                        @click="notificationsOpen = false"
                    >
                        View all
                    </Link>
                </div>
            </div>

            <!-- Notifications List -->
            <div class="max-h-96 overflow-y-auto">
                <div v-if="loadingNotifications" class="p-8 text-center">
                    <svg class="animate-spin h-8 w-8 text-primary-500 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>

                <div v-else-if="notifications.length === 0" class="p-8 text-center">
                    <svg class="w-12 h-12 text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-sm text-gray-500 dark:text-gray-400">No new notifications</p>
                </div>

                <button
                    v-else
                    v-for="notification in notifications"
                    :key="notification.id"
                    @click="markAsRead(notification)"
                    class="w-full flex items-start gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-dark-bg/50 transition border-b border-gray-100 dark:border-dark-border last:border-b-0 text-left"
                >
                    <div :class="['flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center', getTypeColor(notification.type)]">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getTypeIcon(notification.type)" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ notification.title }}</p>
                        <p class="text-xs text-gray-600 dark:text-gray-300 mt-0.5 line-clamp-2">{{ notification.message }}</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ formatDate(notification.created_at) }}</p>
                    </div>
                    <div class="flex-shrink-0">
                        <div class="w-2 h-2 bg-primary-500 rounded-full"></div>
                    </div>
                </button>
            </div>
        </div>
    </div>

    <!-- Click outside overlay -->
    <div
        v-show="notificationsOpen"
        @click="notificationsOpen = false"
        class="fixed inset-0 z-30"
    ></div>
</template>
