<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import Button from '@/Components/ui/Button.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import ExportMenu from '@/Components/Reports/ExportMenu.vue';
import PeriodFilter from '@/Components/Reports/PeriodFilter.vue';
import { formatCurrency, formatNumber } from '@/lib/reportFormat';
import { formatNumber as formatPlainNumber } from '@/lib/money';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, RefreshCw, CalendarClock, DollarSign, Info } from 'lucide-vue-next';

const { t } = useI18n();

defineProps({
    products: Array,
    categories: Array,
    summary: Object,
    truncated: Boolean,
    filters: Object,
});

const ratio = (value) => (value === null || value === undefined ? t('reports.inventoryTurnover.notAvailable') : `${formatPlainNumber(value, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}x`);
const daysOf = (value) => (value === null || value === undefined ? t('reports.inventoryTurnover.notAvailable') : formatNumber(value));

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
const thRightClass = 'px-4 py-2.5 text-right text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('reports.inventoryTurnover.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('reports.index')" class="text-text-tertiary transition-colors hover:text-text-primary">{{ t('reports.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('reports.inventoryTurnover.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('reports.inventoryTurnover.title')" :description="t('reports.inventoryTurnover.description')">
            <template #actions>
                <ExportMenu route-name="reports.inventory-turnover" :params="filters" />
                <Button variant="secondary" size="sm" as="Link" :href="route('reports.index')">
                    <ArrowLeft :size="14" />
                    {{ t('reports.backToReports') }}
                </Button>
            </template>
        </PageHeader>

        <PeriodFilter route-name="reports.inventory-turnover" :filters="filters" />

        <Card class="mt-4">
            <div class="flex items-start gap-3">
                <Info :size="16" class="mt-0.5 shrink-0 text-status-info" />
                <div class="space-y-1 text-xs text-text-secondary">
                    <p class="font-medium text-text-primary">{{ t('reports.inventoryTurnover.methodTitle') }}</p>
                    <p>{{ t('reports.inventoryTurnover.method') }}</p>
                    <p>{{ t('reports.inventoryTurnover.cogsNote') }}</p>
                    <p v-if="summary.units_estimated_cost > 0" class="text-status-warning">
                        {{ t('reports.inventoryTurnover.estimatedCost', { count: summary.units_estimated_cost }, summary.units_estimated_cost) }}
                    </p>
                </div>
            </div>
        </Card>

        <section class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile :label="t('reports.inventoryTurnover.turnover')" :value="ratio(summary.turnover)" icon-tone="brand">
                <template #icon><RefreshCw :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.inventoryTurnover.daysOfInventory')"
                :value="daysOf(summary.days_of_inventory)"
                :hint="t('reports.inventoryTurnover.periodDays', { n: summary.period_days }, summary.period_days)"
                icon-tone="info"
            >
                <template #icon><CalendarClock :size="18" /></template>
            </StatTile>
            <StatTile :label="t('reports.inventoryTurnover.cogs')" :value="formatCurrency(summary.cogs)" icon-tone="success">
                <template #icon><DollarSign :size="18" /></template>
            </StatTile>
            <StatTile :label="t('reports.inventoryTurnover.avgInventory')" :value="formatCurrency(summary.average_value)" icon-tone="violet">
                <template #icon><DollarSign :size="18" /></template>
            </StatTile>
        </section>

        <section class="mt-4">
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('reports.inventoryTurnover.byCategory')">
                        <template #actions>
                            <ExportMenu route-name="reports.inventory-turnover" :params="filters" group="category" />
                        </template>
                    </CardHeader>
                </div>
                <div class="w-full overflow-x-auto p-3">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border-subtle">
                                <th :class="thClass">{{ t('reports.common.category') }}</th>
                                <th :class="thRightClass">{{ t('reports.inventoryTurnover.unitsSold') }}</th>
                                <th :class="thRightClass">{{ t('reports.inventoryTurnover.avgInventory') }}</th>
                                <th :class="thRightClass">{{ t('reports.inventoryTurnover.cogs') }}</th>
                                <th :class="thRightClass">{{ t('reports.inventoryTurnover.turnover') }}</th>
                                <th :class="thRightClass">{{ t('reports.inventoryTurnover.daysOfInventory') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in categories"
                                :key="row.category_id ?? 'none'"
                                class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                            >
                                <td class="px-4 py-3 font-medium text-text-primary">{{ row.category || t('reports.common.uncategorized') }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatNumber(row.units_sold) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatCurrency(row.average_value) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatCurrency(row.cogs) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-text-primary">{{ ratio(row.turnover) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ daysOf(row.days_of_inventory) }}</td>
                            </tr>
                            <tr v-if="categories.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-text-tertiary">{{ t('reports.common.noData') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </section>

        <section class="mt-4">
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('reports.inventoryTurnover.byProduct')" />
                </div>
                <div class="p-3">
                    <p v-if="truncated" class="px-2 pb-2 text-xs text-status-warning">{{ t('reports.common.truncated', { count: products.length }) }}</p>
                    <div class="w-full overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border-subtle">
                                    <th :class="thClass">{{ t('reports.common.product') }}</th>
                                    <th :class="thRightClass">{{ t('reports.inventoryTurnover.unitsSold') }}</th>
                                    <th :class="thRightClass">{{ t('reports.inventoryTurnover.opening') }}</th>
                                    <th :class="thRightClass">{{ t('reports.inventoryTurnover.closing') }}</th>
                                    <th :class="thRightClass">{{ t('reports.inventoryTurnover.avgInventory') }}</th>
                                    <th :class="thRightClass">{{ t('reports.inventoryTurnover.cogs') }}</th>
                                    <th :class="thRightClass">{{ t('reports.inventoryTurnover.turnover') }}</th>
                                    <th :class="thRightClass">{{ t('reports.inventoryTurnover.daysOfInventory') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in products"
                                    :key="row.id"
                                    class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                                >
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-text-primary">{{ row.name }}</p>
                                        <p class="font-mono text-xs text-text-tertiary">{{ row.sku }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatNumber(row.units_sold) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatNumber(row.opening_units) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatNumber(row.closing_units) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatCurrency(row.average_value) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatCurrency(row.cogs) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-text-primary">{{ ratio(row.turnover) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ daysOf(row.days_of_inventory) }}</td>
                                </tr>
                                <tr v-if="products.length === 0">
                                    <td colspan="8" class="px-4 py-8 text-center text-sm text-text-tertiary">{{ t('reports.common.noData') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </Card>
        </section>
    </AppLayout>
</template>
