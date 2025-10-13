<script setup>
import { ref, onMounted } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
import { usePermissions } from '@/composables/usePermissions';

const sidebarOpen = ref(false);
const isDark = ref(true);
const { hasPermission } = usePermissions();

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

                    <!-- Settings -->
                    <Link
                        v-if="hasPermission('view_settings')"
                        :href="route('settings.index')"
                        :class="[
                            'flex items-center gap-3 px-3 py-3 rounded-lg transition-all duration-150',
                            route().current('settings.*')
                                ? 'bg-primary-400/10 text-primary-400 border border-primary-400/30'
                                : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                        ]"
                    >
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span class="font-medium">Settings</span>
                    </Link>
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
            <!-- Top bar for mobile -->
            <div class="sticky top-0 z-30 bg-dark-card border-b border-dark-border lg:hidden">
                <div class="flex items-center justify-between px-4 h-16">
                    <button
                        @click="sidebarOpen = !sidebarOpen"
                        class="text-gray-400 hover:text-primary-400 transition"
                    >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <Link :href="route('dashboard')" class="flex items-center gap-2">
                        <ApplicationLogo class="h-7 w-auto fill-current text-primary-400" />
                        <span class="text-lg font-bold text-gray-100">InventorOS</span>
                    </Link>
                    <!-- Theme Toggle for mobile -->
                    <button
                        @click="toggleTheme"
                        class="p-2 text-gray-400 hover:text-primary-400 transition"
                        title="Toggle theme"
                    >
                        <svg v-if="isDark" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Page Heading -->
            <header
                class="bg-white dark:bg-dark-card border-b border-gray-200 dark:border-dark-border"
                v-if="$slots.header"
            >
                <div class="px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
