<script setup>
import { ref, onMounted } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
import { usePermissions } from '@/composables/usePermissions';
import NotificationDropdown from '@/Components/Layout/NotificationDropdown.vue';
import ThemeToggle from '@/Components/Layout/ThemeToggle.vue';
import SidebarNavItem from '@/Components/Layout/SidebarNavItem.vue';
import SidebarUserProfile from '@/Components/Layout/SidebarUserProfile.vue';

const sidebarOpen = ref(false);
const settingsSubmenuOpen = ref(false);
const notificationDropdownRef = ref(null);
const { hasPermission } = usePermissions();

const toggleSettingsSubmenu = () => {
    settingsSubmenuOpen.value = !settingsSubmenuOpen.value;
};

const closeNotifications = () => {
    notificationDropdownRef.value?.closeDropdown();
};

onMounted(() => {
    // Auto-expand settings submenu if on a settings page
    if (route().current('settings.*') || route().current('account.*') || route().current('settings.email.*') || route().current('webhooks.*')) {
        settingsSubmenuOpen.value = true;
    }
});

// Navigation items configuration
const navItems = {
    dashboard: {
        icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
        activeRoutes: ['dashboard'],
    },
    products: {
        icon: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
        activeRoutes: ['products.*'],
    },
    orders: {
        icon: 'M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z',
        activeRoutes: ['orders.*'],
        color: 'pink',
    },
    purchaseOrders: {
        icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
        activeRoutes: ['purchase-orders.*'],
        color: 'emerald',
    },
    suppliers: {
        icon: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
        activeRoutes: ['suppliers.*'],
        color: 'amber',
    },
    categories: {
        icon: 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z',
        activeRoutes: ['categories.*'],
        color: 'purple',
    },
    locations: {
        icon: 'M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z',
        activeRoutes: ['locations.*'],
        color: 'orange',
    },
    importExport: {
        icon: 'M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4',
        activeRoutes: ['import-export.*'],
        color: 'cyan',
    },
    reports: {
        icon: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        activeRoutes: ['reports.*'],
        color: 'teal',
    },
    users: {
        icon: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
        activeRoutes: ['users.*'],
        color: 'blue',
    },
    roles: {
        icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
        activeRoutes: ['roles.*'],
        color: 'indigo',
    },
    plugins: {
        icon: 'M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z',
        activeRoutes: ['plugins.*'],
        color: 'green',
    },
    adminTools: {
        icon: 'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4',
        activeRoutes: ['admin.update.*'],
        color: 'yellow',
    },
};
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
                    <ThemeToggle />
                </div>

                <!-- Navigation Links -->
                <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto">
                    <!-- Dashboard -->
                    <SidebarNavItem
                        :href="route('dashboard')"
                        :icon="navItems.dashboard.icon"
                        label="Dashboard"
                        :active-routes="navItems.dashboard.activeRoutes"
                    />

                    <!-- Inventory -->
                    <SidebarNavItem
                        v-if="hasPermission('view_products')"
                        :href="route('products.index')"
                        :icon="navItems.products.icon"
                        label="Inventory"
                        :active-routes="navItems.products.activeRoutes"
                    />

                    <!-- Plugin Menu Items -->
                    <template v-for="item in $page.props.pluginMenuItems" :key="item.label">
                        <SidebarNavItem
                            v-if="!item.permission || hasPermission(item.permission)"
                            :href="item.route ? route(item.route) : item.url"
                            :icon="item.icon"
                            :label="item.label"
                            :active-routes="item.active_routes || [item.route]"
                        />
                    </template>

                    <!-- Orders -->
                    <SidebarNavItem
                        v-if="hasPermission('view_orders')"
                        :href="route('orders.index')"
                        :icon="navItems.orders.icon"
                        label="Orders"
                        :active-routes="navItems.orders.activeRoutes"
                        :active-color="navItems.orders.color"
                    />

                    <!-- Purchase Orders -->
                    <SidebarNavItem
                        v-if="hasPermission('view_purchase_orders')"
                        :href="route('purchase-orders.index')"
                        :icon="navItems.purchaseOrders.icon"
                        label="Purchase Orders"
                        :active-routes="navItems.purchaseOrders.activeRoutes"
                        :active-color="navItems.purchaseOrders.color"
                    />

                    <!-- Suppliers -->
                    <SidebarNavItem
                        v-if="hasPermission('view_suppliers')"
                        :href="route('suppliers.index')"
                        :icon="navItems.suppliers.icon"
                        label="Suppliers"
                        :active-routes="navItems.suppliers.activeRoutes"
                        :active-color="navItems.suppliers.color"
                    />

                    <!-- Categories -->
                    <SidebarNavItem
                        v-if="hasPermission('manage_categories')"
                        :href="route('categories.index')"
                        :icon="navItems.categories.icon"
                        label="Categories"
                        :active-routes="navItems.categories.activeRoutes"
                        :active-color="navItems.categories.color"
                    />

                    <!-- Locations -->
                    <SidebarNavItem
                        v-if="hasPermission('manage_locations')"
                        :href="route('locations.index')"
                        :icon="navItems.locations.icon"
                        label="Locations"
                        :active-routes="navItems.locations.activeRoutes"
                        :active-color="navItems.locations.color"
                    />

                    <!-- Import / Export -->
                    <SidebarNavItem
                        v-if="hasPermission('import_data') || hasPermission('export_data')"
                        :href="route('import-export.index')"
                        :icon="navItems.importExport.icon"
                        label="Import / Export"
                        :active-routes="navItems.importExport.activeRoutes"
                        :active-color="navItems.importExport.color"
                    />

                    <!-- Reports -->
                    <SidebarNavItem
                        v-if="hasPermission('view_reports')"
                        :href="route('reports.index')"
                        :icon="navItems.reports.icon"
                        label="Reports"
                        :active-routes="navItems.reports.activeRoutes"
                        :active-color="navItems.reports.color"
                    />

                    <!-- Divider -->
                    <div class="pt-4 pb-4" v-if="hasPermission('view_users') || hasPermission('view_roles') || hasPermission('view_plugins') || hasPermission('view_settings')">
                        <div class="border-t border-dark-border"></div>
                    </div>

                    <!-- Admin Section Label -->
                    <div class="px-3 mb-2" v-if="hasPermission('view_users') || hasPermission('view_roles')">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Administration</p>
                    </div>

                    <!-- Users -->
                    <SidebarNavItem
                        v-if="hasPermission('view_users')"
                        :href="route('users.index')"
                        :icon="navItems.users.icon"
                        label="Users"
                        :active-routes="navItems.users.activeRoutes"
                        :active-color="navItems.users.color"
                    />

                    <!-- Roles -->
                    <SidebarNavItem
                        v-if="hasPermission('view_roles')"
                        :href="route('roles.index')"
                        :icon="navItems.roles.icon"
                        label="Roles"
                        :active-routes="navItems.roles.activeRoutes"
                        :active-color="navItems.roles.color"
                    />

                    <!-- Divider for admin section -->
                    <div class="pt-4 pb-4" v-if="(hasPermission('view_users') || hasPermission('view_roles')) && (hasPermission('view_plugins') || hasPermission('view_settings'))">
                        <div class="border-t border-dark-border"></div>
                    </div>

                    <!-- Plugins -->
                    <SidebarNavItem
                        v-if="hasPermission('view_plugins')"
                        :href="route('plugins.index')"
                        :icon="navItems.plugins.icon"
                        label="Plugins"
                        :active-routes="navItems.plugins.activeRoutes"
                        :active-color="navItems.plugins.color"
                    />

                    <!-- Admin Tools -->
                    <SidebarNavItem
                        v-if="hasPermission('manage_organization')"
                        :href="route('admin.update.index')"
                        :icon="navItems.adminTools.icon"
                        label="Admin Tools"
                        :active-routes="navItems.adminTools.activeRoutes"
                        :active-color="navItems.adminTools.color"
                    />

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
                                v-if="hasPermission('manage_organization')"
                                :href="route('settings.email.index')"
                                :class="[
                                    'flex items-center gap-3 px-3 py-2 rounded-lg transition-all duration-150 text-sm',
                                    route().current('settings.email.*')
                                        ? 'bg-primary-400/10 text-primary-400'
                                        : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                                ]"
                            >
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                                <span class="font-medium">Email</span>
                            </Link>

                            <Link
                                v-if="hasPermission('manage_organization')"
                                :href="route('webhooks.index')"
                                :class="[
                                    'flex items-center gap-3 px-3 py-2 rounded-lg transition-all duration-150 text-sm',
                                    route().current('webhooks.*')
                                        ? 'bg-primary-400/10 text-primary-400'
                                        : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
                                ]"
                            >
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                </svg>
                                <span class="font-medium">Webhooks</span>
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
                <SidebarUserProfile />
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
                        <ThemeToggle />
                        <NotificationDropdown ref="notificationDropdownRef" />
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
            v-show="false"
            @click="closeNotifications"
            class="fixed inset-0 z-30"
        ></div>
    </div>
</template>
