<script setup>
/**
 * AppLayout: shared shell for the approved dashboard design.
 *
 * The single authenticated layout for the app. To use, `import AppLayout`
 * and wrap your page content in it; the default slot is the page body.
 *
 * Design notes:
 *   - Light, single-surface sidebar (matches the workspace, not a dark slab)
 *   - Lucide icons (no inline SVG paths)
 *   - 232px fixed width — no collapse rail; mobile drawer instead
 *   - Section labels (UPPERCASE, tracking-wider) group related nav items
 *   - Workspace badge at top, user pill at bottom
 *   - Branded header, workspace card, quiet selected navigation
 *   - Top strip keeps real organization, warehouse and account controls
 *   - Density: 8px nav rows, 13px font, hover = subtle surface change
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { containTabFocus } from '@/lib/focus';
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
    Lock,
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
    LogOut,
    X,
} from '@lucide/vue';

import { usePermissions } from '@/composables/usePermissions';
import FlashMessages from '@/Components/Layout/FlashMessages.vue';
import GlobalSearch from '@/Components/Layout/GlobalSearch.vue';
import NotificationDropdown from '@/Components/Layout/NotificationDropdown.vue';
import ThemeToggle from '@/Components/Layout/ThemeToggle.vue';
import WarehouseSwitcher from '@/Components/WarehouseSwitcher.vue';
import OrganizationSwitcher from '@/Components/OrganizationSwitcher.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import LegalFooter from '@/Components/LegalFooter.vue';
import { pluginLabel } from '@/plugins/pluginI18n';
import { lockPageScroll } from '@/lib/scrollLock';

const { t, te } = useI18n();
defineProps({ tablePage: { type: Boolean, default: false } });
const page = usePage();
const { hasPermission, hasAnyPermission } = usePermissions();

const mobileOpen = ref(false);
const isMobile = ref(false);
const globalSearchRef = ref(null);
const mobileTrigger = ref(null);
const sidebarRef = ref(null);
let removeNavigationListener;
let removeMediaListener;
let releaseDrawerScroll;

// Close mobile drawer on navigation
onMounted(() => {
    removeNavigationListener = router.on('start', () => {
        mobileOpen.value = false;
    });
    const media = window.matchMedia('(max-width: 767px)');
    const updateMobile = () => {
        isMobile.value = media.matches;
        if (!media.matches) mobileOpen.value = false;
    };
    updateMobile();
    media.addEventListener('change', updateMobile);
    removeMediaListener = () => media.removeEventListener('change', updateMobile);
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
watch(mobileOpen, async (open) => {
    releaseDrawerScroll?.();
    releaseDrawerScroll = open ? lockPageScroll() : undefined;
    await nextTick();
    if (open) sidebarRef.value?.querySelector('a[href], button')?.focus();
    else if (isMobile.value && mobileTrigger.value?.isConnected) mobileTrigger.value.focus();
});

onBeforeUnmount(() => {
    releaseDrawerScroll?.();
    window.removeEventListener('keydown', handleHotkey);
    removeNavigationListener?.();
    removeMediaListener?.();
});

// GlobalSearch owns Cmd/Ctrl-K; the shell handles the navigation drawer.
const handleHotkey = (e) => {
    if (e.key === 'Escape') mobileOpen.value = false;
};
onMounted(() => window.addEventListener('keydown', handleHotkey));

const user = computed(() => page.props.auth?.user);
// Requests waiting for this user's decision, shown on the Approvals item.
const pendingApprovalsCount = computed(() => Number(page.props.pendingApprovalsCount || 0));
const workspaceName = computed(() => page.props.auth?.organization?.name || 'Inventoros');
const workspaceLogo = computed(() => page.props.auth?.organization?.logo_url);

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
            { icon: Boxes, name: t('warehouseStock.title'), href: route('stock.index'), active: ['stock.*'], perm: 'view_products' },
            { icon: ArrowLeftRight, name: t('nav.stockTransfers'), href: route('stock-transfers.index'), active: ['stock-transfers.*'], perm: 'transfer_stock' },
            { icon: ScanLine, name: t('nav.stockAudits'), href: route('stock-audits.index'), active: ['stock-audits.*'], perm: 'view_stock_audits' },
            { icon: Hammer, name: t('nav.workOrders'), href: route('work-orders.index'), active: ['work-orders.*'], perm: 'manage_stock' },
            { icon: Lock, name: t('nav.reservations'), href: route('reservations.index'), active: ['reservations.*'], perm: 'view_products' },
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
    <div class="workspace-shell min-h-screen bg-surface-canvas text-text-primary" :class="{ 'app-layout-table': tablePage }">
        <!-- Mobile top bar -->
        <div class="md:hidden fixed top-0 inset-x-0 z-40 h-12 flex items-center justify-between px-4 bg-surface-base border-b border-border-subtle">
            <Link :href="route('dashboard')" class="flex items-center gap-2">
                <img v-if="workspaceLogo" :src="workspaceLogo" :alt="workspaceName" class="h-7 w-7 shrink-0 object-contain" />
                <span v-else class="h-7 w-7 shrink-0 rounded-md bg-surface-overlay grid place-items-center text-sm font-semibold">{{ workspaceName.charAt(0).toUpperCase() }}</span>
                <span class="text-sm font-semibold tracking-tight">{{ workspaceName }}</span>
            </Link>
            <button
                ref="mobileTrigger"
                @click="mobileOpen = !mobileOpen"
                :aria-expanded="mobileOpen"
                aria-controls="workspace-navigation"
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
            ref="sidebarRef"
            id="workspace-navigation"
            @keydown="event => { if (isMobile && mobileOpen) containTabFocus(event, sidebarRef); }"
            :inert="isMobile && !mobileOpen"
            :class="[
                'workspace-sidebar fixed md:fixed inset-y-0 start-0 z-50 w-60 flex flex-col',
                'bg-surface-base border-e border-border-subtle',
                'transform transition-transform duration-200',
                mobileOpen ? 'translate-x-0' : '-translate-x-full rtl:translate-x-full md:translate-x-0 rtl:md:translate-x-0',
                'pt-12 md:pt-0',
            ]"
        >
            <Link :href="route('dashboard')" class="workspace-brand flex h-14 shrink-0 items-center gap-2 px-5 text-lg font-semibold tracking-tight ds-focus-ring">
                <img src="/images/brand/inventoros_icon_transparent_512.png" alt="" class="h-6 w-6 object-contain" />
                <span>Inventoros</span>
            </Link>
            <!-- Workspace context retains the organization's custom logo. -->
            <div class="workspace-context mx-3 mb-2 flex shrink-0 items-center rounded-md">
                <Link
                    :href="route('dashboard')"
                    :title="workspaceName"
                    class="flex items-center gap-2.5 w-full px-2 py-2 rounded-md hover:bg-surface-overlay transition-colors ds-focus-ring"
                >
                    <img v-if="workspaceLogo" :src="workspaceLogo" :alt="workspaceName" class="h-6 w-6 shrink-0 object-contain" />
                    <span v-else class="h-6 w-6 shrink-0 rounded-md border border-border-subtle bg-surface-raised grid place-items-center text-xs font-semibold">{{ workspaceName.charAt(0).toUpperCase() }}</span>
                    <span class="text-xs font-semibold tracking-tight truncate flex-1 text-start">
                        {{ workspaceName }}
                    </span>
                    <PackageSearch :size="14" :stroke-width="1.6" class="text-text-tertiary" />
                </Link>
            </div>

            <!-- Cmd-K search trigger -->
            <div class="px-3 shrink-0">
                <button
                    @click="globalSearchRef?.open()"
                    class="workspace-search w-full flex items-center gap-2 h-8 px-2.5 rounded-md text-xs text-text-secondary
                           bg-surface-overlay border border-transparent hover:border-border-strong
                           transition-colors ds-focus-ring"
                >
                    <Search :size="13" />
                    <span class="flex-1 text-start">{{ t('nav.search') }}</span>
                    <kbd class="hidden md:inline px-1.5 py-0.5 rounded bg-surface-overlay text-[10px] font-mono text-text-secondary border border-border-subtle">
                        ⌘K
                    </kbd>
                </button>
            </div>

            <!-- Nav -->
            <nav class="workspace-nav flex-1 mt-4 overflow-y-auto ds-scroll px-3 pb-4">
                <div v-for="section in visibleSections" :key="section.id" class="mb-4">
                    <p class="workspace-section-label px-2 mb-1 text-[11px] font-medium text-text-tertiary">
                        {{ section.label }}
                    </p>
                    <div class="space-y-px">
                        <component
                            :is="item.external ? 'a' : Link"
                            v-for="item in section.items"
                            :key="item.name"
                            :href="item.href"
                            :class="[
                                'workspace-nav-item group flex items-center gap-2.5 h-8 px-2.5 rounded-md text-[13px]',
                                'transition-colors ds-focus-ring',
                                isActive(item)
                                    ? 'is-active text-text-primary'
                                    : 'text-text-secondary hover:bg-surface-overlay hover:text-text-primary',
                            ]"
                        >
                            <component
                                :is="item.icon"
                                :size="16"
                                :stroke-width="1.65"
                                :class="isActive(item) ? 'text-brand' : 'text-text-tertiary group-hover:text-text-secondary'"
                            />
                            <span class="truncate flex-1">{{ item.name }}</span>
                            <span
                                v-if="item.badge"
                                class="ms-auto inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-status-warning-soft px-1.5 text-[11px] font-semibold tabular-nums text-status-warning"
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
                    <div class="min-w-0 flex-1 text-start">
                        <p class="text-[13px] font-medium text-text-primary truncate">{{ user?.name }}</p>
                        <p class="text-[11px] text-text-tertiary truncate">{{ user?.email }}</p>
                    </div>
                </Link>
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    data-testid="logout"
                    class="flex items-center gap-2.5 w-full px-2 py-2 rounded-md text-sm text-text-secondary hover:bg-surface-overlay ds-focus-ring"
                >
                    <LogOut :size="16" />
                    {{ t('nav.logOut') }}
                </Link>
            </div>
        </aside>

        <!-- Main column -->
        <div class="workspace-main app-main-column pt-12 md:pt-0" :inert="isMobile && mobileOpen">
            <!-- Top strip — sticky, thin -->
            <div class="workspace-topbar app-topbar sticky top-0 z-30 h-14 flex items-center justify-between gap-3 px-4 md:px-8 bg-surface-raised border-b border-border-subtle">
                <div class="app-breadcrumb flex-1 min-w-0">
                    <slot name="header">
                        <span class="text-xs text-text-tertiary">{{ workspaceName }}</span>
                    </slot>
                </div>
                <div class="workspace-global-controls flex items-center gap-1 shrink-0">
                    <OrganizationSwitcher />
                    <WarehouseSwitcher />
                    <LanguageSwitcher />
                    <ThemeToggle />
                    <NotificationDropdown />
                </div>
            </div>

            <!-- Page content -->
            <main class="px-4 py-6 md:px-8" :class="{ 'app-table-page': tablePage }">
                <slot />
            </main>

            <LegalFooter :single-line="tablePage" :class="tablePage ? 'table-legal-footer' : 'app-legal-footer px-4 md:px-6 py-6 border-t border-border-subtle'" />
        </div>

        <GlobalSearch ref="globalSearchRef" />
        <FlashMessages />
    </div>
</template>
