<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PluginWidgets from '@/Components/PluginWidgets.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, reactive, computed } from 'vue';
import { useQuickReorder } from '@/composables/useQuickReorder';
import { useI18n } from 'vue-i18n';
import { displayCalendarDate, displayDateTime } from '@/lib/dates';
import { formatCompactMoney, formatMoney, formatNumber as formatPlainNumber } from '@/lib/money';
import { orderStatusLabel, orderStatusVariant } from '@/lib/orderLabels';
import axios from 'axios';
import {
    Boxes,
    DollarSign,
    AlertTriangle,
    ShoppingCart,
    Plus,
    Settings2,
    Package,
    PackageX,
    Activity,
    CheckCircle2,
    X,
    ClipboardList,
} from 'lucide-vue-next';

const { t, te } = useI18n();

const props = defineProps({
    stats: Object,
    // The organization's currency: the one the headline money figures are in.
    currency: { type: String, default: 'USD' },
    recentProducts: Array,
    lowStockProducts: Array,
    reorderSuggestions: Array,
    recentOrders: Array,
    stockByCategory: Array,
    widgetPreferences: Object,
    pluginComponents: Object,
    pluginWidgets: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    // Admin-only scheduler / queue health warnings (SchedulerHealth).
    systemWarnings: { type: Array, default: () => [] },
    currency: { type: String, default: 'USD' },
});

const systemWarningMessage = (warning) => {
    if (warning.type === 'scheduler_stale') {
        return warning.last_run
            ? t('dashboard.systemHealth.schedulerStale', { date: displayDateTime(warning.last_run) })
            : t('dashboard.systemHealth.schedulerNeverRan');
    }
    if (warning.type === 'queue_backlog') return t('dashboard.systemHealth.queueBacklog', { count: warning.count });
    if (warning.type === 'failed_jobs') return t('dashboard.systemHealth.failedJobs', { count: warning.count });
    return null;
};

const showCustomizeModal = ref(false);
const saving = ref(false);

const widgetLabels = {
    stats_overview: t('dashboard.widgets.statsOverview'),
    revenue_chart: t('dashboard.widgets.revenueChart'),
    stock_movements: t('dashboard.widgets.stockMovements'),
    low_stock_alerts: t('dashboard.widgets.lowStockAlerts'),
    recent_orders: t('dashboard.recentOrders'),
    recent_products: t('dashboard.recentProducts'),
    top_products: t('dashboard.widgets.topProducts'),
    stock_by_category: t('dashboard.widgets.stockByCategory'),
    reorder_suggestions: t('dashboard.widgets.reorderSuggestions'),
};

const widgets = reactive({ ...(props.widgetPreferences || {}) });

const saveWidgetPreferences = async () => {
    saving.value = true;
    try {
        await axios.patch(route('settings.dashboard-widgets.update'), {
            widgets: { ...widgets },
        });
    } catch (e) {
        // Store in localStorage as fallback
        localStorage.setItem('dashboard_widgets', JSON.stringify({ ...widgets }));
    } finally {
        saving.value = false;
        showCustomizeModal.value = false;
    }
};

// Amounts in the organization's currency unless another is given (see lib/money).
const formatCurrency = (value, currency = props.currency) => formatMoney(value, currency);

const formatNumber = (value) => {
    return formatPlainNumber(value ?? 0, { notation: 'compact', maximumFractionDigits: 1 });
};

const formatCompactCurrency = (value, currency = props.currency) => formatCompactMoney(value, currency);

// Amounts in currencies other than the organization's, listed under a money
// figure rather than added into it (the server sends them per currency).
const plusOthers = (values) => {
    const others = (values || [])
        .filter((row) => row.currency !== props.currency && Number(row.amount) !== 0)
        .map((row) => formatCompactCurrency(row.amount, row.currency));
    return others.length ? t('dashboard.plusOtherCurrencies', { amounts: others.join(', ') }) : null;
};
const otherCurrencies = (key) => plusOthers(props.stats?.byCurrency?.[key]);
const otherCategoryCurrencies = (category) => plusOthers(category.values);

const quickReorder = useQuickReorder(computed(() => props.reorderSuggestions || []));

const reorderThClass = 'px-3 py-2 text-left text-xs font-medium tracking-tight text-text-secondary';

// Secondary stat tiles (revenue_chart widget)
const secondaryStats = () => [
    { key: 'pendingOrders', label: t('dashboard.pendingOrders'), value: formatNumber(props.stats?.pendingOrders), href: route('orders.index', { status: 'pending' }), tone: 'text-status-warning' },
    { key: 'categories', label: t('dashboard.categories'), value: props.stats?.categories, href: route('categories.index'), tone: 'text-brand' },
    { key: 'locations', label: t('dashboard.locations'), value: props.stats?.locations, href: route('locations.index'), tone: 'text-brand' },
    { key: 'revenueThisMonth', label: t('dashboard.revenueThisMonth'), value: formatCompactCurrency(props.stats?.revenueThisMonth), extra: otherCurrencies('revenueThisMonth'), href: null, tone: 'text-brand' },
    { key: 'deadStockValue', label: t('dashboard.deadStockValue'), value: formatCompactCurrency(props.stats?.deadStockValue), extra: otherCurrencies('deadStockValue'), href: route('reports.dead-stock'), tone: 'text-status-warning' },
    { key: 'outstandingReceivables', label: t('dashboard.outstandingReceivables'), value: formatCompactCurrency(props.stats?.outstandingReceivables), extra: otherCurrencies('outstandingReceivables'), href: route('reports.receivables'), tone: 'text-status-warning' },
// A withheld figure is absent from `stats`, not zeroed, so filtering on
// presence also drops the link that went with it -- several point at index
// routes the same user would be refused.
].filter((stat) => props.stats?.[stat.key] !== undefined);
</script>

<template>
    <Head :title="t('dashboard.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('dashboard.title') }}</span>
            </div>
        </template>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <PageHeader
            :title="t('dashboard.title')"
            :description="t('dashboard.description')"
        >
            <template #actions>
                <Button variant="secondary" size="sm" @click="showCustomizeModal = true">
                    <Settings2 :size="14" />
                    {{ t('dashboard.customize') }}
                </Button>
                <Button variant="default" size="sm" as="Link" :href="route('orders.create')">
                    <Plus :size="14" />
                    {{ t('dashboard.newOrder') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Scheduler / queue health (admins only) -->
        <div
            v-if="systemWarnings.length"
            role="alert"
            class="mt-6 rounded-lg border border-status-warning/20 bg-status-warning-soft p-4 text-status-warning"
        >
            <div class="flex items-start gap-3">
                <AlertTriangle :size="16" class="mt-0.5 shrink-0" />
                <div class="space-y-1 text-sm">
                    <p class="font-semibold">{{ t('dashboard.systemHealth.title') }}</p>
                    <p v-for="warning in systemWarnings" :key="warning.type">{{ systemWarningMessage(warning) }}</p>
                    <p class="text-xs">{{ t('dashboard.systemHealth.help') }}</p>
                </div>
            </div>
        </div>

        <!-- Plugin Slot: Before Stats -->
        <PluginSlot slot="before-stats" :components="pluginComponents?.beforeStats" />

        <!-- Primary stats -->
        <section v-if="widgets.stats_overview" class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile
                v-if="stats.totalProducts !== undefined"
                :label="t('dashboard.totalProducts')"
                :value="formatNumber(stats.totalProducts)"
                :hint="t('dashboard.hints.activeInCatalog')"
                icon-tone="brand"
            >
                <template #icon><Boxes :size="20" :stroke-width="1.5" /></template>
            </StatTile>
            <StatTile
                v-if="stats.totalValue !== undefined"
                :label="t('dashboard.inventoryValue')"
                :value="formatCompactCurrency(stats.totalValue)"
                :hint="otherCurrencies('totalValue') || t('dashboard.atSellingPrice')"
                icon-tone="success"
            >
                <template #icon><DollarSign :size="20" :stroke-width="1.5" /></template>
            </StatTile>
            <StatTile
                v-if="stats.lowStockProducts !== undefined"
                :label="t('dashboard.lowStock')"
                :value="formatNumber(stats.lowStockProducts)"
                :delta="stats.lowStockProducts > 0 ? 'attention' : null"
                :delta-tone="stats.lowStockProducts > 0 ? 'down' : 'neutral'"
                :hint="t('dashboard.hints.belowMinimum')"
                icon-tone="warning"
            >
                <template #icon><AlertTriangle :size="20" :stroke-width="1.5" /></template>
            </StatTile>
            <StatTile
                v-if="stats.totalOrders !== undefined"
                :label="t('dashboard.totalOrders')"
                :value="formatNumber(stats.totalOrders)"
                :hint="t('dashboard.hints.allTime')"
                icon-tone="violet"
            >
                <template #icon><ShoppingCart :size="20" :stroke-width="1.5" /></template>
            </StatTile>
        </section>

        <!-- Secondary stats -->
        <section v-if="widgets.revenue_chart" class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <component
                :is="stat.href ? Link : 'div'"
                v-for="stat in secondaryStats()"
                :key="stat.label"
                v-bind="stat.href ? { href: stat.href } : {}"
                :class="[
                    'rounded-lg border border-border-subtle bg-surface-raised p-4 transition-colors',
                    stat.href ? 'hover:border-border-strong' : '',
                ]"
            >
                <p class="text-[11px] font-medium uppercase tracking-wider text-text-tertiary">{{ stat.label }}</p>
                <p :class="['mt-1 text-xl font-semibold tabular-nums', stat.tone]">{{ stat.value }}</p>
                <p v-if="stat.extra" class="mt-0.5 text-xs tabular-nums text-text-tertiary">{{ stat.extra }}</p>
            </component>
        </section>

        <!-- Plugin Slot: After Stats -->
        <PluginSlot slot="after-stats" :components="pluginComponents?.afterStats" />
        <!-- Plugin Slot: Before Content Grid -->
        <PluginSlot slot="before-content" :components="pluginComponents?.beforeContent" />

        <!-- Three column: recent orders / low stock / recent products -->
        <section
            v-if="widgets.recent_orders || widgets.low_stock_alerts || widgets.recent_products"
            class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3"
        >
            <!-- Recent Orders -->
            <Card v-if="widgets.recent_orders" :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('dashboard.recentOrders')">
                        <template #actions>
                            <Link :href="route('orders.index')" class="text-xs text-text-tertiary transition-colors hover:text-text-primary">
                                {{ t('dashboard.viewAll') }}
                            </Link>
                        </template>
                    </CardHeader>
                </div>
                <div class="p-3">
                    <div v-if="recentOrders.length === 0" class="flex flex-col items-center gap-2 py-8 text-center">
                        <ShoppingCart :size="20" class="text-text-tertiary" />
                        <p class="text-sm text-text-tertiary">{{ t('dashboard.noOrdersYet') }}</p>
                        <Button variant="default" size="sm" as="Link" :href="route('orders.create')">
                            {{ t('dashboard.createFirstOrder') }}
                        </Button>
                    </div>
                    <ul v-else class="space-y-0.5">
                        <li v-for="order in recentOrders" :key="order.id">
                            <Link
                                :href="route('orders.show', order.id)"
                                class="flex items-center justify-between gap-3 rounded-md px-2 py-2.5 transition-colors hover:bg-surface-overlay"
                            >
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono text-xs font-medium text-text-primary">{{ order.order_number }}</span>
                                        <Badge :variant="orderStatusVariant(order.status)" size="sm" dot>{{ orderStatusLabel(order.status, { t, te }) }}</Badge>
                                    </div>
                                    <p class="mt-0.5 truncate text-xs text-text-tertiary">
                                        {{ order.customer_name }} · {{ t('dashboard.itemsCount', order.items.length) }}
                                    </p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="text-sm font-semibold tabular-nums text-text-primary">{{ formatCurrency(order.total, order.currency) }}</p>
                                    <p class="text-[11px] text-text-tertiary">{{ displayCalendarDate(order.order_date) }}</p>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>
            </Card>

            <!-- Low Stock Alerts -->
            <Card v-if="widgets.low_stock_alerts" :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('dashboard.lowStockAlert')">
                        <template #actions>
                            <Link :href="route('products.index', { low_stock: 1 })" class="text-xs text-text-tertiary transition-colors hover:text-text-primary">
                                {{ t('dashboard.viewAll') }}
                            </Link>
                        </template>
                    </CardHeader>
                </div>
                <div class="p-3">
                    <div v-if="lowStockProducts.length === 0" class="flex flex-col items-center gap-2 py-8 text-center">
                        <CheckCircle2 :size="20" class="text-status-success" />
                        <p class="text-sm text-text-tertiary">{{ t('dashboard.allWellStocked') }}</p>
                    </div>
                    <ul v-else class="space-y-0.5">
                        <li v-for="product in lowStockProducts" :key="product.id">
                            <Link
                                :href="route('products.show', product.id)"
                                class="flex items-center justify-between gap-3 rounded-md px-2 py-2.5 transition-colors hover:bg-surface-overlay"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-text-primary">{{ product.name }}</p>
                                    <p class="truncate text-xs text-text-tertiary">
                                        {{ product.category?.name }} <span v-if="product.location">· {{ product.location?.name }}</span>
                                    </p>
                                </div>
                                <Badge variant="danger" size="sm" dot>{{ product.stock }} / {{ product.min_stock }}</Badge>
                            </Link>
                        </li>
                    </ul>
                </div>
            </Card>

            <!-- Recent Products -->
            <Card v-if="widgets.recent_products" :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('dashboard.recentProducts')">
                        <template #actions>
                            <Link :href="route('products.index')" class="text-xs text-text-tertiary transition-colors hover:text-text-primary">
                                {{ t('dashboard.viewAll') }}
                            </Link>
                        </template>
                    </CardHeader>
                </div>
                <div class="p-3">
                    <div v-if="recentProducts.length === 0" class="flex flex-col items-center gap-2 py-8 text-center">
                        <Package :size="20" class="text-text-tertiary" />
                        <p class="text-sm text-text-tertiary">{{ t('dashboard.noProductsYet') }}</p>
                        <Button variant="default" size="sm" as="Link" :href="route('products.create')">
                            {{ t('dashboard.addFirstProduct') }}
                        </Button>
                    </div>
                    <ul v-else class="space-y-0.5">
                        <li v-for="product in recentProducts" :key="product.id" class="flex items-center justify-between gap-3 rounded-md px-2 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-text-primary">{{ product.name }}</p>
                                <p class="truncate text-xs text-text-tertiary">
                                    {{ product.category?.name }} <span v-if="product.location">· {{ product.location?.name }}</span>
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-sm font-semibold tabular-nums text-text-primary">{{ formatCurrency(product.price, product.currency) }}</p>
                                <p class="text-[11px] text-text-tertiary">{{ t('dashboard.qty', { count: product.stock }) }}</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </Card>
        </section>

        <!-- Reorder Suggestions -->
        <section v-if="reorderSuggestions && reorderSuggestions.length > 0" class="mt-4">
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('dashboard.reorderSuggestions.title')" :subtitle="t('dashboard.reorderSuggestions.subtitle')">
                        <template #actions>
                            <Badge variant="warning" size="sm">{{ reorderSuggestions.length }}</Badge>
                            <Link :href="route('products.index', { low_stock: true })" class="text-xs text-text-tertiary transition-colors hover:text-text-primary">
                                {{ t('dashboard.viewAll') }}
                            </Link>
                        </template>
                    </CardHeader>
                </div>
                <div class="px-2 pb-2 pt-1">
                    <div class="w-full overflow-x-auto rounded-lg bg-surface-raised">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border-subtle">
                                    <th v-if="quickReorder.canCreatePo.value" class="w-8 px-3 py-2">
                                        <input
                                            type="checkbox"
                                            class="rounded border-border-subtle text-brand ds-focus-ring"
                                            :checked="quickReorder.allSelected.value"
                                            :disabled="quickReorder.orderable.value.length === 0"
                                            :aria-label="t('quickReorder.selectAll')"
                                            @change="quickReorder.toggleAll()"
                                        />
                                    </th>
                                    <th :class="reorderThClass">{{ t('common.product') }}</th>
                                    <th :class="reorderThClass">{{ t('purchaseOrders.supplier') }}</th>
                                    <th :class="[reorderThClass, 'text-right']">{{ t('products.stock') }}</th>
                                    <th :class="[reorderThClass, 'text-right']">{{ t('dashboard.reorderSuggestions.reorderAt') }}</th>
                                    <th :class="[reorderThClass, 'text-right']">{{ t('quickReorder.suggestedQty') }}</th>
                                    <th v-if="quickReorder.canCreatePo.value" :class="[reorderThClass, 'text-right']"><span class="sr-only">{{ t('common.actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in reorderSuggestions"
                                    :key="row.id"
                                    class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay/60"
                                >
                                    <td v-if="quickReorder.canCreatePo.value" class="px-3 py-2">
                                        <input
                                            type="checkbox"
                                            class="rounded border-border-subtle text-brand ds-focus-ring disabled:opacity-40"
                                            :checked="quickReorder.isSelected(row.id)"
                                            :disabled="!row.supplier_id"
                                            :aria-label="t('quickReorder.selectForReorder', { name: row.name })"
                                            @change="quickReorder.toggle(row.id)"
                                        />
                                    </td>
                                    <td class="px-3 py-2">
                                        <Link :href="route('products.show', row.id)" class="flex flex-col">
                                            <span class="font-medium text-text-primary hover:underline">{{ row.name }}</span>
                                            <span class="text-xs text-text-tertiary">{{ row.sku }}<span v-if="row.category"> · {{ row.category }}</span></span>
                                            <span v-if="row.warehouses?.length" class="text-xs text-status-warning">{{ t('warehouses.stockLevels.shortIn', { names: row.warehouses.join(', ') }) }}</span>
                                        </Link>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span v-if="row.supplier" class="text-text-secondary">{{ row.supplier }}</span>
                                        <span v-else class="flex flex-col">
                                            <span class="text-xs italic text-status-danger">{{ t('quickReorder.noSupplier') }}</span>
                                            <Link :href="route('products.edit', row.id)" class="text-xs font-medium text-brand hover:underline">{{ t('quickReorder.addSupplier') }}</Link>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <Badge :variant="row.stock === 0 ? 'danger' : 'warning'" size="sm" dot>{{ row.stock }}</Badge>
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums text-text-secondary">{{ row.reorder_point }}</td>
                                    <td class="px-3 py-2 text-right font-medium tabular-nums text-text-primary">{{ row.suggested_quantity }}</td>
                                    <td v-if="quickReorder.canCreatePo.value" class="px-3 py-2 text-right">
                                        <Button
                                            v-if="row.supplier_id"
                                            variant="ghost"
                                            size="xs"
                                            :disabled="quickReorder.submitting.value"
                                            @click="quickReorder.createPurchaseOrders([row.id])"
                                        >
                                            <ClipboardList :size="12" />
                                            {{ t('quickReorder.createPo') }}
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="quickReorder.canCreatePo.value" class="flex flex-col gap-2 px-3 pb-2 pt-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-text-tertiary">{{ t('quickReorder.hint') }}</p>
                        <Button
                            size="sm"
                            :disabled="quickReorder.selected.value.length === 0 || quickReorder.submitting.value"
                            :loading="quickReorder.submitting.value"
                            @click="quickReorder.createPurchaseOrders()"
                        >
                            <ClipboardList :size="14" />
                            {{ t('quickReorder.createPos', { count: quickReorder.selected.value.length }) }}
                        </Button>
                    </div>
                </div>
            </Card>
        </section>

        <!-- Plugin dashboard widgets (register_dashboard_widget) -->
        <PluginWidgets :widgets="pluginWidgets" />
        <!-- Plugin Slot: Widgets -->
        <PluginSlot slot="widgets" :components="pluginComponents?.widgets" />

        <!-- Plugin Slot: After Content Grid -->
        <PluginSlot slot="after-content" :components="pluginComponents?.afterContent" />

        <!-- Stock by Category -->
        <section v-if="widgets.stock_by_category && stockByCategory.length > 0" class="mt-4">
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('dashboard.stockValueByCategory')" />
                </div>
                <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="category in stockByCategory"
                        :key="category.name"
                        class="rounded-lg border border-border-subtle bg-surface-canvas p-4 transition-colors hover:border-border-strong"
                    >
                        <p class="text-sm font-medium text-text-secondary">{{ category.name }}</p>
                        <p class="mt-1 text-xl font-semibold tabular-nums text-text-primary">{{ formatCompactCurrency(category.value) }}</p>
                        <p v-if="otherCategoryCurrencies(category)" class="mt-0.5 text-xs tabular-nums text-text-tertiary">{{ otherCategoryCurrencies(category) }}</p>
                        <p class="mt-0.5 text-xs text-text-tertiary">{{ formatNumber(category.count) }} {{ t('common.products') }}</p>
                    </div>
                </div>
            </Card>
        </section>

        <!-- Quick Actions -->
        <section class="mt-4">
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('dashboard.quickActions')" />
                </div>
                <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 lg:grid-cols-4">
                    <Link
                        :href="route('orders.create')"
                        class="group flex items-center gap-3 rounded-lg border border-border-subtle bg-surface-canvas p-4 transition-colors hover:border-brand"
                    >
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand">
                            <Plus :size="20" :stroke-width="1.5" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-text-primary">{{ t('dashboard.createOrder') }}</p>
                            <p class="text-xs text-text-tertiary">{{ t('dashboard.newOrder') }}</p>
                        </div>
                    </Link>
                    <Link
                        :href="route('products.create')"
                        class="group flex items-center gap-3 rounded-lg border border-border-subtle bg-surface-canvas p-4 transition-colors hover:border-brand"
                    >
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand">
                            <Plus :size="20" :stroke-width="1.5" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-text-primary">{{ t('dashboard.addProduct') }}</p>
                            <p class="text-xs text-text-tertiary">{{ t('dashboard.createNewItem') }}</p>
                        </div>
                    </Link>
                    <Link
                        :href="route('products.index')"
                        class="group flex items-center gap-3 rounded-lg border border-border-subtle bg-surface-canvas p-4 transition-colors hover:border-border-strong"
                    >
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-surface-overlay text-text-secondary">
                            <Boxes :size="18" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-text-primary">{{ t('dashboard.viewInventory') }}</p>
                            <p class="text-xs text-text-tertiary">{{ t('dashboard.browseAllItems') }}</p>
                        </div>
                    </Link>
                    <Link
                        :href="route('orders.index')"
                        class="group flex items-center gap-3 rounded-lg border border-border-subtle bg-surface-canvas p-4 transition-colors hover:border-border-strong"
                    >
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-surface-overlay text-text-secondary">
                            <ShoppingCart :size="18" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-text-primary">{{ t('dashboard.viewOrders') }}</p>
                            <p class="text-xs text-text-tertiary">{{ t('dashboard.allOrders') }}</p>
                        </div>
                    </Link>
                </div>
            </Card>
        </section>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />

        <!-- Customize Dashboard Modal -->
        <Teleport to="body">
            <div v-if="showCustomizeModal" class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="fixed inset-0 bg-black/50" @click="showCustomizeModal = false"></div>
                <div class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg">
                    <div class="mb-6 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">{{ t('dashboard.customizeModal.title') }}</h3>
                        <button @click="showCustomizeModal = false" class="text-text-tertiary transition-colors hover:text-text-primary">
                            <X :size="18" />
                        </button>
                    </div>

                    <p class="mb-4 text-sm text-text-secondary">{{ t('dashboard.customizeModal.help') }}</p>

                    <div class="ds-scroll max-h-80 space-y-2 overflow-y-auto">
                        <label
                            v-for="(label, key) in widgetLabels"
                            :key="key"
                            class="flex cursor-pointer items-center justify-between rounded-lg border border-border-subtle bg-surface-canvas p-3 transition-colors hover:bg-surface-overlay"
                        >
                            <span class="text-sm font-medium text-text-primary">{{ label }}</span>
                            <div class="relative">
                                <input type="checkbox" v-model="widgets[key]" class="peer sr-only" />
                                <div class="h-6 w-10 rounded-full bg-surface-sunken transition-colors peer-checked:bg-brand"></div>
                                <div class="absolute left-0.5 top-0.5 h-5 w-5 transform rounded-full bg-white shadow transition-transform peer-checked:translate-x-4"></div>
                            </div>
                        </label>
                    </div>

                    <div class="mt-6 flex gap-3">
                        <Button variant="default" class="flex-1" :loading="saving" @click="saveWidgetPreferences">
                            {{ saving ? t('common.saving') : t('dashboard.customizeModal.savePreferences') }}
                        </Button>
                        <Button variant="secondary" @click="showCustomizeModal = false">{{ t('common.cancel') }}</Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
