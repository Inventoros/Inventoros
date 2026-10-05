<script setup>
/**
 * AppLayout — Linear-inspired authenticated layout.
 *
 * The single authenticated layout for the app. To use, `import AppLayout`
 * and wrap your page content in it; the default slot is the page body.
 *
 * Design notes:
 *   - Light, single-surface sidebar (matches the workspace, not a dark slab)
 *   - Lucide icons (no inline SVG paths)
 *   - 240px fixed width — no collapse rail; mobile drawer instead
 *   - Section labels (UPPERCASE, tracking-wider) group related nav items
 *   - Workspace badge at top, user pill at bottom
 *   - Top strip is a thin 44px row, breadcrumb + actions
 *   - Density: 8px nav rows, 13px font, hover = subtle surface change
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
    Contact,
    BadgeCheck,
    SlidersHorizontal,
    History,
    ShieldCheck,
    Puzzle,
    Settings2,
    Bell,
    Search,
    Menu,
    X,
} from 'lucide-vue-next';

import { usePermissions } from '@/composables/usePermissions';
import FlashMessages from '@/Components/Layout/FlashMessages.vue';
import GlobalSearch from '@/Components/Layout/GlobalSearch.vue';
import NotificationDropdown from '@/Components/Layout/NotificationDropdown.vue';
import ThemeToggle from '@/Components/Layout/ThemeToggle.vue';
import WarehouseSwitcher from '@/Components/WarehouseSwitcher.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import LegalFooter from '@/Components/LegalFooter.vue';
import { pluginLabel } from '@/plugins/pluginI18n';

const { t, te } = useI18n();
const page = usePage();
const { hasPermission, hasAnyPermission } = usePermissions();

const mobileOpen = ref(false);
const globalSearchRef = ref(null);

// Close mobile drawer on navigation
onMounted(() => {
    router.on('start', () => {
        mobileOpen.value = false;
    });
});

/**
 * Lock the page behind the mobile drawer.
 *
 * The drawer is a fixed overlay, so without this the body keeps scrolling
 * under it: a swipe meant for the nav list scrolls the page instead, and
 * closing the drawer leaves you somewhere you never meant to be. Scoped to
 * the drawer being open, and restored on unmount so a page transition
 * mid-animation cannot strand the lock.
 */
watch(mobileOpen, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});

onBeforeUnmount(() => {
    document.body.style.overflow = '';
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
// Requests waiting for this user's decision, shown on the Approvals item.
const pendingApprovalsCount = computed(() => Number(page.props.pendingApprovalsCount || 0));
const workspaceName = computed(() => page.props.auth?.organization?.name || 'Inventoros');

/**
 * Nav schema. Each section is { id, label, items: [{ icon, name, href, active, perm? }] }.
 * Render is data-driven so adding a section is one line.
 */
const sections = computed(() => [
    {
        id: 'workspace',
        label: t('nav.sections.workspace'),
        items: [
            { icon: LayoutGrid, name: t('nav.dashboard'), href: route('dashboard'), active: ['dashboard'] },
            { icon: Boxes, name: t('nav.inventory'), href: route('products.index'), active: ['products.*'], perm: 'view_products' },
            { icon: ShoppingCart, name: t('nav.orders'), href: route('orders.index'), active: ['orders.*'], perm: 'view_orders' },
            { icon: Contact, name: t('nav.customers'), href: route('customers.index'), active: ['customers.*'], perm: 'view_customers' },
            { icon: Undo2, name: t('nav.returns'), href: route('returns.index'), active: ['returns.*'], perm: 'manage_returns' },
            { icon: ClipboardList, name: t('nav.purchaseOrders'), href: route('purchase-orders.index'), active: ['purchase-orders.*'], perm: 'view_purchase_orders' },
            { icon: Truck, name: t('nav.suppliers'), href: route('suppliers.index'), active: ['suppliers.*'], perm: 'view_suppliers' },
            { icon: BadgeCheck, name: t('nav.approvals'), href: route('approvals.index'), active: ['approvals.*'], perm: ['approve_purchase_orders', 'approve_stock_adjustments', 'approve_stock_transfers'], badge: pendingApprovalsCount.value },
        ],
    },
    {
        id: 'catalog',
        label: t('nav.sections.catalog'),
        items: [
            { icon: Tag, name: t('nav.categories'), href: route('categories.index'), active: ['categories.*'], perm: 'manage_categories' },
            { icon: MapPin, name: t('nav.locations'), href: route('locations.index'), active: ['locations.*'], perm: 'manage_locations' },
            { icon: Warehouse, name: t('nav.warehouses'), href: route('warehouses.index'), active: ['warehouses.*'], perm: 'view_warehouses' },
        ],
    },
    {
        id: 'stock',
        label: t('nav.sections.stock'),
        items: [
            { icon: SlidersHorizontal, name: t('nav.stockAdjustments'), href: route('stock-adjustments.index'), active: ['stock-adjustments.*'], perm: 'manage_stock' },
            { icon: ArrowLeftRight, name: t('nav.stockTransfers'), href: route('stock-transfers.index'), active: ['stock-transfers.*'], perm: 'transfer_stock' },
            { icon: ScanLine, name: t('nav.stockAudits'), href: route('stock-audits.index'), active: ['stock-audits.*'], perm: 'view_stock_audits' },
            { icon: Hammer, name: t('nav.workOrders'), href: route('work-orders.index'), active: ['work-orders.*'], perm: 'manage_stock' },
        ],
    },
    {
        id: 'insights',
        label: t('nav.sections.insights'),
        items: [
            { icon: FileSpreadsheet, name: t('nav.importExport'), href: route('import-export.index'), active: ['import-export.*'], perm: ['export_data', 'import_data'] },
            { icon: BarChart3, name: t('nav.reports'), href: route('reports.index'), active: ['reports.*'], perm: 'view_reports' },
            { icon: History, name: t('nav.activityLog'), href: route('activity-log.index'), active: ['activity-log.*'], perm: 'view_activity_log' },
        ],
    },
    {
        id: 'plugins',
        label: t('nav.sections.plugins'),
        items: pluginNavItems.value,
    },
    {
        id: 'admin',
        label: t('nav.sections.admin'),
        items: [
            { icon: Users, name: t('nav.users'), href: route('users.index'), active: ['users.*'], perm: 'view_users' },
            { icon: ShieldCheck, name: t('nav.roles'), href: route('roles.index'), active: ['roles.*'], perm: 'view_roles' },
            { icon: Puzzle, name: t('nav.plugins'), href: route('plugins.index'), active: ['plugins.*'], perm: 'view_plugins' },
            {
                icon: Settings2,
                name: t('nav.settings'),
                href: route('settings.index'),
                active: ['settings.*', 'webhooks.*', 'two-factor.setup', 'admin.update.*'],
            },
        ],
    },
]);

/**
 * Menu items registered by active plugins (register_menu_item()), shared as
 * the `pluginMenuItems` prop. A plugin names either a route or a URL; items
 * whose route is not registered are dropped rather than throwing. A
 * `label_key` (plugins.{slug}.*) is shown in the user's language once the
 * plugin's bundle has added its messages; until then, the plain label.
 */
const pluginNavItems = computed(() =>
    (page.props.pluginMenuItems || [])
        .map((item) => {
            const hasRoute = item.route && route().has(item.route);
            const href = item.url || (hasRoute ? route(item.route) : null);
            const active = item.active_routes?.length ? item.active_routes : (hasRoute ? [item.route] : []);

            return {
                icon: Puzzle,
                name: pluginLabel(t, te, item.label_key, item.label),
                href,
                active,
                perm: item.permission || undefined,
                external: /^https?:\/\//i.test(item.url || ''),
            };
        })
        .filter((item) => item.href)
);

const canSee = (perm) => {
    if (!perm) return true;
    return Array.isArray(perm) ? hasAnyPermission(perm) : hasPermission(perm);
};

const visibleSections = computed(() =>
    sections.value
        .map((s) => ({
            ...s,
            items: s.items.filter((i) => canSee(i.perm)),
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
                :aria-label="t('nav.toggleNavigation')"
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
                    <span class="flex-1 text-left">{{ t('nav.search') }}</span>
                    <kbd class="hidden md:inline px-1.5 py-0.5 rounded bg-surface-overlay text-[10px] font-mono text-text-secondary border border-border-subtle">
                        ⌘K
                    </kbd>
                </button>
            </div>

            <!-- Nav -->
            <nav class="flex-1 mt-4 overflow-y-auto ds-scroll px-3 pb-4">
                <div v-for="section in visibleSections" :key="section.id" class="mb-5">
                    <p class="px-2 mb-1 text-[10px] font-medium uppercase tracking-wider text-text-tertiary">
                        {{ section.label }}
                    </p>
                    <div class="space-y-px">
                        <component
                            :is="item.external ? 'a' : Link"
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
                            <span
                                v-if="item.badge"
                                class="ml-auto inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-status-warning-soft px-1.5 text-[11px] font-semibold tabular-nums text-status-warning"
                                :aria-label="t('nav.waitingCount', { count: item.badge })"
                            >
                                {{ item.badge > 99 ? '99+' : item.badge }}
                            </span>
                        </component>
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
                <div class="app-breadcrumb flex-1 min-w-0">
                    <slot name="header">
                        <span class="text-xs text-text-tertiary">{{ workspaceName }}</span>
                    </slot>
                </div>
                <div class="flex items-center gap-1 shrink-0">
                    <WarehouseSwitcher />
                    <LanguageSwitcher />
                    <ThemeToggle />
                    <NotificationDropdown />
                </div>
            </div>

            <!-- Page content -->
            <main class="px-4 md:px-6 py-6 md:py-8">
                <slot />
            </main>

            <LegalFooter class="px-4 md:px-6 py-6 border-t border-border-subtle" />
        </div>

        <GlobalSearch ref="globalSearchRef" />
        <FlashMessages />
    </div>
</template>
