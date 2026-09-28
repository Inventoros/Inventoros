<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import Button from '@/Components/ui/Button.vue';
import ExportMenu from '@/Components/Reports/ExportMenu.vue';
import PeriodFilter from '@/Components/Reports/PeriodFilter.vue';
import { formatCurrency, formatNumber, formatDelta } from '@/lib/reportFormat';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArrowLeft } from 'lucide-vue-next';

const { t } = useI18n();

defineProps({
    byWarehouse: Array,
    byLocation: Array,
    previousPeriod: Object,
    filters: Object,
});

const deltaClass = (value) => {
    if (value === null || value === undefined || Number(value) === 0) return 'text-text-tertiary';
    return Number(value) > 0 ? 'text-status-success' : 'text-status-danger';
};

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
const thRightClass = 'px-4 py-2.5 text-right text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('reports.salesByLocation.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('reports.index')" class="text-text-tertiary transition-colors hover:text-text-primary">{{ t('reports.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('reports.salesByLocation.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('reports.salesByLocation.title')" :description="t('reports.salesByLocation.description')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('reports.index')">
                    <ArrowLeft :size="14" />
                    {{ t('reports.backToReports') }}
                </Button>
            </template>
        </PageHeader>

        <PeriodFilter route-name="reports.sales-by-location" :filters="filters" />

        <p class="mt-3 text-xs text-text-tertiary">
            {{ t('reports.salesByLocation.previousPeriod', { from: previousPeriod.date_from, to: previousPeriod.date_to }) }}
            {{ t('reports.salesByLocation.salesNote') }}
        </p>

        <section class="mt-4">
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('reports.salesByLocation.byWarehouse')">
                        <template #actions>
                            <ExportMenu route-name="reports.sales-by-location" :params="filters" />
                        </template>
                    </CardHeader>
                </div>
                <div class="w-full overflow-x-auto p-3">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border-subtle">
                                <th :class="thClass">{{ t('reports.common.warehouse') }}</th>
                                <th :class="thRightClass">{{ t('reports.common.orders') }}</th>
                                <th :class="thRightClass">{{ t('reports.salesByLocation.sales') }}</th>
                                <th :class="thRightClass">{{ t('reports.salesByLocation.previous') }}</th>
                                <th :class="thRightClass">{{ t('reports.salesByLocation.change') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in byWarehouse"
                                :key="row.warehouse_id ?? 'none'"
                                class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                            >
                                <td class="px-4 py-3 font-medium text-text-primary">{{ row.name || t('reports.salesByLocation.noWarehouse') }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatNumber(row.orders) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-text-primary">{{ formatCurrency(row.revenue) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatCurrency(row.previous_revenue) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums" :class="deltaClass(row.delta_pct)">{{ formatDelta(row.delta_pct) }}</td>
                            </tr>
                            <tr v-if="byWarehouse.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-text-tertiary">{{ t('reports.common.noData') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </section>

        <section class="mt-4">
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('reports.salesByLocation.byLocation')">
                        <template #actions>
                            <ExportMenu route-name="reports.sales-by-location" :params="filters" group="location" />
                        </template>
                    </CardHeader>
                </div>
                <div class="w-full overflow-x-auto p-3">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border-subtle">
                                <th :class="thClass">{{ t('reports.common.location') }}</th>
                                <th :class="thClass">{{ t('reports.common.warehouse') }}</th>
                                <th :class="thRightClass">{{ t('reports.common.units') }}</th>
                                <th :class="thRightClass">{{ t('reports.salesByLocation.sales') }}</th>
                                <th :class="thRightClass">{{ t('reports.salesByLocation.previous') }}</th>
                                <th :class="thRightClass">{{ t('reports.salesByLocation.change') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in byLocation"
                                :key="row.location_id ?? 'none'"
                                class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                            >
                                <td class="px-4 py-3 font-medium text-text-primary">{{ row.name || t('reports.salesByLocation.noLocation') }}</td>
                                <td class="px-4 py-3 text-text-secondary">{{ row.warehouse || '-' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatNumber(row.units) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-text-primary">{{ formatCurrency(row.revenue) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatCurrency(row.previous_revenue) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums" :class="deltaClass(row.delta_pct)">{{ formatDelta(row.delta_pct) }}</td>
                            </tr>
                            <tr v-if="byLocation.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-text-tertiary">{{ t('reports.common.noData') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </section>
    </AppLayout>
</template>
