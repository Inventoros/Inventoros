<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    ArrowLeft,
    Download,
    ShoppingCart,
    DollarSign,
    Boxes,
    TrendingUp,
} from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    summary: Object,
    byStatus: Array,
    topProducts: Array,
    dailySales: Array,
    filters: Object,
});

const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');

const applyFilters = () => {
    router.get(route('reports.sales-analysis'), {
        date_from: dateFrom.value,
        date_to: dateTo.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};

const exportReport = () => {
    window.print();
};

const statusVariant = (status) =>
    ({
        pending: 'warning',
        processing: 'info',
        shipped: 'brand',
        delivered: 'success',
        cancelled: 'danger',
    }[status] || 'neutral');

// Bar heights for the sales-by-status visual, scaled to the largest revenue.
const maxStatusRevenue = computed(() =>
    Math.max(1, ...(props.byStatus || []).map((item) => Number(item.revenue) || 0))
);

const barHeight = (revenue) => {
    const pct = (Number(revenue) || 0) / maxStatusRevenue.value;
    return `${Math.max(4, Math.round(pct * 100))}%`;
};

const inputClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
const thClass =
    'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('reports.salesAnalysis.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('reports.index')" class="text-text-tertiary transition-colors hover:text-text-primary">{{ t('reports.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('reports.salesAnalysis.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('reports.salesAnalysis.title')" :description="t('reports.salesAnalysis.description')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('reports.index')">
                    <ArrowLeft :size="14" />
                    {{ t('reports.backToReports') }}
                </Button>
                <Button variant="default" size="sm" @click="exportReport">
                    <Download :size="14" />
                    {{ t('common.export') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Date filter -->
        <Card class="mt-6">
            <form @submit.prevent="applyFilters" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <label for="date_from" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('reports.salesAnalysis.dateFrom') }}</label>
                        <input id="date_from" v-model="dateFrom" type="date" :class="inputClass" />
                    </div>
                    <div>
                        <label for="date_to" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('reports.salesAnalysis.dateTo') }}</label>
                        <input id="date_to" v-model="dateTo" type="date" :class="inputClass" />
                    </div>
                    <div class="flex items-end">
                        <Button type="submit" variant="default" size="sm">{{ t('reports.salesAnalysis.apply') }}</Button>
                    </div>
                </div>
            </form>
        </Card>

        <!-- Summary metrics -->
        <section class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile
                :label="t('reports.salesAnalysis.totalOrders')"
                :value="summary.total_orders"
                icon-tone="brand"
            >
                <template #icon><ShoppingCart :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.salesAnalysis.totalRevenue')"
                :value="formatCurrency(summary.total_revenue)"
                icon-tone="success"
            >
                <template #icon><DollarSign :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.salesAnalysis.itemsSold')"
                :value="summary.total_items_sold"
                icon-tone="brand"
            >
                <template #icon><Boxes :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.salesAnalysis.avgOrderValue')"
                :value="formatCurrency(summary.average_order_value)"
                icon-tone="success"
            >
                <template #icon><TrendingUp :size="18" /></template>
            </StatTile>
        </section>

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <!-- Sales by status (bar visual) -->
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('reports.salesAnalysis.salesByStatus')" />
                </div>
                <div class="p-5">
                    <div class="flex items-end justify-around gap-3 h-40 border-b border-border-subtle pb-px">
                        <div v-for="item in byStatus" :key="item.status" class="flex h-full flex-1 flex-col items-center justify-end gap-2">
                            <span class="text-xs font-medium tabular-nums text-text-secondary">{{ formatCurrency(item.revenue) }}</span>
                            <div
                                class="w-full max-w-[3rem] rounded-t-md bg-brand"
                                :style="{ height: barHeight(item.revenue) }"
                            ></div>
                        </div>
                    </div>
                    <div class="mt-3 flex items-start justify-around gap-3">
                        <div v-for="item in byStatus" :key="item.status" class="flex flex-1 flex-col items-center gap-1 text-center">
                            <Badge :variant="statusVariant(item.status)" size="sm" dot class="capitalize">{{ item.status }}</Badge>
                            <span class="text-xs text-text-tertiary">{{ item.count }} {{ t('nav.orders').toLowerCase() }}</span>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Top products -->
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('reports.salesAnalysis.topSelling')" />
                </div>
                <div class="p-3">
                    <ul class="space-y-0.5">
                        <li
                            v-for="(product, index) in topProducts"
                            :key="index"
                            class="flex items-center justify-between gap-3 rounded-md px-2 py-2.5 transition-colors hover:bg-surface-overlay"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-text-primary">{{ product.product_name }}</p>
                                <p class="text-xs text-text-tertiary">{{ product.quantity_sold }} {{ t('reports.salesAnalysis.unitsSold') }}</p>
                            </div>
                            <span class="shrink-0 text-sm font-semibold tabular-nums text-status-success">{{ formatCurrency(product.revenue) }}</span>
                        </li>
                    </ul>
                </div>
            </Card>
        </div>

        <!-- Daily sales trend -->
        <section class="mt-4">
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('reports.salesAnalysis.dailySalesTrend')" />
                </div>
                <div class="mt-4 w-full overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border-subtle">
                                <th :class="thClass">{{ t('common.date') }}</th>
                                <th :class="[thClass, 'text-right']">{{ t('nav.orders') }}</th>
                                <th :class="[thClass, 'text-right']">{{ t('reports.salesAnalysis.revenue') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="day in dailySales"
                                :key="day.date"
                                class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                            >
                                <td class="px-4 py-3 text-text-primary">{{ new Date(day.date).toLocaleDateString() }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ day.orders }}</td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums text-status-success">{{ formatCurrency(day.revenue) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </section>
    </AppLayout>
</template>
