<script setup>
import { ref, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import SidebarNavItem from '@/Components/Layout/SidebarNavItem.vue';
import SidebarUserProfile from '@/Components/Layout/SidebarUserProfile.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useSidebar } from '@/composables/useSidebar';
import { navItems } from '@/Layouts/navigation';

const { hasPermission } = usePermissions();
const { sidebarCollapsed, sidebarOpen, setCollapsed } = useSidebar();
const settingsSubmenuOpen = ref(false);

const toggleSidebarCollapse = () => {
    setCollapsed(!sidebarCollapsed.value);
    if (sidebarCollapsed.value) {
        settingsSubmenuOpen.value = false;
    }
};

const toggleSettingsSubmenu = () => {
    if (sidebarCollapsed.value) {
        setCollapsed(false);
        settingsSubmenuOpen.value = true;
        return;
    }
    settingsSubmenuOpen.value = !settingsSubmenuOpen.value;
};

onMounted(() => {
    // Auto-expand settings submenu if on a settings page
    if (route().current('settings.*') || route().current('account.*') || route().current('settings.email.*') || route().current('webhooks.*')) {
        settingsSubmenuOpen.value = true;
    }
});
</script>

<template>
    <!-- Sidebar -->
    <aside class="fixed inset-y-0 left-0 z-50 bg-sidebar-bg border-r border-slate-800 transform transition-all duration-200 lg:translate-x-0 flex flex-col"
           :class="[
               sidebarCollapsed ? 'w-[68px]' : 'w-64',
               { '-translate-x-full': !sidebarOpen, 'translate-x-0': sidebarOpen }
           ]">

        <!-- Logo -->
        <div class="flex items-center justify-between h-16 border-b border-slate-800" :class="sidebarCollapsed ? 'px-3' : 'px-5'">
            <Link :href="route('dashboard')" class="flex items-center gap-3">
                <ApplicationLogo class="h-8 w-8 flex-shrink-0" />
                <span v-show="!sidebarCollapsed" class="text-lg font-bold text-white tracking-tight">Inventoros</span>
            </Link>
            <button
                @click="toggleSidebarCollapse"
                class="hidden lg:flex items-center justify-center p-1.5 text-slate-500 hover:text-slate-300 hover:bg-slate-800 rounded-lg transition"
                :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
            >
                <svg class="w-4 h-4 transition-transform duration-200" :class="sidebarCollapsed ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                </svg>
            </button>
        </div>

        <!-- Navigation Links -->
        <nav class="flex-1 py-4 space-y-0.5 overflow-y-auto sidebar-scroll" :class="sidebarCollapsed ? 'px-2' : 'px-3'">
            <!-- Dashboard -->
            <SidebarNavItem
                :href="route('dashboard')"
                :icon="navItems.dashboard.icon"
                label="Dashboard"
                :active-routes="navItems.dashboard.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Inventory -->
            <SidebarNavItem
                v-if="hasPermission('view_products')"
                :href="route('products.index')"
                :icon="navItems.products.icon"
                label="Inventory"
                :active-routes="navItems.products.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Plugin Menu Items -->
            <template v-for="item in $page.props.pluginMenuItems" :key="item.label">
                <SidebarNavItem
                    v-if="!item.permission || hasPermission(item.permission)"
                    :href="item.route ? route(item.route) : item.url"
                    :icon="item.icon"
                    :label="item.label"
                    :active-routes="item.active_routes || [item.route]"
                    :collapsed="sidebarCollapsed"
                />
            </template>

            <!-- Orders -->
            <SidebarNavItem
                v-if="hasPermission('view_orders')"
                :href="route('orders.index')"
                :icon="navItems.orders.icon"
                label="Orders"
                :active-routes="navItems.orders.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Returns -->
            <SidebarNavItem
                v-if="hasPermission('manage_returns')"
                :href="route('returns.index')"
                :icon="navItems.returns.icon"
                label="Returns"
                :active-routes="navItems.returns.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Purchase Orders -->
            <SidebarNavItem
                v-if="hasPermission('view_purchase_orders')"
                :href="route('purchase-orders.index')"
                :icon="navItems.purchaseOrders.icon"
                label="Purchase Orders"
                :active-routes="navItems.purchaseOrders.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Suppliers -->
            <SidebarNavItem
                v-if="hasPermission('view_suppliers')"
                :href="route('suppliers.index')"
                :icon="navItems.suppliers.icon"
                label="Suppliers"
                :active-routes="navItems.suppliers.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Categories -->
            <SidebarNavItem
                v-if="hasPermission('manage_categories')"
                :href="route('categories.index')"
                :icon="navItems.categories.icon"
                label="Categories"
                :active-routes="navItems.categories.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Locations -->
            <SidebarNavItem
                v-if="hasPermission('manage_locations')"
                :href="route('locations.index')"
                :icon="navItems.locations.icon"
                label="Locations"
                :active-routes="navItems.locations.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Warehouses -->
            <SidebarNavItem
                v-if="hasPermission('view_warehouses')"
                :href="route('warehouses.index')"
                :icon="navItems.warehouses.icon"
                label="Warehouses"
                :active-routes="navItems.warehouses.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Stock Transfers -->
            <SidebarNavItem
                v-if="hasPermission('transfer_stock')"
                :href="route('stock-transfers.index')"
                :icon="navItems.stockTransfers.icon"
                label="Stock Transfers"
                :active-routes="navItems.stockTransfers.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Stock Audits -->
            <SidebarNavItem
                v-if="hasPermission('view_stock_audits')"
                :href="route('stock-audits.index')"
                :icon="navItems.stockAudits.icon"
                label="Stock Audits"
                :active-routes="navItems.stockAudits.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Work Orders -->
            <SidebarNavItem
                v-if="hasPermission('manage_stock')"
                :href="route('work-orders.index')"
                :icon="navItems.workOrders.icon"
                label="Work Orders"
                :active-routes="navItems.workOrders.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Import / Export -->
            <SidebarNavItem
                v-if="hasPermission('import_data') || hasPermission('export_data')"
                :href="route('import-export.index')"
                :icon="navItems.importExport.icon"
                label="Import / Export"
                :active-routes="navItems.importExport.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Reports -->
            <SidebarNavItem
                v-if="hasPermission('view_reports')"
                :href="route('reports.index')"
                :icon="navItems.reports.icon"
                label="Reports"
                :active-routes="navItems.reports.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Divider -->
            <div class="pt-4 pb-2" v-if="hasPermission('view_users') || hasPermission('view_roles') || hasPermission('view_plugins') || hasPermission('view_settings')">
                <div class="border-t border-slate-700/50"></div>
            </div>

            <!-- Admin Section Label -->
            <div v-if="(hasPermission('view_users') || hasPermission('view_roles')) && !sidebarCollapsed" class="px-3 pb-1 pt-1">
                <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-widest">Administration</p>
            </div>

            <!-- Users -->
            <SidebarNavItem
                v-if="hasPermission('view_users')"
                :href="route('users.index')"
                :icon="navItems.users.icon"
                label="Users"
                :active-routes="navItems.users.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Roles -->
            <SidebarNavItem
                v-if="hasPermission('view_roles')"
                :href="route('roles.index')"
                :icon="navItems.roles.icon"
                label="Roles"
                :active-routes="navItems.roles.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Plugins -->
            <SidebarNavItem
                v-if="hasPermission('view_plugins')"
                :href="route('plugins.index')"
                :icon="navItems.plugins.icon"
                label="Plugins"
                :active-routes="navItems.plugins.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Admin Tools -->
            <SidebarNavItem
                v-if="hasPermission('manage_organization')"
                :href="route('admin.update.index')"
                :icon="navItems.adminTools.icon"
                label="Admin Tools"
                :active-routes="navItems.adminTools.activeRoutes"
                :collapsed="sidebarCollapsed"
            />

            <!-- Settings with Submenu -->
            <div v-if="hasPermission('view_settings')" class="space-y-0.5">
                <button
                    @click="toggleSettingsSubmenu"
                    :class="[
                        'w-full flex items-center px-3 py-2.5 rounded-lg transition-all duration-150 text-sm',
                        sidebarCollapsed ? 'justify-center' : 'gap-3',
                        route().current('settings.*') || route().current('account.*')
                            ? 'bg-primary-600/20 text-primary-400'
                            : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800'
                    ]"
                    :title="sidebarCollapsed ? 'Settings' : ''"
                >
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <span v-show="!sidebarCollapsed" class="font-medium flex-1 text-left">Settings</span>
                    <svg
                        v-show="!sidebarCollapsed"
                        :class="['w-4 h-4 flex-shrink-0 transition-transform duration-200', settingsSubmenuOpen ? 'rotate-180' : '']"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <!-- Settings Submenu -->
                <div v-show="settingsSubmenuOpen && !sidebarCollapsed" class="ml-8 space-y-0.5">
                    <Link
                        :href="route('settings.organization.index')"
                        :class="[
                            'flex items-center gap-3 px-3 py-2 rounded-lg transition-all duration-150 text-sm',
                            route().current('settings.organization.*')
                                ? 'bg-primary-600/20 text-primary-400'
                                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800'
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
                                ? 'bg-primary-600/20 text-primary-400'
                                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800'
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
                                ? 'bg-primary-600/20 text-primary-400'
                                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800'
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
                                ? 'bg-primary-600/20 text-primary-400'
                                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800'
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
        <SidebarUserProfile :collapsed="sidebarCollapsed" />
    </aside>

    <!-- Mobile sidebar overlay -->
    <div
        v-show="sidebarOpen"
        @click="sidebarOpen = false"
        class="fixed inset-0 z-40 bg-black/50 backdrop-blur-sm lg:hidden"
    ></div>
</template>
