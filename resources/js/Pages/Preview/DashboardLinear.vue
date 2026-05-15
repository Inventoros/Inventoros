<script setup>
/**
 * Linear-inspired dashboard preview.
 * Renders against the same data the live Dashboard.vue receives.
 *
 * Visit: /preview/dashboard
 *
 * This is intentionally smaller than the legacy dashboard — it shows
 * the design language (density, monochromatic palette, calm hover
 * states, lucide iconography) and proves the AppLayout + UI primitives
 * compose well. The full widget customisation will land when the page
 * is migrated for real.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    Boxes,
    AlertTriangle,
    ShoppingCart,
    DollarSign,
    ArrowUpRight,
    PackageX,
    Activity,
} from 'lucide-vue-next';

import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import DataTable from '@/Components/ui/DataTable.vue';

const props = defineProps({
    stats: Object,
    recentProducts: Array,
    lowStockProducts: Array,
    reorderSuggestions: Array,
    recentOrders: Array,
});

const fmtCurrency = (v) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: 'USD', maximumFractionDigits: 0,
}).format(Number(v ?? 0));

const fmtNumber = (v) => new Intl.NumberFormat('en-US').format(Number(v ?? 0));

const orderStatusVariant = (status) => ({
    pending: 'warning',
    processing: 'info',
    shipped: 'brand',
    delivered: 'success',
    cancelled: 'danger',
}[status] || 'neutral');

const lowStockColumns = [
    { key: 'name', label: 'Product' },
    { key: 'sku', label: 'SKU' },
    { key: 'stock', label: 'On hand', align: 'right' },
    { key: 'min_stock', label: 'Min', align: 'right' },
];

const orderColumns = [
    { key: 'order_number', label: 'Order' },
    { key: 'customer_name', label: 'Customer' },
    { key: 'status', label: 'Status' },
    { key: 'total', label: 'Total', align: 'right' },
];

const reorderColumns = [
    { key: 'name', label: 'Product' },
    { key: 'supplier', label: 'Supplier' },
    { key: 'stock', label: 'Stock', align: 'right' },
    { key: 'reorder_quantity', label: 'Suggest order', align: 'right' },
];

const lowStockRows = computed(() => props.lowStockProducts ?? []);
const recentOrderRows = computed(() => props.recentOrders ?? []);
const reorderRows = computed(() => props.reorderSuggestions ?? []);
</script>

<template>
    <Head title="Dashboard (preview)" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="text-text-primary font-medium">Dashboard</span>
                <Badge variant="brand" size="sm" class="ml-2">Preview</Badge>
            </div>
        </template>

        <PageHeader
            title="Dashboard"
            description="Snapshot of inventory, orders, and reorder priorities."
        >
            <template #actions>
                <Button variant="secondary" size="sm">Export</Button>
                <Button variant="default" size="sm" as="Link" :href="route('orders.create')">
                    <ShoppingCart :size="14" />
                    New order
                </Button>
            </template>
        </PageHeader>

        <!-- Stats grid -->
        <section class="mt-6 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
            <StatTile
                label="Products"
                :value="fmtNumber(stats?.totalProducts)"
                hint="active in catalog"
            >
                <template #icon><Boxes :size="14" class="text-text-tertiary" /></template>
            </StatTile>
            <StatTile
                label="Inventory value"
                :value="fmtCurrency(stats?.totalValue)"
                hint="at cost"
            >
                <template #icon><DollarSign :size="14" class="text-text-tertiary" /></template>
            </StatTile>
            <StatTile
                label="Low stock"
                :value="fmtNumber(stats?.lowStockProducts)"
                :delta="stats?.lowStockProducts > 0 ? 'attention' : null"
                :delta-tone="stats?.lowStockProducts > 0 ? 'down' : 'neutral'"
                hint="below min"
            >
                <template #icon><AlertTriangle :size="14" class="text-text-tertiary" /></template>
            </StatTile>
            <StatTile
                label="Revenue (mo)"
                :value="fmtCurrency(stats?.revenueThisMonth)"
                hint="month-to-date"
            >
                <template #icon><ArrowUpRight :size="14" class="text-text-tertiary" /></template>
            </StatTile>
        </section>

        <!-- Two-column main -->
        <section class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
            <!-- Reorder suggestions — most actionable card, gets the wide column -->
            <Card class="lg:col-span-2" :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader
                        title="Reorder suggestions"
                        subtitle="Below reorder point. Suggested quantity comes from product config."
                    >
                        <template #actions>
                            <Button variant="ghost" size="xs" as="Link" :href="route('purchase-orders.create')">
                                Draft PO
                            </Button>
                        </template>
                    </CardHeader>
                </div>
                <div class="px-2 pb-2 pt-1">
                    <DataTable
                        :columns="reorderColumns"
                        :rows="reorderRows"
                        dense
                        class="border-0"
                    >
                        <template #cell-name="{ row }">
                            <div class="flex flex-col">
                                <span class="font-medium text-text-primary">{{ row.name }}</span>
                                <span class="text-text-tertiary text-xs">{{ row.sku }}</span>
                            </div>
                        </template>
                        <template #cell-supplier="{ row }">
                            <span class="text-text-secondary">{{ row.supplier || '—' }}</span>
                        </template>
                        <template #cell-stock="{ row }">
                            <Badge variant="warning" size="sm" dot>{{ row.stock }}</Badge>
                        </template>
                        <template #cell-reorder_quantity="{ row }">
                            <span class="font-medium tabular-nums">{{ row.reorder_quantity ?? '—' }}</span>
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

            <!-- Low stock list -->
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader
                        title="Low stock"
                        subtitle="At or below minimum."
                    >
                        <template #actions>
                            <Link
                                :href="route('products.index', { low_stock: 1 })"
                                class="text-xs text-text-tertiary hover:text-text-primary transition-colors"
                            >
                                View all
                            </Link>
                        </template>
                    </CardHeader>
                </div>
                <ul class="divide-y divide-border-subtle">
                    <li
                        v-for="item in lowStockRows.slice(0, 5)"
                        :key="item.id"
                        class="px-5 py-3 hover:bg-surface-overlay transition-colors"
                    >
                        <Link :href="route('products.show', item.id)" class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-text-primary truncate">{{ item.name }}</p>
                                <p class="text-xs text-text-tertiary truncate">{{ item.sku }}</p>
                            </div>
                            <Badge variant="warning" size="sm" dot>
                                {{ item.stock }} / {{ item.min_stock }}
                            </Badge>
                        </Link>
                    </li>
                    <li v-if="lowStockRows.length === 0" class="px-5 py-8 text-center text-sm text-text-tertiary">
                        Everything stocked.
                    </li>
                </ul>
            </Card>
        </section>

        <!-- Recent orders -->
        <section class="mt-4">
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader title="Recent orders" subtitle="The last 5 orders from any source.">
                        <template #actions>
                            <Button variant="ghost" size="xs" as="Link" :href="route('orders.index')">
                                All orders
                            </Button>
                        </template>
                    </CardHeader>
                </div>
                <div class="px-2 pb-2 pt-1">
                    <DataTable
                        :columns="orderColumns"
                        :rows="recentOrderRows"
                        :row-href="(o) => route('orders.show', o.id)"
                        dense
                        class="border-0"
                    >
                        <template #cell-order_number="{ row }">
                            <span class="font-mono text-xs">{{ row.order_number }}</span>
                        </template>
                        <template #cell-status="{ row }">
                            <Badge :variant="orderStatusVariant(row.status)" size="sm" dot>
                                {{ row.status }}
                            </Badge>
                        </template>
                        <template #cell-total="{ row }">
                            <span class="font-medium tabular-nums">{{ fmtCurrency(row.total) }}</span>
                        </template>
                        <template #empty>
                            <div class="flex flex-col items-center gap-2 py-6">
                                <Activity :size="20" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">No orders yet.</p>
                            </div>
                        </template>
                    </DataTable>
                </div>
            </Card>
        </section>
    </AppLayout>
</template>
