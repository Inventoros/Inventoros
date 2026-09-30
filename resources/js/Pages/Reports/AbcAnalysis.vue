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
import { ArrowLeft, Award, Medal, CircleDot } from 'lucide-vue-next';

const { t } = useI18n();

defineProps({
    rows: Array,
    summary: Object,
    total_revenue: Number,
    thresholds: Object,
    truncated: Boolean,
    filters: Object,
});

const classVariant = (cls) => ({ A: 'success', B: 'info', C: 'neutral' }[cls] || 'neutral');
const classIcon = { A: Award, B: Medal, C: CircleDot };
const classTone = { A: 'success', B: 'info', C: 'brand' };

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
const thRightClass = 'px-4 py-2.5 text-right text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('reports.abcAnalysis.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('reports.index')" class="text-text-tertiary transition-colors hover:text-text-primary">{{ t('reports.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('reports.abcAnalysis.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('reports.abcAnalysis.title')" :description="t('reports.abcAnalysis.description')">
            <template #actions>
                <ExportMenu route-name="reports.abc-analysis" :params="filters" />
                <Button variant="secondary" size="sm" as="Link" :href="route('reports.index')">
                    <ArrowLeft :size="14" />
                    {{ t('reports.backToReports') }}
                </Button>
            </template>
        </PageHeader>

        <PeriodFilter route-name="reports.abc-analysis" :filters="filters" />

        <section class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <StatTile
                v-for="cls in ['A', 'B', 'C']"
                :key="cls"
                :label="t(`reports.abcAnalysis.class${cls}`)"
                :value="formatCurrency(summary[cls].revenue)"
                :hint="`${t(`reports.abcAnalysis.class${cls}Hint`)} · ${t('reports.abcAnalysis.productCount', { count: summary[cls].count }, summary[cls].count)} · ${formatPercent(summary[cls].share_pct)}`"
                :icon-tone="classTone[cls]"
            >
                <template #icon><component :is="classIcon[cls]" :size="18" /></template>
            </StatTile>
        </section>

        <section class="mt-4">
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader
                        :title="t('reports.abcAnalysis.ranking')"
                        :subtitle="t('reports.abcAnalysis.totalRevenue', { amount: formatCurrency(total_revenue) })"
                    />
                </div>
                <div class="p-3">
                    <p v-if="truncated" class="px-2 pb-2 text-xs text-status-warning">{{ t('reports.common.truncated', { count: rows.length }) }}</p>
                    <div class="w-full overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border-subtle">
                                    <th :class="thClass">{{ t('reports.abcAnalysis.rank') }}</th>
                                    <th :class="thClass">{{ t('reports.common.product') }}</th>
                                    <th :class="thRightClass">{{ t('reports.common.units') }}</th>
                                    <th :class="thRightClass">{{ t('reports.abcAnalysis.revenue') }}</th>
                                    <th :class="thRightClass">{{ t('reports.abcAnalysis.share') }}</th>
                                    <th :class="thRightClass">{{ t('reports.abcAnalysis.cumulative') }}</th>
                                    <th :class="thClass">{{ t('reports.abcAnalysis.class') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(row, index) in rows"
                                    :key="row.product_id ?? `deleted-${index}`"
                                    class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                                >
                                    <td class="px-4 py-3 tabular-nums text-text-tertiary">{{ index + 1 }}</td>
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-text-primary">{{ row.name }}</p>
                                        <p class="font-mono text-xs text-text-tertiary">{{ row.sku }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatNumber(row.units) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-text-primary">{{ formatCurrency(row.revenue) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatPercent(row.share_pct) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatPercent(row.cumulative_pct) }}</td>
                                    <td class="px-4 py-3"><Badge :variant="classVariant(row.class)" size="sm">{{ row.class }}</Badge></td>
                                </tr>
                                <tr v-if="rows.length === 0">
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
