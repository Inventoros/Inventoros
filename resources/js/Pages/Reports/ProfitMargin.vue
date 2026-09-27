<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import ExportMenu from '@/Components/Reports/ExportMenu.vue';
import PeriodFilter from '@/Components/Reports/PeriodFilter.vue';
import { formatCurrency, formatNumber, formatPercent } from '@/lib/reportFormat';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, DollarSign, Receipt, TrendingUp, Percent, Info } from 'lucide-vue-next';

const { t } = useI18n();

defineProps({
    products: Array,
    categories: Array,
    summary: Object,
    costBasis: String,
    truncated: Boolean,
    filters: Object,
});

const marginClass = (value) =>
    value === null || value === undefined ? 'text-text-tertiary' : Number(value) < 0 ? 'text-status-danger' : 'text-status-success';

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
const thRightClass = 'px-4 py-2.5 text-right text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('reports.profitMargin.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('reports.index')" class="text-text-tertiary transition-colors hover:text-text-primary">{{ t('reports.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('reports.profitMargin.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('reports.profitMargin.title')" :description="t('reports.profitMargin.description')">
            <template #actions>
                <ExportMenu route-name="reports.profit-margin" :params="filters" />
                <Button variant="secondary" size="sm" as="Link" :href="route('reports.index')">
                    <ArrowLeft :size="14" />
                    {{ t('reports.backToReports') }}
                </Button>
            </template>
        </PageHeader>

        <PeriodFilter route-name="reports.profit-margin" :filters="filters" />

        <Card class="mt-4">
            <div class="flex items-start gap-3">
                <Info :size="16" class="mt-0.5 shrink-0 text-status-info" />
                <div class="space-y-1 text-xs text-text-secondary">
                    <p class="font-medium text-text-primary">{{ t('reports.profitMargin.costBasisTitle') }}</p>
                    <p>{{ t('reports.profitMargin.costBasis') }}</p>
                    <p v-if="summary.units_estimated_cost > 0" class="text-status-warning">
                        {{ t('reports.profitMargin.estimatedCost', { count: summary.units_estimated_cost }) }}
                    </p>
                    <p v-if="summary.units_without_cost > 0" class="text-status-warning">
                        {{ t('reports.profitMargin.missingCost', { count: summary.units_without_cost }) }}
                    </p>
                </div>
            </div>
        </Card>

        <section class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile :label="t('reports.profitMargin.revenue')" :value="formatCurrency(summary.revenue)" :hint="t('reports.profitMargin.revenueHint')" icon-tone="success">
                <template #icon><DollarSign :size="18" /></template>
            </StatTile>
            <StatTile :label="t('reports.profitMargin.cogs')" :value="formatCurrency(summary.cogs)" icon-tone="info">
                <template #icon><Receipt :size="18" /></template>
            </StatTile>
            <StatTile :label="t('reports.profitMargin.margin')" :value="formatCurrency(summary.margin)" icon-tone="brand">
                <template #icon><TrendingUp :size="18" /></template>
            </StatTile>
            <StatTile :label="t('reports.profitMargin.marginPct')" :value="formatPercent(summary.margin_pct)" icon-tone="violet">
                <template #icon><Percent :size="18" /></template>
            </StatTile>
        </section>

        <section class="mt-4">
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('reports.profitMargin.byCategory')">
                        <template #actions>
                            <ExportMenu route-name="reports.profit-margin" :params="filters" group="category" />
                        </template>
                    </CardHeader>
                </div>
                <div class="w-full overflow-x-auto p-3">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border-subtle">
                                <th :class="thClass">{{ t('reports.common.category') }}</th>
                                <th :class="thRightClass">{{ t('reports.common.units') }}</th>
                                <th :class="thRightClass">{{ t('reports.profitMargin.revenue') }}</th>
                                <th :class="thRightClass">{{ t('reports.profitMargin.cogs') }}</th>
                                <th :class="thRightClass">{{ t('reports.profitMargin.margin') }}</th>
                                <th :class="thRightClass">{{ t('reports.profitMargin.marginPct') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in categories"
                                :key="row.category_id ?? 'none'"
                                class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                            >
                                <td class="px-4 py-3 font-medium text-text-primary">{{ row.category || t('reports.common.uncategorized') }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatNumber(row.units) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatCurrency(row.revenue) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatCurrency(row.cogs) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums" :class="marginClass(row.margin)">{{ formatCurrency(row.margin) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums" :class="marginClass(row.margin_pct)">{{ formatPercent(row.margin_pct) }}</td>
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
                    <CardHeader :title="t('reports.profitMargin.byProduct')" />
                </div>
                <div class="p-3">
                    <p v-if="truncated" class="px-2 pb-2 text-xs text-status-warning">{{ t('reports.common.truncated', { count: products.length }) }}</p>
                    <div class="w-full overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border-subtle">
                                    <th :class="thClass">{{ t('reports.common.product') }}</th>
                                    <th :class="thClass">{{ t('reports.common.category') }}</th>
                                    <th :class="thRightClass">{{ t('reports.common.units') }}</th>
                                    <th :class="thRightClass">{{ t('reports.profitMargin.revenue') }}</th>
                                    <th :class="thRightClass">{{ t('reports.profitMargin.cogs') }}</th>
                                    <th :class="thRightClass">{{ t('reports.profitMargin.margin') }}</th>
                                    <th :class="thRightClass">{{ t('reports.profitMargin.marginPct') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in products"
                                    :key="row.product_id ?? 'deleted'"
                                    class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                                >
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-text-primary">
                                            {{ row.name }}
                                            <Badge v-if="row.cost_missing" variant="warning" size="sm" class="ml-1">{{ t('reports.common.costMissing') }}</Badge>
                                            <Badge v-if="row.cost_estimated" variant="neutral" size="sm" class="ml-1">{{ t('reports.profitMargin.estimatedBadge') }}</Badge>
                                        </p>
                                        <p class="font-mono text-xs text-text-tertiary">{{ row.sku }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-text-secondary">{{ row.category || t('reports.common.uncategorized') }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatNumber(row.units) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatCurrency(row.revenue) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatCurrency(row.cogs) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums" :class="marginClass(row.margin)">{{ formatCurrency(row.margin) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums" :class="marginClass(row.margin_pct)">{{ formatPercent(row.margin_pct) }}</td>
                                </tr>
                                <tr v-if="products.length === 0">
                                    <td colspan="7" class="px-4 py-8 text-center text-sm text-text-tertiary">{{ t('reports.common.noData') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </Card>
        </section>
    </AppLayout>
</template>
