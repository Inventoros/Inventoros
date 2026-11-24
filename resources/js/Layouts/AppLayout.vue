<script setup>
/**
 * AppLayout: shared shell for the approved dashboard design.
 *
 * The single authenticated layout for the app. To use, `import AppLayout`
 * and wrap your page content in it; the default slot is the page body.
 *
 * Design notes:
 *   - Two-level sidebar: a navy rail with one icon per section (settings
 *     and the account menu at its foot) and a light panel listing the
 *     selected section's pages. The panel follows the current page.
 *   - 1280px and up: rail and panel docked; the panel can be collapsed.
 *     768-1279px: rail only, the panel opens as a flyout. Phones: a drawer
 *     holding both.
 *   - Inventoros icon set (resources/js/icons)
 *   - Branded header, workspace card, quiet selected navigation
 *   - Top strip keeps real organization, warehouse and account controls
 *   - Density: 8px nav rows, 13px font, hover = subtle surface change
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { containTabFocus } from '@/lib/focus';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    PanelLeftClose,
    PanelLeftOpen,
    Search,
    Menu,
    X,
} from '@lucide/vue';
import {
    IconActivityLog,
    IconApprovals,
    IconCategories,
    IconCustomers,
    IconDashboard,
    IconImportExport,
    IconInventory,
    IconLocations,
    IconOrders,
    IconPlugins,
    IconPurchaseOrders,
    IconReports,
    IconReservations,
    IconReturns,
    IconRoles,
    IconSettings,
    IconStockAdjustments,
    IconStockAudits,
    IconStockByWarehouse,
    IconStockTransfers,
    IconSuppliers,
    IconUsers,
    IconWarehouses,
    IconWorkOrders,
} from '@/icons';

import { usePermissions } from '@/composables/usePermissions';
import FlashMessages from '@/Components/Layout/FlashMessages.vue';
import GlobalSearch from '@/Components/Layout/GlobalSearch.vue';
import NotificationDropdown from '@/Components/Layout/NotificationDropdown.vue';
import ThemeToggle from '@/Components/Layout/ThemeToggle.vue';
import AccountMenu from '@/Components/Layout/AccountMenu.vue';
import { lastNavSection, leavesPage } from '@/lib/navSection';
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
const panelRef = ref(null);
let removeNavigationListener;
let removeMediaListener;
let releaseDrawerScroll;

/*
 * Sidebar modes. Phones (<768px) get a drawer with the rail and panel. From
 * 768px the rail is docked; from 1280px the panel docks beside it unless the
 * user collapsed it, which is remembered in this browser. When only the rail
 * is docked, picking a section opens its panel as a flyout.
 */
const COLLAPSED_KEY = 'inventoros.sidebar.collapsed';
const readCollapsed = () => {
    try {
        return window.localStorage.getItem(COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
};
const isWide = ref(true);
const collapsed = ref(typeof window !== 'undefined' && readCollapsed());
const flyoutOpen = ref(false);
const panelDocked = computed(() => !isMobile.value && isWide.value && !collapsed.value);
const railOnly = computed(() => !isMobile.value && !panelDocked.value);
const panelVisible = computed(() => isMobile.value || panelDocked.value || flyoutOpen.value);

const setCollapsed = async (value) => {
    collapsed.value = value;
    flyoutOpen.value = false;
    try {
        window.localStorage.setItem(COLLAPSED_KEY, value ? '1' : '0');
    } catch {
        // Private windows may refuse storage; the choice then lasts this page.
    }
    await nextTick();
    sidebarRef.value?.querySelector(value ? '.workspace-rail-button.is-selected' : '.workspace-panel-toggle')?.focus();
};

// Close the drawer and the flyout on navigation.
onMounted(() => {
    removeNavigationListener = router.on('start', (event) => {
        if (!leavesPage(event)) return;
        if (selectedSection.value) lastNavSection.value = selectedSection.value.id;
        mobileOpen.value = false;
        flyoutOpen.value = false;
    });
    const media = window.matchMedia('(max-width: 767px)');
    const wide = window.matchMedia('(min-width: 1280px)');
    const updateMobile = async () => {
        const hadFocus = sidebarRef.value?.contains(document.activeElement);
        isMobile.value = media.matches;
        isWide.value = wide.matches;
        if (!media.matches) mobileOpen.value = false;
        flyoutOpen.value = false;
        // A breakpoint can hide the focused link (the panel undocks, or the
        // drawer closes); keep focus on the control that brings it back.
        if (!hadFocus) return;
        await nextTick();
        const active = document.activeElement;
        if (active && active !== document.body && sidebarRef.value?.contains(active) && active.offsetParent !== null) return;
        if (isMobile.value) mobileTrigger.value?.focus();
        else sidebarRef.value?.querySelector('.workspace-rail-button.is-selected')?.focus();
    };
    updateMobile();
    media.addEventListener('change', updateMobile);
    wide.addEventListener('change', updateMobile);
    document.addEventListener('pointerdown', closeFlyoutOutside);
    removeMediaListener = () => {
        media.removeEventListener('change', updateMobile);
        wide.removeEventListener('change', updateMobile);
        document.removeEventListener('pointerdown', closeFlyoutOutside);
    };
});

function closeFlyoutOutside(event) {
    if (flyoutOpen.value && sidebarRef.value && !sidebarRef.value.contains(event.target)) flyoutOpen.value = false;
}

const closeFlyout = async (returnFocus = false) => {
    if (!flyoutOpen.value) return;
    // Return focus to the button that opened it, not the page's own section.
    const openedBy = selectedSection.value?.id;
    flyoutOpen.value = false;
    if (!returnFocus) return;
    await nextTick();
    sidebarRef.value?.querySelector(`.workspace-rail-button[data-section="${openedBy}"]`)?.focus();
};

const onSidebarFocusOut = (event) => {
    if (flyoutOpen.value && event.relatedTarget && !sidebarRef.value?.contains(event.relatedTarget)) flyoutOpen.value = false;
};

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
const tooltipsHidden = ref(false);
const handleHotkey = (e) => {
    if (e.key !== 'Escape') return;
    tooltipsHidden.value = true;
    if (flyoutOpen.value) closeFlyout(true);
    mobileOpen.value = false;
};
onMounted(() => window.addEventListener('keydown', handleHotkey));

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
            { icon: IconDashboard, name: t('nav.dashboard'), href: route('dashboard'), active: ['dashboard'] },
            { icon: IconInventory, name: t('nav.inventory'), href: route('products.index'), active: ['products.*'], perm: 'view_products' },
            { icon: IconOrders, name: t('nav.orders'), href: route('orders.index'), active: ['orders.*'], perm: 'view_orders' },
            { icon: IconCustomers, name: t('nav.customers'), href: route('customers.index'), active: ['customers.*'], perm: 'view_customers' },
            { icon: IconReturns, name: t('nav.returns'), href: route('returns.index'), active: ['returns.*'], perm: 'manage_returns' },
            { icon: IconPurchaseOrders, name: t('nav.purchaseOrders'), href: route('purchase-orders.index'), active: ['purchase-orders.*'], perm: 'view_purchase_orders' },
            { icon: IconSuppliers, name: t('nav.suppliers'), href: route('suppliers.index'), active: ['suppliers.*'], perm: 'view_suppliers' },
            { icon: IconApprovals, name: t('nav.approvals'), href: route('approvals.index'), active: ['approvals.*'], perm: ['approve_purchase_orders', 'approve_stock_adjustments', 'approve_stock_transfers'], badge: pendingApprovalsCount.value },
        ],
    },
    {
        id: 'catalog',
        label: t('nav.sections.catalog'),
        items: [
            { icon: IconCategories, name: t('nav.categories'), href: route('categories.index'), active: ['categories.*'], perm: 'manage_categories' },
            { icon: IconLocations, name: t('nav.locations'), href: route('locations.index'), active: ['locations.*'], perm: 'manage_locations' },
            { icon: IconWarehouses, name: t('nav.warehouses'), href: route('warehouses.index'), active: ['warehouses.*'], perm: 'view_warehouses' },
        ],
    },
    {
        id: 'stock',
        label: t('nav.sections.stock'),
        items: [
            { icon: IconStockAdjustments, name: t('nav.stockAdjustments'), href: route('stock-adjustments.index'), active: ['stock-adjustments.*'], perm: 'manage_stock' },
            { icon: IconStockByWarehouse, name: t('warehouseStock.title'), href: route('stock.index'), active: ['stock.*'], perm: 'view_products' },
            { icon: IconStockTransfers, name: t('nav.stockTransfers'), href: route('stock-transfers.index'), active: ['stock-transfers.*'], perm: 'transfer_stock' },
            { icon: IconStockAudits, name: t('nav.stockAudits'), href: route('stock-audits.index'), active: ['stock-audits.*'], perm: 'view_stock_audits' },
            { icon: IconWorkOrders, name: t('nav.workOrders'), href: route('work-orders.index'), active: ['work-orders.*'], perm: 'manage_stock' },
            { icon: IconReservations, name: t('nav.reservations'), href: route('reservations.index'), active: ['reservations.*'], perm: 'view_products' },
        ],
    },
    {
        id: 'insights',
        label: t('nav.sections.insights'),
        items: [
            { icon: IconImportExport, name: t('nav.importExport'), href: route('import-export.index'), active: ['import-export.*'], perm: ['export_data', 'import_data'] },
            { icon: IconReports, name: t('nav.reports'), href: route('reports.index'), active: ['reports.*'], perm: 'view_reports' },
            { icon: IconActivityLog, name: t('nav.activityLog'), href: route('activity-log.index'), active: ['activity-log.*'], perm: 'view_activity_log' },
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
            { icon: IconUsers, name: t('nav.users'), href: route('users.index'), active: ['users.*'], perm: 'view_users' },
            { icon: IconRoles, name: t('nav.roles'), href: route('roles.index'), active: ['roles.*'], perm: 'view_roles' },
            { icon: IconPlugins, name: t('nav.plugins'), href: route('plugins.index'), active: ['plugins.*'], perm: 'view_plugins' },
            {
                icon: IconSettings,
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
                icon: IconPlugins,
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

/*
 * The rail: one button per visible section, Admin at its foot beside the
 * account menu. The panel shows the selected section: the one picked on
 * this page, else the one holding the current page, else the last picked.
 */
const railIcons = {
    workspace: IconDashboard,
    catalog: IconCategories,
    stock: IconStockByWarehouse,
    insights: IconReports,
    plugins: IconPlugins,
    admin: IconSettings,
};
const railSections = computed(() => visibleSections.value.filter((section) => section.id !== 'admin'));
const adminSection = computed(() => visibleSections.value.find((section) => section.id === 'admin'));
const routeSectionId = computed(() => visibleSections.value.find((section) => section.items.some(isActive))?.id);
const pickedSectionId = ref(null);
const selectedSection = computed(() => {
    const id = pickedSectionId.value ?? routeSectionId.value ?? lastNavSection.value;
    return visibleSections.value.find((section) => section.id === id) ?? visibleSections.value[0];
});
// A flyout closed without navigating leaves the rail showing where the user is.
watch(flyoutOpen, (open) => {
    if (!open && railOnly.value) pickedSectionId.value = null;
});
const sectionBadge = (section) => section.items.reduce((total, item) => total + (Number(item.badge) || 0), 0);
const sectionWaiting = (section) => (section.id !== selectedSection.value?.id || !panelVisible.value ? sectionBadge(section) : 0);
const railLabel = (section) => {
    const waiting = sectionWaiting(section);
    return waiting ? `${section.label}, ${t('nav.waitingCount', { count: waiting })}` : section.label;
};

const selectSection = async (section, event) => {
    const reselected = selectedSection.value?.id === section.id;
    pickedSectionId.value = section.id;
    if (!railOnly.value) return;
    flyoutOpen.value = !(reselected && flyoutOpen.value);
    // From the keyboard, move into the panel that just opened.
    if (flyoutOpen.value && event?.detail === 0) {
        await nextTick();
        panelRef.value?.querySelector('.workspace-nav a[href]')?.focus();
    }
};

// Arrow keys, Home and End move between the rail's buttons.
const onRailKeydown = (event) => {
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
    const buttons = [...event.currentTarget.querySelectorAll('.workspace-rail-button')];
    const current = buttons.indexOf(document.activeElement);
    if (current === -1) return;
    event.preventDefault();
    const next = event.key === 'Home' ? 0
        : event.key === 'End' ? buttons.length - 1
        : (current + (event.key === 'ArrowUp' ? -1 : 1) + buttons.length) % buttons.length;
    buttons[next]?.focus();
};
// Shown on the search trigger so the shortcut matches the keyboard in use.
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.userAgentData?.platform || navigator.platform || '');
const searchShortcut = isMac ? '⌘K' : 'Ctrl K';
// The same shortcut in the form aria-keyshortcuts expects.
const searchKeys = isMac ? 'Meta+K' : 'Control+K';
</script>

<template>
    <div class="workspace-shell min-h-screen bg-surface-canvas text-text-primary" :class="{ 'app-layout-table': tablePage, 'is-panel-docked': panelDocked }">
        <!-- Mobile top bar -->
        <div class="app-mobile-bar md:hidden fixed top-0 inset-x-0 z-40 h-12 flex items-center justify-between px-4 bg-surface-base border-b border-border-subtle">
            <Link :href="route('dashboard')" class="flex min-h-11 items-center gap-2">
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

        <!-- Sidebar: section rail + section panel -->
        <aside
            ref="sidebarRef"
            id="workspace-navigation"
            @keydown="event => { if (isMobile && mobileOpen) containTabFocus(event, sidebarRef); }"
            @focusout="onSidebarFocusOut"
            :inert="isMobile && !mobileOpen"
            :class="[
                'workspace-sidebar fixed bottom-0 top-12 md:top-0 start-0 z-50 flex',
                'transform transition-transform duration-200',
                { 'is-rail-only': railOnly, 'is-flyout': railOnly && flyoutOpen },
                mobileOpen ? 'translate-x-0' : '-translate-x-full rtl:translate-x-full md:translate-x-0 rtl:md:translate-x-0',
            ]"
        >
            <div
                :class="['workspace-rail', { 'is-tooltips-hidden': tooltipsHidden }]"
                @keydown="onRailKeydown"
                @focusin="tooltipsHidden = false"
                @pointerleave="tooltipsHidden = false"
            >
                <div class="workspace-rail-logo">
                    <img src="/images/brand/inventoros_icon_transparent_512.png" alt="" />
                </div>
                <nav class="workspace-rail-sections" :aria-label="t('nav.sectionSwitcher')">
                    <button
                        v-for="section in [...railSections, ...(adminSection ? [adminSection] : [])]"
                        :key="section.id"
                        :data-section="section.id"
                        type="button"
                        :class="['workspace-rail-button', { 'is-selected': section.id === selectedSection?.id, 'is-foot': section.id === 'admin' }]"
                        :aria-label="railLabel(section)"
                        :aria-current="section.id === selectedSection?.id ? 'true' : undefined"
                        :aria-expanded="railOnly ? (flyoutOpen && section.id === selectedSection?.id) : undefined"
                        aria-controls="workspace-panel"
                        @click="event => selectSection(section, event)"
                    >
                        <component :is="railIcons[section.id] || IconPlugins" :size="20" :stroke-width="1.7" />
                        <span v-if="sectionWaiting(section)" class="workspace-rail-dot" aria-hidden="true" />
                        <span class="workspace-rail-tooltip" aria-hidden="true">{{ section.label }}</span>
                    </button>
                </nav>
                <div class="workspace-rail-foot">
                    <AccountMenu />
                </div>
            </div>

            <div v-show="panelVisible" id="workspace-panel" ref="panelRef" class="workspace-panel">
                <div class="workspace-brand flex shrink-0 items-center gap-1 ps-5 pe-2">
                    <Link :href="route('dashboard')" class="flex-1 min-w-0 truncate text-lg font-semibold tracking-tight rounded-md ds-focus-ring">Inventoros</Link>
                    <button
                        v-if="!isMobile && isWide"
                        type="button"
                        class="workspace-panel-toggle p-1.5 rounded-md text-text-tertiary hover:text-text-primary hover:bg-surface-overlay ds-focus-ring"
                        :aria-label="panelDocked ? t('nav.collapseSidebar') : t('nav.expandSidebar')"
                        :title="panelDocked ? t('nav.collapseSidebar') : t('nav.expandSidebar')"
                        @click="setCollapsed(panelDocked)"
                    >
                        <PanelLeftClose v-if="panelDocked" :size="16" :stroke-width="1.7" class="rtl:-scale-x-100" aria-hidden="true" />
                        <PanelLeftOpen v-else :size="16" :stroke-width="1.7" class="rtl:-scale-x-100" aria-hidden="true" />
                    </button>
                </div>
                <!-- Workspace context retains the organization's custom logo. -->
                <div class="workspace-context mx-3 mb-2 flex shrink-0 items-center rounded-md">
                    <Link
                        :href="route('dashboard')"
                        :title="workspaceName"
                        class="flex items-center gap-2.5 w-full px-2 py-2 rounded-md hover:bg-surface-overlay transition-colors ds-focus-ring"
                    >
                        <img v-if="workspaceLogo" :src="workspaceLogo" :alt="workspaceName" class="h-6 w-6 shrink-0 object-contain" />
                        <span v-else class="h-6 w-6 shrink-0 rounded-md border border-border-subtle bg-surface-raised grid place-items-center text-xs font-semibold">{{ workspaceName.charAt(0).toUpperCase() }}</span>
                        <span class="text-xs font-semibold tracking-tight leading-snug line-clamp-2 break-words flex-1 min-w-0 text-start">
                            {{ workspaceName }}
                        </span>
                    </Link>
                </div>

                <!-- The selected section's pages -->
                <nav v-if="selectedSection" class="workspace-nav flex-1 mt-3 overflow-y-auto ds-scroll px-3 pb-4" :aria-label="selectedSection.label">
                    <p class="workspace-section-label px-2 mb-1 text-[11px] font-medium text-text-tertiary">
                        {{ selectedSection.label }}
                    </p>
                    <div class="space-y-px">
                        <component
                            :is="item.external ? 'a' : Link"
                            v-for="item in selectedSection.items"
                            :key="item.name"
                            :href="item.href"
                            :aria-current="isActive(item) ? 'page' : undefined"
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
                            <span class="truncate flex-1" :title="item.name">{{ item.name }}</span>
                            <span
                                v-if="item.badge"
                                class="ms-auto inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-status-warning-soft px-1.5 text-[11px] font-semibold tabular-nums text-status-warning"
                                :aria-label="t('nav.waitingCount', { count: item.badge })"
                            >
                                {{ item.badge > 99 ? '99+' : item.badge }}
                            </span>
                        </component>
                    </div>
                </nav>
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
                <!-- Global search: Cmd/Ctrl+K anywhere, or "/" outside a field. -->
                <button
                    type="button"
                    class="workspace-search"
                    :aria-label="`${t('nav.search')} (${searchShortcut})`"
                    :aria-keyshortcuts="searchKeys"
                    @click="globalSearchRef?.open()"
                >
                    <Search :size="15" :stroke-width="1.7" aria-hidden="true" />
                    <span class="workspace-search-placeholder">{{ t('nav.globalSearch.placeholder') }}</span>
                    <kbd aria-hidden="true">{{ searchShortcut }}</kbd>
                </button>
                <div class="workspace-global-controls flex items-center gap-1 shrink-0">
                    <button
                        type="button"
                        class="workspace-search-icon p-1.5 rounded-md text-text-secondary hover:bg-surface-overlay ds-focus-ring"
                        :aria-label="t('nav.search')"
                        @click="globalSearchRef?.open()"
                    >
                        <Search :size="18" aria-hidden="true" />
                    </button>
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
