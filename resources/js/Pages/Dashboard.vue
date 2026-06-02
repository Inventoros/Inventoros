<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import DataTable from '@/Components/ui/DataTable.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
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
} from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    stats: Object,
    recentProducts: Array,
    lowStockProducts: Array,
    reorderSuggestions: Array,
    recentOrders: Array,
    stockByCategory: Array,
    widgetPreferences: Object,
    pluginComponents: Object,
});

const showCustomizeModal = ref(false);
const saving = ref(false);

const widgetLabels = {
    stats_overview: 'Stats Overview',
    revenue_chart: 'Revenue & Secondary Stats',
    stock_movements: 'Stock Movements',
    low_stock_alerts: 'Low Stock Alerts',
    recent_orders: 'Recent Orders',
    recent_products: 'Recent Products',
    top_products: 'Top Products',
    stock_by_category: 'Stock by Category',
    reorder_suggestions: 'Reorder Suggestions',
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

const formatCurrency = (value) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value ?? 0);

const formatNumber = (value) => {
    const v = Number(value ?? 0);
    if (v >= 1000000) return (v / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    if (v >= 1000) return (v / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
    return v.toString();
};

const formatCompactCurrency = (value) => {
    const f = formatNumber(value);
    return f.endsWith('K') || f.endsWith('M') ? '$' + f : formatCurrency(value);
};

const orderStatusVariant = (status) =>
    ({
        pending: 'warning',
        processing: 'info',
        shipped: 'brand',
        delivered: 'success',
        cancelled: 'danger',
    }[status] || 'neutral');

const reorderColumns = [
    { key: 'name', label: 'Product' },
    { key: 'supplier', label: 'Supplier' },
    { key: 'stock', label: 'Stock', align: 'right' },
    { key: 'reorder_point', label: 'Reorder at', align: 'right' },
    { key: 'reorder_quantity', label: 'Order qty', align: 'right' },
];

// Secondary stat tiles (revenue_chart widget)
const secondaryStats = () => [
    { label: t('dashboard.pendingOrders'), value: formatNumber(props.stats?.pendingOrders), href: route('orders.index', { status: 'pending' }), tone: 'text-status-warning' },
    { label: t('dashboard.categories'), value: props.stats?.categories, href: route('categories.index'), tone: 'text-brand' },
    { label: t('dashboard.locations'), value: props.stats?.locations, href: route('locations.index'), tone: 'text-brand' },
    { label: t('dashboard.inventoryValue'), value: formatCompactCurrency(props.stats?.totalValue), href: null, tone: 'text-status-success' },
    { label: t('dashboard.revenueThisMonth'), value: formatCompactCurrency(props.stats?.revenueThisMonth), href: null, tone: 'text-brand' },
];
</script>

<template>
    <Head :title="t('dashboard.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('dashboard.title') }}</span>
            </div>
        </template>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <PageHeader
            :title="t('dashboard.title')"
            description="Snapshot of inventory, orders, and reorder priorities."
        >
            <template #actions>
                <Button variant="secondary" size="sm" @click="showCustomizeModal = true">
                    <Settings2 :size="14" />
                    Customize
                </Button>
                <Button variant="default" size="sm" as="Link" :href="route('orders.create')">
                    <Plus :size="14" />
                    {{ t('dashboard.newOrder') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Plugin Slot: Before Stats -->
        <PluginSlot slot="before-stats" :components="pluginComponents?.beforeStats" />

        <!-- Primary stats -->
        <section v-if="widgets.stats_overview" class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile
                :label="t('dashboard.totalProducts')"
                :value="formatNumber(stats.totalProducts)"
                hint="active in catalog"
                icon-tone="brand"
            >
                <template #icon><Boxes :size="20" :stroke-width="1.5" /></template>
            </StatTile>
            <StatTile
                :label="t('dashboard.totalValue')"
                :value="formatCompactCurrency(stats.totalValue)"
                hint="at cost"
                icon-tone="success"
            >
                <template #icon><DollarSign :size="20" :stroke-width="1.5" /></template>
            </StatTile>
            <StatTile
                :label="t('dashboard.lowStock')"
                :value="formatNumber(stats.lowStockProducts)"
                :delta="stats.lowStockProducts > 0 ? 'attention' : null"
                :delta-tone="stats.lowStockProducts > 0 ? 'down' : 'neutral'"
                hint="below minimum"
                icon-tone="warning"
            >
                <template #icon><AlertTriangle :size="20" :stroke-width="1.5" /></template>
            </StatTile>
            <StatTile
                :label="t('dashboard.totalOrders')"
                :value="formatNumber(stats.totalOrders)"
                hint="all time"
                icon-tone="violet"
            >
                <template #icon><ShoppingCart :size="20" :stroke-width="1.5" /></template>
            </StatTile>
        </section>

        <!-- Secondary stats -->
        <section v-if="widgets.revenue_chart" class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
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
                                        <Badge :variant="orderStatusVariant(order.status)" size="sm" dot>{{ order.status }}</Badge>
                                    </div>
                                    <p class="mt-0.5 truncate text-xs text-text-tertiary">
                                        {{ order.customer_name }} · {{ order.items.length }} items
                                    </p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="text-sm font-semibold tabular-nums text-text-primary">{{ formatCurrency(order.total) }}</p>
                                    <p class="text-[11px] text-text-tertiary">{{ new Date(order.order_date).toLocaleDateString() }}</p>
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
                                <p class="text-sm font-semibold tabular-nums text-text-primary">{{ formatCurrency(product.price) }}</p>
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
                    <CardHeader title="Reorder suggestions" subtitle="Below reorder point. Suggested quantity comes from product config.">
                        <template #actions>
                            <Badge variant="warning" size="sm">{{ reorderSuggestions.length }}</Badge>
                            <Link :href="route('products.index', { low_stock: true })" class="text-xs text-text-tertiary transition-colors hover:text-text-primary">
                                {{ t('dashboard.viewAll') }}
                            </Link>
                        </template>
                    </CardHeader>
                </div>
                <div class="px-2 pb-2 pt-1">
                    <DataTable :columns="reorderColumns" :rows="reorderSuggestions" :row-href="(p) => route('products.edit', p.id)" dense class="border-0">
                        <template #cell-name="{ row }">
                            <div class="flex flex-col">
                                <span class="font-medium text-text-primary">{{ row.name }}</span>
                                <span class="text-xs text-text-tertiary">{{ row.sku }}<span v-if="row.category"> · {{ row.category }}</span></span>
                            </div>
                        </template>
                        <template #cell-supplier="{ row }">
                            <span v-if="row.supplier" class="text-text-secondary">{{ row.supplier }}</span>
                            <span v-else class="text-xs italic text-status-danger">No supplier</span>
                        </template>
                        <template #cell-stock="{ row }">
                            <Badge :variant="row.stock === 0 ? 'danger' : 'warning'" size="sm" dot>{{ row.stock }}</Badge>
                        </template>
                        <template #cell-reorder_point="{ row }">
                            <span class="tabular-nums text-text-secondary">{{ row.reorder_point }}</span>
                        </template>
                        <template #cell-reorder_quantity="{ row }">
                            <span class="font-medium tabular-nums text-text-primary">{{ row.reorder_quantity ?? '—' }}</span>
                        </template>
                        <template #empty>
                            <div class="flex flex-col items-center gap-2 py-6">
                                <PackageX :size="20" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">No items below reorder point.</p>
                            </div>
                        </template>
                    </DataTable>
                </div>
            </Card>
        </section>

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
                        <h3 class="text-base font-semibold text-text-primary">Customize dashboard</h3>
                        <button @click="showCustomizeModal = false" class="text-text-tertiary transition-colors hover:text-text-primary">
                            <X :size="18" />
                        </button>
                    </div>

                    <p class="mb-4 text-sm text-text-secondary">Toggle widgets to show or hide them on your dashboard.</p>

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
                            {{ saving ? 'Saving…' : 'Save preferences' }}
                        </Button>
                        <Button variant="secondary" @click="showCustomizeModal = false">Cancel</Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
