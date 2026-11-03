<script setup>
import TableViewport from '@/Components/ui/TableViewport.vue';
/** Workspace overview: a divided metric strip and operational record panels.
 * Secondary financial facts and shortcuts share a compact surface on wide
 * screens. Existing plugin slots and widget keys stay intact.
 * Values and freshness come from the server; no synthetic trends. */
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PluginWidgets from '@/Components/PluginWidgets.vue';
import MetricCard from '@/Components/Dashboard/MetricCard.vue';
import Modal from '@/Components/Modal.vue';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, reactive, computed } from 'vue';
import { useQuickReorder } from '@/composables/useQuickReorder';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from 'vue-i18n';
import { displayCalendarDate, displayDateTime } from '@/lib/dates';
import { formatCompactMoney, formatMoney, formatNumber as formatPlainNumber } from '@/lib/money';
import { categoryBreakdown, stockLevel } from '@/lib/dashboard';
import { orderStatusLabel } from '@/lib/orderLabels';
import axios from 'axios';
import {
    Boxes,
    AlertTriangle,
    ShoppingCart,
    Plus,
    SlidersHorizontal,
    Package,
    CheckCircle2,
    X,
    ClipboardList,
    BarChart3,
    ArrowUpRight,
    ArrowRight,
    ChevronRight,
    ArrowLeftRight,
    FileSpreadsheet,
    Clock3,
    CircleDot,
    Check,
} from '@lucide/vue';

const { canVisit } = usePermissions();
const { t, te, n } = useI18n();
const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    currency: { type: String, default: 'USD' },
    generatedAt: { type: String, default: null },
    recentProducts: { type: Array, default: () => [] },
    lowStockProducts: { type: Array, default: () => [] },
    reorderSuggestions: { type: Array, default: () => [] },
    recentOrders: { type: Array, default: () => [] },
    stockByCategory: { type: Array, default: () => [] },
    widgetPreferences: { type: Object, default: () => ({}) },
    pluginComponents: { type: Object, default: () => ({}) },
    pluginWidgets: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    systemWarnings: { type: Array, default: () => [] },
});
const widgets = reactive({ ...props.widgetPreferences });
const widgetDraft = ref({});
const showCustomizeModal = ref(false);
const showSystemModal = ref(false);
const saving = ref(false);
const preferenceError = ref(false);
const selectedProduct = ref(null);
const widgetLabels = computed(() => ({
    stats_overview: t('dashboard.widgets.statsOverview'),
    revenue_chart: t('dashboard.widgets.revenueChart'),
    low_stock_alerts: t('dashboard.widgets.lowStockAlerts'),
    recent_orders: t('dashboard.recentOrders'),
    recent_products: t('dashboard.recentProducts'),
    stock_by_category: t('dashboard.widgets.stockByCategory'),
    reorder_suggestions: t('dashboard.widgets.reorderSuggestions'),
}));
const customize = () => {
    widgetDraft.value = { ...widgets };
    preferenceError.value = false;
    showCustomizeModal.value = true;
};
const saveWidgetPreferences = async () => {
    saving.value = true;
    preferenceError.value = false;
    try {
        await axios.patch(route('settings.dashboard-widgets.update'), { widgets: { ...widgetDraft.value } });
        Object.assign(widgets, widgetDraft.value);
        showCustomizeModal.value = false;
    } catch {
        preferenceError.value = true;
    } finally {
        saving.value = false;
    }
};
const formatCurrency = (value, currency = props.currency) => formatMoney(value, currency);
const formatNumber = (value) => formatPlainNumber(value, { notation: 'compact', maximumFractionDigits: 1 });
const formatCompactCurrency = (value, currency = props.currency) => formatCompactMoney(value, currency);
const plusOthers = (values) => {
    const amounts = (values || [])
        .filter((row) => row.currency !== props.currency && Number(row.amount) !== 0)
        .map((row) => formatCompactCurrency(row.amount, row.currency));
    return amounts.length ? t('dashboard.plusOtherCurrencies', { amounts: amounts.join(', ') }) : '';
};
const otherCurrencies = (key) => plusOthers(props.stats.byCurrency?.[key]);
const optionalRoute = (name, parameters) => (canVisit(name) ? route(name, parameters) : null);
const headlineMetrics = computed(() => {
    const stats = props.stats;
    const counts = [
        stats.categories !== undefined ? `${formatNumber(stats.categories)} ${t('dashboard.categories')}` : '',
        stats.locations !== undefined ? `${formatNumber(stats.locations)} ${t('dashboard.locations')}` : '',
    ]
        .filter(Boolean)
        .join(' · ');
    return [
        widgets.stats_overview &&
            stats.totalProducts !== undefined && {
                key: 'products',
                label: t('dashboard.totalProducts'),
                value: formatNumber(stats.totalProducts),
                icon: Boxes,
                context: counts,
                detail: formatPlainNumber(stats.totalProducts),
                href: optionalRoute('products.index'),
            },
        widgets.stats_overview &&
            stats.totalValue !== undefined && {
                key: 'value',
                label: t('dashboard.inventoryValue'),
                value: formatCompactCurrency(stats.totalValue),
                icon: Package,
                context: `${t('dashboard.atSellingPrice')} · ${props.currency}`,
                detail: formatCurrency(stats.totalValue),
                extra: otherCurrencies('totalValue'),
                href: optionalRoute('reports.inventory-valuation'),
            },
        widgets.revenue_chart &&
            stats.pendingOrders !== undefined && {
                key: 'pending',
                label: t('dashboard.pendingOrders'),
                value: formatNumber(stats.pendingOrders),
                icon: ShoppingCart,
                context: `${formatNumber(stats.totalOrders)} ${t('nav.orders')} · ${t('dashboard.hints.allTime')}`,
                detail: formatPlainNumber(stats.pendingOrders),
                href: optionalRoute('orders.index', { status: 'pending' }),
            },
        widgets.revenue_chart &&
            stats.revenueThisMonth !== undefined && {
                key: 'revenue',
                label: t('dashboard.revenueThisMonth'),
                value: formatCompactCurrency(stats.revenueThisMonth),
                icon: BarChart3,
                context: `${t('dashboard.design.monthToDate')} · ${props.currency}`,
                detail: formatCurrency(stats.revenueThisMonth),
                extra: otherCurrencies('revenueThisMonth'),
                href: optionalRoute('reports.sales-analysis'),
            },
    ].filter(Boolean);
});
const financialStats = computed(() =>
    widgets.revenue_chart
        ? [
              props.stats.outstandingReceivables !== undefined && {
                  key: 'outstandingReceivables',
                  label: t('dashboard.outstandingReceivables'),
                  context: t('dashboard.design.allOutstanding'),
                  href: optionalRoute('reports.receivables'),
              },
              props.stats.deadStockValue !== undefined && {
                  key: 'deadStockValue',
                  label: t('dashboard.deadStockValue'),
                  context: t('dashboard.design.atCost'),
                  href: optionalRoute('reports.dead-stock'),
              },
          ].filter(Boolean)
        : [],
);
const categories = computed(() => categoryBreakdown(props.stockByCategory, props.stats.totalValue));
const categoryName = (category) => category.name || t('products.show.uncategorized');
const categoryProductCount = computed(() => props.stockByCategory.reduce((sum, row) => sum + Number(row.count), 0));
const formatShare = (share) =>
    share === null
        ? '-'
        : share < 0.1 && share > 0
          ? `<${n(0.001, { style: 'percent', maximumFractionDigits: 1 })}`
          : n(share / 100, { style: 'percent', maximumFractionDigits: 1 });
const quickActions = computed(() =>
    [
        { route: 'products.create', label: t('dashboard.addProduct'), icon: Plus },
        { route: 'stock-adjustments.create', label: t('nav.stockAdjustments'), icon: SlidersHorizontal },
        { route: 'stock-transfers.create', label: t('nav.stockTransfers'), icon: ArrowLeftRight },
        { route: 'import-export.index', label: t('nav.importExport'), icon: FileSpreadsheet },
    ].filter((action) => canVisit(action.route)),
);
const systemWarningMessage = (warning) => {
    if (warning.type === 'scheduler_stale')
        return warning.last_run
            ? t('dashboard.systemHealth.schedulerStale', { date: displayDateTime(warning.last_run) })
            : t('dashboard.systemHealth.schedulerNeverRan');
    if (warning.type === 'queue_backlog') return t('dashboard.systemHealth.queueBacklog', { count: warning.count });
    if (warning.type === 'failed_jobs') return t('dashboard.systemHealth.failedJobs', { count: warning.count });
    return '';
};
const quickReorder = useQuickReorder(computed(() => props.reorderSuggestions));
const selectedSuggestion = computed(() => props.reorderSuggestions.find((row) => row.id === selectedProduct.value?.id));
const reorderThClass = 'px-3 py-2 text-start text-xs font-medium tracking-tight text-text-secondary';
const ordersStatusIcon = (status) => (status === 'delivered' ? Check : status === 'pending' ? Clock3 : CircleDot);
</script>

<template>
    <Head :title="t('dashboard.title')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span
                ><ChevronRight :size="12" class="text-text-tertiary" aria-hidden="true" /><span class="font-medium text-text-primary">{{
                    t('dashboard.title')
                }}</span>
            </div>
        </template>
        <div class="dashboard">
            <PluginSlot slot="header" :components="pluginComponents.header" />
            <header class="dashboard-heading">
                <div>
                    <p class="dashboard-eyebrow">{{ t('dashboard.design.overview') }}</p>
                    <h1>{{ t('dashboard.title') }}</h1>
                </div>
                <div class="dashboard-heading-actions">
                    <time v-if="generatedAt" :datetime="generatedAt" :title="displayDateTime(generatedAt)" class="dashboard-date">{{
                        displayCalendarDate(generatedAt)
                    }}</time>
                    <Button variant="outline" size="sm" :aria-label="t('dashboard.customizeModal.title')" @click="customize"
                        ><SlidersHorizontal :size="14" /><span class="dashboard-customize-label">{{
                            t('dashboard.customize')
                        }}</span></Button
                    >
                    <Button v-if="canVisit('orders.create')" size="sm" as="Link" :href="route('orders.create')"
                        ><Plus :size="14" />{{ t('dashboard.newOrder') }}</Button
                    >
                </div>
            </header>
            <div v-if="systemWarnings.length" class="dashboard-system" role="status">
                <span><AlertTriangle :size="14" aria-hidden="true" />{{ t('dashboard.systemHealth.title') }}</span
                ><button type="button" class="dashboard-text-link ds-focus-ring" @click="showSystemModal = true">
                    {{ t('dashboard.design.reviewSystem') }}<ArrowRight :size="13" aria-hidden="true" />
                </button>
            </div>
            <PluginSlot slot="before-stats" :components="pluginComponents.beforeStats" />
            <section
                v-if="headlineMetrics.length"
                class="dashboard-metrics"
                :class="{
                    'dashboard-metrics-single': headlineMetrics.length === 1,
                    'dashboard-metrics-odd': headlineMetrics.length > 1 && headlineMetrics.length % 2 !== 0,
                }"
                :style="{ '--metric-count': headlineMetrics.length }"
                :aria-label="t('dashboard.widgets.statsOverview')"
            >
                <MetricCard
                    v-for="metric in headlineMetrics"
                    :key="metric.key"
                    v-bind="metric"
                    :data-testid="`dashboard-metric-${metric.key}`"
                />
            </section>
            <PluginSlot slot="after-stats" :components="pluginComponents.afterStats" />
            <PluginSlot slot="before-content" :components="pluginComponents.beforeContent" />

            <section
                v-if="widgets.low_stock_alerts"
                class="dashboard-panel dashboard-replenishment"
                :class="{ 'dashboard-replenishment-warning': lowStockProducts.length }"
                aria-labelledby="replenishment-title"
            >
                <div class="dashboard-panel-heading">
                    <div class="dashboard-panel-title">
                        <AlertTriangle v-if="lowStockProducts.length" :size="16" class="text-status-warning" aria-hidden="true" />
                        <h2 id="replenishment-title">{{ t('dashboard.design.replenishment') }}</h2>
                        <span v-if="stats.lowStockProducts" class="dashboard-warning-badge"
                            >{{ formatNumber(stats.lowStockProducts) }} {{ t('dashboard.lowStock') }}</span
                        >
                    </div>
                    <Link
                        v-if="canVisit('products.index')"
                        :href="route('products.index', { low_stock: 1 })"
                        class="dashboard-text-link ds-focus-ring"
                        >{{ t('dashboard.viewInventory') }}<ArrowUpRight :size="14" aria-hidden="true"
                    /></Link>
                </div>
                <div v-if="!lowStockProducts.length" class="dashboard-empty">
                    <template v-if="stats.totalProducts === 0"
                        ><Package :size="22" aria-hidden="true" />
                        <p>{{ t('dashboard.noProductsYet') }}</p>
                        <Link v-if="canVisit('products.create')" :href="route('products.create')" class="dashboard-text-link"
                            >{{ t('dashboard.addFirstProduct') }}<ArrowRight :size="14" /></Link></template
                    ><template v-else
                        ><CheckCircle2 :size="20" aria-hidden="true" />
                        <p>{{ t('dashboard.allWellStocked') }}</p></template
                    >
                </div>
                <ul v-else class="dashboard-replenishment-list">
                    <li v-for="product in lowStockProducts" :key="product.id" class="dashboard-stock-row">
                        <div class="dashboard-product">
                            <span class="dashboard-product-icon"><Package :size="22" :stroke-width="1.5" aria-hidden="true" /></span>
                            <div>
                                <Link :href="route('products.show', product.id)" class="dashboard-product-name ds-focus-ring"
                                    >{{ product.name }}<ArrowUpRight :size="12" aria-hidden="true"
                                /></Link>
                                <p class="dashboard-product-meta">
                                    {{ product.category?.name || t('products.show.uncategorized')
                                    }}<template v-if="product.location"> · {{ product.location.name }}</template>
                                </p>
                            </div>
                        </div>
                        <div class="dashboard-stock-level">
                            <div>
                                <span
                                    >{{ t('warehouses.stockLevels.onHand') }}
                                    <strong>{{ formatPlainNumber(stockLevel(product).stock) }}</strong></span
                                ><span
                                    >{{ t('dashboard.design.minimum') }}
                                    <strong>{{ formatPlainNumber(stockLevel(product).minimum) }}</strong></span
                                >
                            </div>
                            <div
                                class="dashboard-stock-track"
                                role="img"
                                :aria-label="
                                    t('dashboard.design.stockLevel', {
                                        stock: formatPlainNumber(stockLevel(product).stock),
                                        minimum: formatPlainNumber(stockLevel(product).minimum),
                                    })
                                "
                            >
                                <span :style="{ width: `${stockLevel(product).percent}%` }"></span><b aria-hidden="true"></b>
                            </div>
                        </div>
                        <p class="dashboard-shortfall">
                            {{ t('dashboard.design.shortfall', { count: formatPlainNumber(stockLevel(product).shortfall) }) }}
                        </p>
                        <button type="button" class="dashboard-review ds-focus-ring" @click="selectedProduct = product">
                            <ClipboardList :size="14" aria-hidden="true" />{{ t('dashboard.design.reviewStock')
                            }}<ArrowRight :size="14" aria-hidden="true" />
                        </button>
                    </li>
                </ul>
            </section>

            <div
                v-if="widgets.recent_orders || widgets.stock_by_category || financialStats.length || quickActions.length"
                class="dashboard-content-grid"
                :class="{
                    'dashboard-content-single':
                        (!widgets.recent_orders && !widgets.stock_by_category) || (!financialStats.length && !quickActions.length),
                }"
            >
                <div
                    v-if="widgets.recent_orders || widgets.stock_by_category"
                    class="dashboard-main-grid"
                    :class="{ 'dashboard-one-column': !widgets.recent_orders || !widgets.stock_by_category }"
                >
                    <section v-if="widgets.recent_orders" class="dashboard-panel dashboard-orders" aria-labelledby="orders-title">
                        <div class="dashboard-panel-heading">
                            <div class="dashboard-panel-title">
                                <h2 id="orders-title">{{ t('dashboard.recentOrders') }}</h2>
                                <span class="dashboard-count">{{ formatNumber(recentOrders.length) }}</span>
                            </div>
                            <Link :href="route('orders.index')" class="dashboard-text-link ds-focus-ring"
                                >{{ t('dashboard.allOrders') }}<ArrowUpRight :size="14" aria-hidden="true"
                            /></Link>
                        </div>
                        <div v-if="!recentOrders.length" class="dashboard-empty">
                            <ShoppingCart :size="22" aria-hidden="true" />
                            <p>{{ t('dashboard.noOrdersYet') }}</p>
                            <Link v-if="canVisit('orders.create')" :href="route('orders.create')" class="dashboard-text-link"
                                >{{ t('dashboard.createFirstOrder') }}<ArrowRight :size="14"
                            /></Link>
                        </div>
                        <TableViewport v-else class="dashboard-order-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>{{ t('nav.orders') }} / {{ t('nav.customers') }}</th>
                                        <th>{{ t('common.status') }}</th>
                                        <th class="dashboard-date-column">{{ t('common.date') }}</th>
                                        <th class="dashboard-amount">{{ t('common.total') }}</th>
                                        <th class="dashboard-order-arrow">
                                            <span class="sr-only">{{ t('common.actions') }}</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="order in recentOrders" :key="order.id">
                                        <td>
                                            <Link :href="route('orders.show', order.id)" class="dashboard-order-number ds-focus-ring">{{
                                                order.order_number
                                            }}</Link
                                            ><span class="dashboard-customer">{{ order.customer_name }}</span>
                                        </td>
                                        <td>
                                            <span
                                                class="dashboard-order-status"
                                                :class="{ 'dashboard-order-pending': order.status === 'pending' }"
                                                ><component :is="ordersStatusIcon(order.status)" :size="11" aria-hidden="true" />{{
                                                    orderStatusLabel(order.status, { t, te })
                                                }}</span
                                            >
                                        </td>
                                        <td class="dashboard-date-column">{{ displayCalendarDate(order.order_date) }}</td>
                                        <td class="dashboard-amount">{{ formatCurrency(order.total, order.currency) }}</td>
                                        <td class="dashboard-order-arrow">
                                            <Link
                                                :href="route('orders.show', order.id)"
                                                :aria-label="order.order_number"
                                                class="dashboard-icon-button ds-focus-ring"
                                                ><ChevronRight :size="14" aria-hidden="true"
                                            /></Link>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </TableViewport>
                        <div class="dashboard-panel-footer">
                            <span>{{ formatNumber(recentOrders.length) }} / {{ formatNumber(stats.totalOrders) }} {{ t('nav.orders') }}</span>
                        </div>
                    </section>
                    <section v-if="widgets.stock_by_category" class="dashboard-panel dashboard-categories" aria-labelledby="categories-title">
                        <div class="dashboard-panel-heading">
                            <h2 id="categories-title">{{ t('dashboard.stockValueByCategory') }}</h2>
                        </div>
                        <div v-if="!categories.length" class="dashboard-empty">
                            <Package :size="22" aria-hidden="true" />
                            <p>{{ t('dashboard.noProductsYet') }}</p>
                        </div>
                        <template v-else
                            ><div class="dashboard-category-caption">
                                <span>{{ t('dashboard.atSellingPrice') }}</span
                                ><span>{{ currency }} / {{ t('dashboard.design.share') }}</span>
                            </div>
                            <ul class="dashboard-category-list">
                                <li
                                    v-for="category in categories"
                                    :key="category.id ?? 'uncategorized'"
                                    class="dashboard-category-row"
                                    tabindex="0"
                                    :title="`${categoryName(category)}: ${formatCurrency(category.value)} · ${formatShare(category.share)}`"
                                >
                                    <div class="dashboard-category-facts">
                                        <span class="dashboard-category-name">{{ categoryName(category) }}</span
                                        ><strong>{{ formatCurrency(category.value) }}</strong
                                        ><span class="dashboard-category-share">{{ formatShare(category.share) }}</span>
                                    </div>
                                    <p v-if="plusOthers(category.values)" class="dashboard-other-currencies">
                                        {{ plusOthers(category.values) }}
                                    </p>
                                    <div v-if="category.share !== null" class="dashboard-share-track" aria-hidden="true">
                                        <span :style="{ width: `${category.share}%` }"></span>
                                    </div>
                                </li>
                            </ul>
                            <div class="dashboard-panel-footer">
                                <span>{{ formatNumber(categoryProductCount) }} {{ t('common.products') }} · {{ currency }}</span
                                ><strong :title="formatCurrency(stats.totalValue)" data-testid="dashboard-category-total">{{
                                    formatCurrency(stats.totalValue)
                                }}</strong>
                            </div></template
                        >
                    </section>
                </div>
                <div
                    v-if="financialStats.length || quickActions.length"
                    class="dashboard-bottom-grid"
                    :class="{ 'dashboard-one-column': !financialStats.length || !quickActions.length }"
                >
                    <section v-if="financialStats.length" class="dashboard-panel" aria-labelledby="financial-title">
                        <div class="dashboard-panel-heading">
                            <h2 id="financial-title">{{ t('dashboard.design.financialWatch') }}</h2>
                            <Link v-if="canVisit('reports.index')" :href="route('reports.index')" class="dashboard-text-link"
                                >{{ t('nav.reports') }}<ArrowUpRight :size="14" aria-hidden="true"
                            /></Link>
                        </div>
                        <div class="dashboard-financial-items">
                            <component
                                :is="stat.href ? Link : 'div'"
                                v-for="stat in financialStats"
                                :key="stat.key"
                                :href="stat.href || undefined"
                                class="dashboard-financial-item ds-focus-ring"
                                :title="formatCurrency(stats[stat.key])"
                                ><span>{{ stat.label }}<ArrowUpRight v-if="stat.href" :size="12" aria-hidden="true" /></span
                                ><strong>{{ formatCompactCurrency(stats[stat.key]) }}</strong
                                ><small>{{ stat.context }} · {{ currency }}</small
                                ><small v-if="otherCurrencies(stat.key)">{{ otherCurrencies(stat.key) }}</small></component
                            >
                        </div>
                    </section>
                    <section v-if="quickActions.length" class="dashboard-panel" aria-labelledby="actions-title">
                        <div class="dashboard-panel-heading">
                            <h2 id="actions-title">{{ t('dashboard.quickActions') }}</h2>
                        </div>
                        <div class="dashboard-shortcuts">
                            <Link v-for="action in quickActions" :key="action.route" :href="route(action.route)" class="ds-focus-ring"
                                ><component :is="action.icon" :size="15" aria-hidden="true" /><span>{{ action.label }}</span
                                ><ArrowUpRight :size="13" aria-hidden="true"
                            /></Link>
                        </div>
                    </section>
                </div>
            </div>
            <details v-if="widgets.recent_products" class="dashboard-panel dashboard-detail-widget">
                <summary>
                    {{ t('dashboard.recentProducts') }} <span class="dashboard-count">{{ formatNumber(recentProducts.length) }}</span>
                </summary>
                <div v-if="!recentProducts.length" class="dashboard-empty">
                    <p>{{ t('dashboard.noProductsYet') }}</p>
                </div>
                <ul v-else class="dashboard-product-list">
                    <li v-for="product in recentProducts" :key="product.id">
                        <div>
                            <Link :href="route('products.show', product.id)" class="dashboard-product-name">{{ product.name }}</Link>
                            <p class="dashboard-product-meta">{{ product.category?.name || t('products.show.uncategorized') }}</p>
                        </div>
                        <div class="text-end">
                            <strong>{{ formatCurrency(product.price, product.currency) }}</strong>
                            <p class="dashboard-product-meta">
                                {{ t('dashboard.qty', { count: formatPlainNumber(product.effective_stock ?? product.stock) }) }}
                            </p>
                        </div>
                    </li>
                </ul>
            </details>

            <!-- Reorder Suggestions -->
            <section v-if="widgets.reorder_suggestions && reorderSuggestions.length > 0" class="mt-4">
                <Card :padded="false">
                    <div class="px-5 pt-5">
                        <CardHeader :title="t('dashboard.reorderSuggestions.title')" :subtitle="t('dashboard.reorderSuggestions.subtitle')">
                            <template #actions>
                                <Badge variant="warning" size="sm">{{ reorderSuggestions.length }}</Badge>
                                <Link
                                    :href="route('products.index', { low_stock: true })"
                                    class="text-xs text-text-tertiary transition-colors hover:text-text-primary"
                                >
                                    {{ t('dashboard.viewAll') }}
                                </Link>
                            </template>
                        </CardHeader>
                    </div>
                    <div class="px-2 pb-2 pt-1">
                        <TableViewport class="w-full overflow-x-auto rounded-lg bg-surface-raised">
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
                                        <th :class="[reorderThClass, 'text-end']">{{ t('products.stock') }}</th>
                                        <th :class="[reorderThClass, 'text-end']">{{ t('dashboard.reorderSuggestions.reorderAt') }}</th>
                                        <th :class="[reorderThClass, 'text-end']">{{ t('quickReorder.suggestedQty') }}</th>
                                        <th v-if="quickReorder.canCreatePo.value" :class="[reorderThClass, 'text-end']">
                                            <span class="sr-only">{{ t('common.actions') }}</span>
                                        </th>
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
                                                :disabled="!row.supplier_id || row.can_quick_reorder === false"
                                                :aria-label="t('quickReorder.selectForReorder', { name: row.name })"
                                                @change="quickReorder.toggle(row.id)"
                                            />
                                        </td>
                                        <td class="px-3 py-2">
                                            <Link :href="route('products.show', row.id)" class="flex flex-col">
                                                <span class="font-medium text-text-primary hover:underline">{{ row.name }}</span>
                                                <span class="text-xs text-text-tertiary"
                                                    >{{ row.sku }}<span v-if="row.category"> · {{ row.category }}</span></span
                                                >
                                                <span v-if="row.warehouses?.length" class="text-xs text-status-warning">{{
                                                    t('warehouses.stockLevels.shortIn', { names: row.warehouses.join(', ') })
                                                }}</span>
                                            </Link>
                                        </td>
                                        <td class="px-3 py-2">
                                            <span v-if="row.supplier" class="text-text-secondary">{{ row.supplier }}</span>
                                            <span v-else class="flex flex-col">
                                                <span class="text-xs italic text-status-danger">{{ t('quickReorder.noSupplier') }}</span>
                                                <Link
                                                    v-if="canVisit('products.edit')"
                                                    :href="route('products.edit', row.id)"
                                                    class="text-xs font-medium text-brand hover:underline"
                                                    >{{ t('quickReorder.addSupplier') }}</Link
                                                >
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-end">
                                            <Badge :variant="row.stock === 0 ? 'danger' : 'warning'" size="sm" dot>{{ row.stock }}</Badge>
                                        </td>
                                        <td class="px-3 py-2 text-end tabular-nums text-text-secondary">{{ row.reorder_point }}</td>
                                        <td class="px-3 py-2 text-end font-medium tabular-nums text-text-primary">
                                            {{ row.suggested_quantity }}
                                        </td>
                                        <td v-if="quickReorder.canCreatePo.value" class="px-3 py-2 text-end">
                                            <Button
                                                v-if="row.supplier_id && row.can_quick_reorder !== false"
                                                variant="ghost"
                                                size="xs"
                                                :disabled="quickReorder.submitting.value"
                                                @click="quickReorder.createPurchaseOrders([row.id])"
                                            >
                                                <ClipboardList :size="12" />
                                                {{ t('quickReorder.createPo') }}
                                            </Button>
                                            <Button
                                                v-else-if="row.can_quick_reorder === false"
                                                variant="ghost"
                                                size="xs"
                                                as="Link"
                                                :href="route('purchase-orders.create')"
                                                :title="t('quickReorder.variantHint')"
                                            >
                                                {{ t('quickReorder.chooseVariants') }}
                                            </Button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </TableViewport>
                        <div
                            v-if="quickReorder.canCreatePo.value"
                            class="flex flex-col gap-2 px-3 pb-2 pt-3 sm:flex-row sm:items-center sm:justify-between"
                        >
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

            <div class="clear-both">
                <PluginWidgets :widgets="pluginWidgets" />
                <PluginSlot slot="widgets" :components="pluginComponents.widgets" />
                <PluginSlot slot="after-content" :components="pluginComponents.afterContent" />
                <PluginSlot slot="footer" :components="pluginComponents.footer" />
            </div>

            <Modal
                :show="showCustomizeModal"
                max-width="lg"
                :aria-label="t('dashboard.customizeModal.title')"
                @close="showCustomizeModal = false"
                ><div class="dashboard-dialog">
                    <div class="dashboard-dialog-heading">
                        <h2>{{ t('dashboard.customizeModal.title') }}</h2>
                        <button
                            type="button"
                            class="dashboard-icon-button ds-focus-ring"
                            :aria-label="t('common.cancel')"
                            @click="showCustomizeModal = false"
                        >
                            <X :size="18" />
                        </button>
                    </div>
                    <p>{{ t('dashboard.customizeModal.help') }}</p>
                    <div class="dashboard-widget-options">
                        <label v-for="(label, key) in widgetLabels" :key="key"
                            ><span>{{ label }}</span
                            ><input
                                v-model="widgetDraft[key]"
                                type="checkbox"
                                class="rounded-sm border-border-strong text-brand ds-focus-ring"
                        /></label>
                    </div>
                    <p v-if="preferenceError" role="alert" class="text-status-danger">{{ t('dashboard.design.saveError') }}</p>
                    <div class="dashboard-dialog-actions">
                        <Button variant="outline" @click="showCustomizeModal = false">{{ t('common.cancel') }}</Button
                        ><Button :loading="saving" @click="saveWidgetPreferences">{{
                            t('dashboard.customizeModal.savePreferences')
                        }}</Button>
                    </div>
                </div></Modal
            >
            <Modal :show="showSystemModal" max-width="lg" :aria-label="t('dashboard.systemHealth.title')" @close="showSystemModal = false"
                ><div class="dashboard-dialog">
                    <div class="dashboard-dialog-heading">
                        <h2>{{ t('dashboard.systemHealth.title') }}</h2>
                        <button
                            class="dashboard-icon-button ds-focus-ring"
                            :aria-label="t('common.cancel')"
                            @click="showSystemModal = false"
                        >
                            <X :size="18" />
                        </button>
                    </div>
                    <p v-for="warning in systemWarnings" :key="warning.type">{{ systemWarningMessage(warning) }}</p>
                    <p>{{ t('dashboard.systemHealth.help') }}</p>
                    <div class="dashboard-dialog-actions">
                        <Button variant="outline" @click="showSystemModal = false">{{ t('common.cancel') }}</Button>
                    </div>
                </div></Modal
            >
            <Modal :show="!!selectedProduct" max-width="lg" :aria-label="t('dashboard.design.reviewStock')" @close="selectedProduct = null"
                ><div v-if="selectedProduct" class="dashboard-dialog">
                    <div class="dashboard-dialog-heading">
                        <h2>{{ t('dashboard.design.reviewStock') }}</h2>
                        <button
                            class="dashboard-icon-button ds-focus-ring"
                            :aria-label="t('common.cancel')"
                            @click="selectedProduct = null"
                        >
                            <X :size="18" />
                        </button>
                    </div>
                    <h3>{{ selectedProduct.name }}</h3>
                    <dl class="dashboard-stock-facts">
                        <div>
                            <dt>{{ t('warehouses.stockLevels.onHand') }}</dt>
                            <dd>{{ formatPlainNumber(stockLevel(selectedProduct).stock) }}</dd>
                        </div>
                        <div>
                            <dt>{{ t('dashboard.design.minimum') }}</dt>
                            <dd>{{ formatPlainNumber(stockLevel(selectedProduct).minimum) }}</dd>
                        </div>
                        <div>
                            <dt>{{ t('dashboard.hints.belowMinimum') }}</dt>
                            <dd>{{ formatPlainNumber(stockLevel(selectedProduct).shortfall) }}</dd>
                        </div>
                        <template v-if="selectedSuggestion"
                            ><div>
                                <dt>{{ t('purchaseOrders.supplier') }}</dt>
                                <dd>{{ selectedSuggestion.supplier || t('quickReorder.noSupplier') }}</dd>
                            </div>
                            <div>
                                <dt>{{ t('quickReorder.suggestedQty') }}</dt>
                                <dd>{{ formatPlainNumber(selectedSuggestion.suggested_quantity) }}</dd>
                            </div></template
                        >
                    </dl>
                    <p>{{ selectedSuggestion ? t('dashboard.reorderSuggestions.subtitle') : t('dashboard.design.shortfallHelp') }}</p>
                    <div class="dashboard-dialog-actions">
                        <Button
                            v-if="canVisit('products.show')"
                            variant="outline"
                            as="Link"
                            :href="route('products.show', selectedProduct.id)"
                            >{{ t('dashboard.viewInventory') }}</Button
                        ><Button
                            v-if="quickReorder.canCreatePo.value && selectedSuggestion?.supplier_id"
                            :loading="quickReorder.submitting.value"
                            @click="quickReorder.createPurchaseOrders([selectedProduct.id])"
                            >{{ t('quickReorder.createPo') }}</Button
                        >
                    </div>
                </div></Modal
            >
        </div>
    </AppLayout>
</template>
