<script setup>
/**
 * AppLayout — Linear-inspired authenticated layout.
 *
 * Lives alongside the legacy AuthenticatedLayout.vue while pages migrate
 * one at a time. To use, swap your page's `import AuthenticatedLayout`
 * for `import AppLayout` and you're done — the slot API is the same.
 *
 * Differences from the legacy layout:
 *   - Light, single-surface sidebar (matches the workspace, not a dark slab)
 *   - Lucide icons (no inline SVG paths)
 *   - 240px fixed width — no collapse rail; mobile drawer instead
 *   - Section labels (UPPERCASE, tracking-wider) group related nav items
 *   - Workspace badge at top, user pill at bottom
 *   - Top strip is a thin 44px row, breadcrumb + actions
 *   - Density: 8px nav rows, 13px font, hover = subtle surface change
 */
import { computed, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    Boxes,
    LayoutGrid,
    PackageSearch,
    ShoppingCart,
    Undo2,
    ClipboardList,
    Truck,
    Tag,
    MapPin,
    Warehouse,
    ArrowLeftRight,
    ScanLine,
    Hammer,
    FileSpreadsheet,
    BarChart3,
    Users,
    ShieldCheck,
    Puzzle,
    Settings2,
    Bell,
    Search,
    Menu,
    X,
} from 'lucide-vue-next';

import { usePermissions } from '@/composables/usePermissions';
import GlobalSearch from '@/Components/Layout/GlobalSearch.vue';
import NotificationDropdown from '@/Components/Layout/NotificationDropdown.vue';
import ThemeToggle from '@/Components/Layout/ThemeToggle.vue';
import WarehouseSwitcher from '@/Components/WarehouseSwitcher.vue';

const { t } = useI18n();
const page = usePage();
const { hasPermission } = usePermissions();

const mobileOpen = ref(false);
const globalSearchRef = ref(null);

// Close mobile drawer on navigation
onMounted(() => {
    router.on('start', () => {
        mobileOpen.value = false;
    });
});

// Cmd/Ctrl-K opens global search anywhere
const handleHotkey = (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        globalSearchRef.value?.open();
    }
};
onMounted(() => window.addEventListener('keydown', handleHotkey));

const user = computed(() => page.props.auth?.user);
const workspaceName = computed(() => page.props.auth?.organization?.name || 'Inventoros');

/**
 * Nav schema. Each section is { label, items: [{ icon, name, href, active, perm? }] }.
 * Render is data-driven so adding a section is one line.
 */
const sections = computed(() => [
    {
        label: 'Workspace',
        items: [
            { icon: LayoutGrid, name: 'Dashboard', href: route('dashboard'), active: ['dashboard'] },
            { icon: Boxes, name: 'Inventory', href: route('products.index'), active: ['products.*'], perm: 'view_products' },
            { icon: ShoppingCart, name: 'Orders', href: route('orders.index'), active: ['orders.*'], perm: 'view_orders' },
            { icon: Undo2, name: 'Returns', href: route('returns.index'), active: ['returns.*'], perm: 'manage_returns' },
            { icon: ClipboardList, name: 'Purchase Orders', href: route('purchase-orders.index'), active: ['purchase-orders.*'], perm: 'view_purchase_orders' },
            { icon: Truck, name: 'Suppliers', href: route('suppliers.index'), active: ['suppliers.*'], perm: 'view_suppliers' },
        ],
    },
    {
        label: 'Catalog',
        items: [
            { icon: Tag, name: 'Categories', href: route('categories.index'), active: ['categories.*'], perm: 'manage_categories' },
            { icon: MapPin, name: 'Locations', href: route('locations.index'), active: ['locations.*'], perm: 'manage_locations' },
            { icon: Warehouse, name: 'Warehouses', href: route('warehouses.index'), active: ['warehouses.*'], perm: 'manage_warehouses' },
        ],
    },
    {
        label: 'Stock',
        items: [
            { icon: ArrowLeftRight, name: 'Stock Transfers', href: route('stock-transfers.index'), active: ['stock-transfers.*'], perm: 'view_stock_transfers' },
            { icon: ScanLine, name: 'Stock Audits', href: route('stock-audits.index'), active: ['stock-audits.*'], perm: 'view_stock_audits' },
            { icon: Hammer, name: 'Work Orders', href: route('work-orders.index'), active: ['work-orders.*'], perm: 'manage_stock' },
        ],
    },
    {
        label: 'Insights',
        items: [
            { icon: FileSpreadsheet, name: 'Import / Export', href: route('import-export.index'), active: ['import-export.*'], perm: 'manage_import_export' },
            { icon: BarChart3, name: 'Reports', href: route('reports.index'), active: ['reports.*'], perm: 'view_reports' },
        ],
    },
    {
        label: 'Admin',
        items: [
            { icon: Users, name: 'Users', href: route('users.index'), active: ['users.*'], perm: 'manage_users' },
            { icon: ShieldCheck, name: 'Roles', href: route('roles.index'), active: ['roles.*'], perm: 'manage_roles' },
            { icon: Puzzle, name: 'Plugins', href: route('plugins.index'), active: ['plugins.*'], perm: 'manage_plugins' },
            { icon: Settings2, name: 'Settings', href: route('settings.account.index'), active: ['settings.*', 'webhooks.*', 'account.*'] },
        ],
    },
]);

const visibleSections = computed(() =>
    sections.value
        .map((s) => ({
            ...s,
            items: s.items.filter((i) => !i.perm || hasPermission(i.perm)),
        }))
        .filter((s) => s.items.length > 0)
);

const isActive = (item) => item.active.some((pattern) => route().current(pattern));
</script>

<template>
    <div class="min-h-screen bg-surface-canvas text-text-primary">
        <!-- Mobile top bar -->
        <div class="md:hidden fixed top-0 inset-x-0 z-40 h-12 flex items-center justify-between px-4 bg-surface-base border-b border-border-subtle">
            <Link :href="route('dashboard')" class="flex items-center gap-2">
                <img src="/images/brand/inventoros_icon_transparent_512.png" alt="Inventoros" class="h-7 w-7 shrink-0" />
                <span class="text-sm font-semibold tracking-tight">{{ workspaceName }}</span>
            </Link>
            <button
                @click="mobileOpen = !mobileOpen"
                class="p-1.5 rounded-md text-text-secondary hover:bg-surface-overlay ds-focus-ring"
                aria-label="Toggle navigation"
            >
                <Menu v-if="!mobileOpen" :size="18" />
                <X v-else :size="18" />
            </button>
        </div>

        <!-- Mobile backdrop -->
        <div
            v-show="mobileOpen"
            @click="mobileOpen = false"
            class="md:hidden fixed inset-0 z-40 bg-black/40 mt-12"
            aria-hidden="true"
        />

        <!-- Sidebar -->
        <aside
            :class="[
                'fixed md:fixed inset-y-0 left-0 z-50 w-60 flex flex-col',
                'bg-surface-base border-r border-border-subtle',
                'transform transition-transform duration-200',
                mobileOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0',
                'pt-12 md:pt-0',
            ]"
        >
            <!-- Workspace badge -->
            <div class="px-3 h-14 flex items-center border-b border-border-subtle shrink-0">
                <Link
                    :href="route('dashboard')"
                    class="flex items-center gap-2.5 w-full px-2 py-1.5 rounded-md hover:bg-surface-overlay transition-colors ds-focus-ring"
                >
                    <img src="/images/brand/inventoros_icon_transparent_512.png" alt="Inventoros" class="h-7 w-7 shrink-0" />
                    <span class="text-sm font-semibold tracking-tight truncate flex-1 text-left">
                        {{ workspaceName }}
                    </span>
                    <PackageSearch :size="14" class="text-text-tertiary" />
                </Link>
            </div>

            <!-- Cmd-K search trigger -->
            <div class="px-3 pt-3 shrink-0">
                <button
                    @click="globalSearchRef?.open()"
                    class="w-full flex items-center gap-2 h-8 px-2.5 rounded-md text-xs text-text-tertiary
                           bg-surface-canvas border border-border-subtle hover:border-border-strong
                           transition-colors ds-focus-ring"
                >
                    <Search :size="13" />
                    <span class="flex-1 text-left">Search…</span>
                    <kbd class="hidden md:inline px-1.5 py-0.5 rounded bg-surface-overlay text-[10px] font-mono text-text-secondary border border-border-subtle">
                        ⌘K
                    </kbd>
                </button>
            </div>

            <!-- Nav -->
            <nav class="flex-1 mt-4 overflow-y-auto ds-scroll px-3 pb-4">
                <div v-for="section in visibleSections" :key="section.label" class="mb-5">
                    <p class="px-2 mb-1 text-[10px] font-medium uppercase tracking-wider text-text-tertiary">
                        {{ section.label }}
                    </p>
                    <div class="space-y-px">
                        <Link
                            v-for="item in section.items"
                            :key="item.name"
                            :href="item.href"
                            :class="[
                                'group flex items-center gap-2.5 h-8 px-2.5 rounded-md text-[13px] font-medium',
                                'transition-colors ds-focus-ring',
                                isActive(item)
                                    ? 'bg-surface-overlay text-text-primary'
                                    : 'text-text-secondary hover:bg-surface-overlay hover:text-text-primary',
                            ]"
                        >
                            <component
                                :is="item.icon"
                                :size="15"
                                :class="isActive(item) ? 'text-brand' : 'text-text-tertiary group-hover:text-text-secondary'"
                            />
                            <span class="truncate flex-1">{{ item.name }}</span>
                        </Link>
                    </div>
                </div>
            </nav>

            <!-- User pill -->
            <div class="px-3 py-3 border-t border-border-subtle shrink-0">
                <Link
                    :href="route('settings.account.index')"
                    data-testid="user-menu"
                    class="flex items-center gap-2.5 w-full px-2 py-1.5 rounded-md hover:bg-surface-overlay transition-colors ds-focus-ring"
                >
                    <span class="h-7 w-7 rounded-full bg-surface-overlay grid place-items-center text-[11px] font-semibold text-text-primary shrink-0">
                        {{ (user?.name || '?').charAt(0).toUpperCase() }}
                    </span>
                    <div class="min-w-0 flex-1 text-left">
                        <p class="text-[13px] font-medium text-text-primary truncate">{{ user?.name }}</p>
                        <p class="text-[11px] text-text-tertiary truncate">{{ user?.email }}</p>
                    </div>
                </Link>
            </div>
        </aside>

        <!-- Main column -->
        <div class="md:pl-60 pt-12 md:pt-0">
            <!-- Top strip — sticky, thin -->
            <div class="sticky top-0 z-30 h-11 flex items-center justify-between gap-3 px-4 md:px-6 bg-surface-canvas/80 backdrop-blur border-b border-border-subtle">
                <div class="flex-1 min-w-0">
                    <slot name="header">
                        <span class="text-xs text-text-tertiary">{{ workspaceName }}</span>
                    </slot>
                </div>
                <div class="flex items-center gap-1 shrink-0">
                    <WarehouseSwitcher />
                    <ThemeToggle />
                    <NotificationDropdown />
                </div>
            </div>

            <!-- Page content -->
            <main class="px-4 md:px-6 py-6 md:py-8">
                <slot />
            </main>
        </div>

        <GlobalSearch ref="globalSearchRef" />
    </div>
</template>
