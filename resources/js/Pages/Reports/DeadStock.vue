<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import ExportMenu from '@/Components/Reports/ExportMenu.vue';
import { formatCurrency, formatNumber, formatDate } from '@/lib/reportFormat';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Boxes, Layers, DollarSign, PackageCheck } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    rows: Array,
    summary: Object,
    truncated: Boolean,
    filters: Object,
    options: Object,
});

const days = ref(props.filters.days);
const basis = ref(props.filters.basis);

const apply = () => {
    router.get(route('reports.dead-stock'), { days: days.value, basis: basis.value }, { preserveState: true, preserveScroll: true });
};

const inputClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
const thRightClass = 'px-4 py-2.5 text-right text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('reports.deadStock.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('reports.index')" class="text-text-tertiary transition-colors hover:text-text-primary">{{ t('reports.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('reports.deadStock.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('reports.deadStock.title')" :description="t('reports.deadStock.description')">
            <template #actions>
                <ExportMenu route-name="reports.dead-stock" :params="filters" />
                <Button variant="secondary" size="sm" as="Link" :href="route('reports.index')">
                    <ArrowLeft :size="14" />
                    {{ t('reports.backToReports') }}
                </Button>
            </template>
        </PageHeader>

        <Card class="mt-6">
            <form class="grid grid-cols-1 gap-4 md:grid-cols-3" @submit.prevent="apply">
                <div>
                    <label for="dead-days" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('reports.deadStock.window') }}</label>
                    <select id="dead-days" v-model.number="days" :class="inputClass">
                        <option v-for="d in options.days" :key="d" :value="d">{{ t('reports.deadStock.days', { n: d }) }}</option>
                    </select>
                </div>
                <div>
                    <label for="dead-basis" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('reports.deadStock.basis') }}</label>
                    <select id="dead-basis" v-model="basis" :class="inputClass">
                        <option v-for="b in options.bases" :key="b" :value="b">{{ t(`reports.deadStock.bases.${b}`) }}</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <Button type="submit" variant="default" size="sm">{{ t('reports.salesAnalysis.apply') }}</Button>
                </div>
            </form>
            <p class="mt-3 text-xs text-text-tertiary">{{ t('reports.deadStock.note') }}</p>
        </Card>

        <section class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <StatTile :label="t('reports.deadStock.products')" :value="formatNumber(summary.product_count)" icon-tone="warning">
                <template #icon><Boxes :size="18" /></template>
            </StatTile>
            <StatTile :label="t('reports.deadStock.units')" :value="formatNumber(summary.total_units)" icon-tone="info">
                <template #icon><Layers :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.deadStock.value')"
                :value="formatCurrency(summary.total_value)"
                :hint="t('reports.deadStock.valueHint')"
                icon-tone="brand"
            >
                <template #icon><DollarSign :size="18" /></template>
            </StatTile>
        </section>

        <section class="mt-4">
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('reports.deadStock.title')" />
                </div>
                <div v-if="rows.length === 0" class="flex flex-col items-center gap-2 px-5 py-12 text-center">
                    <PackageCheck :size="28" class="text-text-tertiary" />
                    <h4 class="text-sm font-medium text-text-secondary">{{ t('reports.deadStock.empty') }}</h4>
                    <p class="text-xs text-text-tertiary">{{ t('reports.deadStock.emptyHint') }}</p>
                </div>
                <div v-else class="p-3">
                    <p v-if="truncated" class="px-2 pb-2 text-xs text-status-warning">{{ t('reports.common.truncated', { count: rows.length }) }}</p>
                    <div class="w-full overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border-subtle">
                                    <th :class="thClass">{{ t('reports.common.product') }}</th>
                                    <th :class="thClass">{{ t('reports.common.category') }}</th>
                                    <th :class="thClass">{{ t('reports.common.location') }}</th>
                                    <th :class="thRightClass">{{ t('reports.deadStock.onHand') }}</th>
                                    <th :class="thRightClass">{{ t('reports.deadStock.unitCost') }}</th>
                                    <th :class="thRightClass">{{ t('reports.deadStock.value') }}</th>
                                    <th :class="thClass">{{ t('reports.deadStock.lastSale') }}</th>
                                    <th :class="thClass">{{ t('reports.deadStock.lastOutbound') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in rows"
                                    :key="row.id"
                                    class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                                >
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-text-primary">{{ row.name }}</p>
                                        <p class="font-mono text-xs text-text-tertiary">{{ row.sku }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-text-secondary">{{ row.category || t('reports.common.uncategorized') }}</td>
                                    <td class="px-4 py-3 text-text-secondary">{{ row.location || '-' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-text-primary">{{ formatNumber(row.stock) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-text-secondary">
                                        <Badge v-if="row.cost_missing" variant="warning" size="sm">{{ t('reports.common.costMissing') }}</Badge>
                                        <span v-else>{{ formatCurrency(row.unit_cost) }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-text-primary">{{ formatCurrency(row.tied_up_value) }}</td>
                                    <td class="px-4 py-3 text-text-secondary">{{ row.last_sale_at ? formatDate(row.last_sale_at) : t('reports.deadStock.never') }}</td>
                                    <td class="px-4 py-3 text-text-secondary">{{ row.last_outbound_at ? formatDate(row.last_outbound_at) : t('reports.deadStock.never') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </Card>
        </section>
    </AppLayout>
</template>
