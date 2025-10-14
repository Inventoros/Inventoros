<script setup>
import { ref, onMounted, computed } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link, router } from '@inertiajs/vue3';
import { usePermissions } from '@/composables/usePermissions';
import axios from 'axios';

const sidebarOpen = ref(false);
const isDark = ref(true);
const unreadCount = ref(0);
const notificationsOpen = ref(false);
const notifications = ref([]);
const loadingNotifications = ref(false);
const settingsSubmenuOpen = ref(false);
const { hasPermission } = usePermissions();

const toggleSettingsSubmenu = () => {
    settingsSubmenuOpen.value = !settingsSubmenuOpen.value;
};

const toggleTheme = () => {
    isDark.value = !isDark.value;
    if (isDark.value) {
        document.documentElement.classList.add('dark');
        localStorage.setItem('theme', 'dark');
    } else {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('theme', 'light');
    }
};

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
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme) {
        isDark.value = savedTheme === 'dark';
        if (isDark.value) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    } else {
        // Default to dark mode
        document.documentElement.classList.add('dark');
    }

    // Auto-expand settings submenu if on a settings page
    if (route().current('settings.*') || route().current('account.*')) {
        settingsSubmenuOpen.value = true;
    }

    // Fetch unread notification count
    fetchUnreadCount();

    // Poll for new notifications every 30 seconds
    const interval = setInterval(fetchUnreadCount, 30000);

    // Cleanup on unmount
    return () => clearInterval(interval);
});
</script>

<template>
    <div class="min-h-screen bg-dark-bg">
        <!-- Sidebar for desktop -->
        <aside class="fixed inset-y-0 left-0 z-50 w-64 bg-dark-card border-r border-dark-border transform transition-transform duration-200 lg:translate-x-0"
               :class="{ '-translate-x-full': !sidebarOpen, 'translate-x-0': sidebarOpen }">
            <div class="flex flex-col h-full">
                <!-- Logo -->
                <div class="flex items-center justify-between h-20 px-6 border-b border-dark-border">
                    <Link :href="route('dashboard')" class="flex items-center gap-3">
                        <ApplicationLogo class="h-9 w-auto fill-current text-primary-400" />
                        <span class="text-xl font-bold text-gray-100">InventorOS</span>
                    </Link>

                    <!-- Theme Toggle -->
                    <button
                        @click="toggleTheme"
                        class="p-2 text-gray-400 hover:text-primary-400 hover:bg-dark-bg/50 rounded-lg transition"
                        :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                    >
                        <!-- Sun icon for light mode (shown when IN dark mode) -->
                        <svg v-if="isDark" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        <!-- Moon icon for dark mode (shown when IN light mode) -->
                        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                        </svg>
                    </button>
                </div>

                <!-- Navigation Links -->
                <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto">
                    <!-- Dashboard -->
                    <Link
                        :href="route('dashboard')"
                        :class="[
                            'flex items-center gap-3 px-3 py-3 rounded-lg transition-all duration-150',
                            route().current('dashboard')
                                ? 'bg-primary-400/10 text-primary-400 border border-primary-400/30'
                                : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                        ]"
                    >
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg>
                        <span class="font-medium">Dashboard</span>
                    </Link>

                    <!-- Inventory -->
                    <Link
                        v-if="hasPermission('view_products')"
                        :href="route('products.index')"
                        :class="[
                            'flex items-center gap-3 px-3 py-3 rounded-lg transition-all duration-150',
                            route().current('products.*')
                                ? 'bg-primary-400/10 text-primary-400 border border-primary-400/30'
                                : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                        ]"
                    >
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                        <span class="font-medium">Inventory</span>
                    </Link>

                    <!-- Orders -->
                    <Link
                        v-if="hasPermission('view_orders')"
                        :href="route('orders.index')"
                        :class="[
                            'flex items-center gap-3 px-3 py-3 rounded-lg transition-all duration-150',
                            route().current('orders.*')
                                ? 'bg-pink-400/10 text-pink-400 border border-pink-400/30'
                                : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                        ]"
                    >
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                        </svg>
                        <span class="font-medium">Orders</span>
                    </Link>

                    <!-- Categories -->
                    <Link
                        v-if="hasPermission('manage_categories')"
                        :href="route('categories.index')"
                        :class="[
                            'flex items-center gap-3 px-3 py-3 rounded-lg transition-all duration-150',
                            route().current('categories.*')
                                ? 'bg-accent-purple/10 text-accent-purple border border-accent-purple/30'
                                : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                        ]"
                    >
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                        </svg>
                        <span class="font-medium">Categories</span>
                    </Link>

                    <!-- Locations -->
                    <Link
                        v-if="hasPermission('manage_locations')"
                        :href="route('locations.index')"
                        :class="[
                            'flex items-center gap-3 px-3 py-3 rounded-lg transition-all duration-150',
                            route().current('locations.*')
                                ? 'bg-orange-400/10 text-orange-400 border border-orange-400/30'
                                : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                        ]"
                    >
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span class="font-medium">Locations</span>
                    </Link>

                    <!-- Import / Export -->
                    <Link
                        v-if="hasPermission('import_data') || hasPermission('export_data')"
                        :href="route('import-export.index')"
                        :class="[
                            'flex items-center gap-3 px-3 py-3 rounded-lg transition-all duration-150',
                            route().current('import-export.*')
                                ? 'bg-cyan-400/10 text-cyan-400 border border-cyan-400/30'
                                : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                        ]"
                    >
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                        </svg>
                        <span class="font-medium">Import / Export</span>
                    </Link>

                    <!-- Reports -->
                    <Link
                        v-if="hasPermission('view_reports')"
                        :href="route('reports.index')"
                        :class="[
                            'flex items-center gap-3 px-3 py-3 rounded-lg transition-all duration-150',
                            route().current('reports.*')
                                ? 'bg-teal-400/10 text-teal-400 border border-teal-400/30'
                                : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                        ]"
                    >
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        <span class="font-medium">Reports</span>
                    </Link>

                    <!-- Divider -->
                    <div class="pt-4 pb-4" v-if="hasPermission('view_users') || hasPermission('view_roles') || hasPermission('view_plugins') || hasPermission('view_settings')">
                        <div class="border-t border-dark-border"></div>
                    </div>

                    <!-- Admin Section Label -->
                    <div class="px-3 mb-2" v-if="hasPermission('view_users') || hasPermission('view_roles')">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Administration</p>
                    </div>

                    <!-- Users -->
                    <Link
                        v-if="hasPermission('view_users')"
                        :href="route('users.index')"
                        :class="[
                            'flex items-center gap-3 px-3 py-3 rounded-lg transition-all duration-150',
                            route().current('users.*')
                                ? 'bg-blue-400/10 text-blue-400 border border-blue-400/30'
                                : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                        ]"
                    >
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        <span class="font-medium">Users</span>
                    </Link>

                    <!-- Roles -->
                    <Link
                        v-if="hasPermission('view_roles')"
                        :href="route('roles.index')"
                        :class="[
                            'flex items-center gap-3 px-3 py-3 rounded-lg transition-all duration-150',
                            route().current('roles.*')
                                ? 'bg-indigo-400/10 text-indigo-400 border border-indigo-400/30'
                                : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                        ]"
                    >
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                        <span class="font-medium">Roles</span>
                    </Link>

                    <!-- Divider for admin section -->
                    <div class="pt-4 pb-4" v-if="(hasPermission('view_users') || hasPermission('view_roles')) && (hasPermission('view_plugins') || hasPermission('view_settings'))">
                        <div class="border-t border-dark-border"></div>
                    </div>

                    <!-- Plugins -->
                    <Link
                        v-if="hasPermission('view_plugins')"
                        :href="route('plugins.index')"
                        :class="[
                            'flex items-center gap-3 px-3 py-3 rounded-lg transition-all duration-150',
                            route().current('plugins.*')
                                ? 'bg-green-400/10 text-green-400 border border-green-400/30'
                                : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                        ]"
                    >
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z" />
                        </svg>
                        <span class="font-medium">Plugins</span>
                    </Link>

                    <!-- Admin Tools -->
                    <Link
                        v-if="hasPermission('manage_organization')"
                        :href="route('admin.update.index')"
                        :class="[
                            'flex items-center gap-3 px-3 py-3 rounded-lg transition-all duration-150',
                            route().current('admin.update.*')
                                ? 'bg-yellow-400/10 text-yellow-400 border border-yellow-400/30'
                                : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                        ]"
                    >
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                        </svg>
                        <span class="font-medium">Admin Tools</span>
                    </Link>

                    <!-- Settings with Submenu -->
                    <div v-if="hasPermission('view_settings')" class="space-y-1">
                        <button
                            @click="toggleSettingsSubmenu"
                            :class="[
                                'w-full flex items-center gap-3 px-3 py-3 rounded-lg transition-all duration-150',
                                route().current('settings.*') || route().current('account.*')
                                    ? 'bg-primary-400/10 text-primary-400 border border-primary-400/30'
                                    : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                            ]"
                        >
                            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <span class="font-medium flex-1 text-left">Settings</span>
                            <svg
                                :class="['w-4 h-4 flex-shrink-0 transition-transform duration-200', settingsSubmenuOpen ? 'rotate-180' : '']"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <!-- Settings Submenu -->
                        <div v-show="settingsSubmenuOpen" class="ml-8 space-y-1">
                            <Link
                                :href="route('settings.organization.index')"
                                :class="[
                                    'flex items-center gap-3 px-3 py-2 rounded-lg transition-all duration-150 text-sm',
                                    route().current('settings.organization.*')
                                        ? 'bg-primary-400/10 text-primary-400'
                                        : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                                ]"
                            >
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                </svg>
                                <span class="font-medium">Organization</span>
                            </Link>

                            <Link
                                :href="route('settings.account.index')"
                                :class="[
                                    'flex items-center gap-3 px-3 py-2 rounded-lg transition-all duration-150 text-sm',
                                    route().current('settings.account.*')
                                        ? 'bg-primary-400/10 text-primary-400'
                                        : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                                ]"
                            >
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                                <span class="font-medium">Account</span>
                            </Link>
                        </div>
                    </div>
                </nav>

                <!-- User Profile at Bottom -->
                <div class="border-t border-dark-border px-4 py-4">
                    <div class="flex items-center gap-3 px-3 py-3 rounded-lg bg-dark-bg/30">
                        <div class="flex-shrink-0">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center ring-2 ring-primary-400/30">
                                <span class="text-sm font-bold text-white">{{ $page.props.auth.user.name.charAt(0).toUpperCase() }}</span>
                            </div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-100 truncate">{{ $page.props.auth.user.name }}</p>
                            <p class="text-xs text-gray-400 truncate">{{ $page.props.auth.user.email }}</p>
                        </div>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="flex-shrink-0 p-2 text-gray-500 hover:text-red-400 hover:bg-red-400/10 rounded-lg transition"
                            title="Logout"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                        </Link>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Mobile sidebar overlay -->
        <div
            v-show="sidebarOpen"
            @click="sidebarOpen = false"
            class="fixed inset-0 z-40 bg-black/50 lg:hidden"
        ></div>

        <!-- Main content area -->
        <div class="lg:pl-64">
            <!-- Page Heading (Fixed) -->
            <header
                class="sticky top-0 z-40 bg-white dark:bg-dark-card border-b border-gray-200 dark:border-dark-border"
                v-if="$slots.header"
            >
                <div class="flex items-center justify-between px-4 py-6 sm:px-6 lg:px-8">
                    <!-- Mobile Menu Button -->
                    <button
                        @click="sidebarOpen = !sidebarOpen"
                        class="lg:hidden p-2 text-gray-400 hover:text-primary-400 hover:bg-gray-100 dark:hover:bg-dark-bg rounded-lg transition"
                    >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    <!-- Header Content -->
                    <div class="flex-1">
                        <slot name="header" />
                    </div>

                    <!-- Header Actions -->
                    <div class="flex items-center gap-2 ml-4">
                        <!-- Theme Toggle -->
                        <button
                            @click="toggleTheme"
                            class="p-2 text-gray-400 hover:text-primary-400 hover:bg-gray-100 dark:hover:bg-dark-bg rounded-lg transition"
                            :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                        >
                            <svg v-if="isDark" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                            <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                            </svg>
                        </button>

                        <!-- Notifications Dropdown -->
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

                            <!-- Notifications Dropdown Panel -->
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
                                        <!-- Icon -->
                                        <div :class="['flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center', getTypeColor(notification.type)]">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getTypeIcon(notification.type)" />
                                            </svg>
                                        </div>

                                        <!-- Content -->
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {{ notification.title }}
                                            </p>
                                            <p class="text-xs text-gray-600 dark:text-gray-300 mt-0.5 line-clamp-2">
                                                {{ notification.message }}
                                            </p>
                                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                                                {{ formatDate(notification.created_at) }}
                                            </p>
                                        </div>

                                        <!-- Unread Indicator -->
                                        <div class="flex-shrink-0">
                                            <div class="w-2 h-2 bg-primary-500 rounded-full"></div>
                                        </div>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>

        <!-- Click outside to close notifications -->
        <div
            v-show="notificationsOpen"
            @click="notificationsOpen = false"
            class="fixed inset-0 z-30"
        ></div>
    </div>
</template>
