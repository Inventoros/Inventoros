<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatNumber, formatDay } from '@/lib/reportFormat';
import { ArrowLeft, ArrowUpDown, Download } from 'lucide-vue-next';

const props = defineProps({
    batches: Object,
    summary: Object,
    filters: Object,
    asOf: String,
    exportLimit: Number,
});
const { t } = useI18n();
const form = reactive({ window: props.filters.window, search: props.filters.search });
watch(() => props.filters, (filters) => Object.assign(form, { window: filters.window, search: filters.search }));
const windows = ['7', '30', '90', 'expired', 'all'];
const columns = [
    { key: 'product_name', label: 'batchExpiryReport.product' },
    { key: 'batch_number', label: 'batchExpiryReport.batch' },
    { key: 'quantity', label: 'batchExpiryReport.units' },
    { key: 'expiry_date', label: 'batchExpiryReport.expiry' },
];
const inputClass = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
const exportUrl = computed(() => route('reports.batch-expiry', { ...props.filters, export: 'csv' }));
const apply = () => router.get(route('reports.batch-expiry'), { ...props.filters, ...form }, { preserveState: true, preserveScroll: true });
const sort = (key) => router.get(route('reports.batch-expiry'), {
    ...props.filters,
    sort_by: key,
    sort_dir: props.filters.sort_by === key && props.filters.sort_dir === 'asc' ? 'desc' : 'asc',
}, { preserveState: true, preserveScroll: true });
const ariaSort = (key) => props.filters.sort_by !== key ? 'none' : (props.filters.sort_dir === 'asc' ? 'ascending' : 'descending');
</script>

<template>
    <Head :title="t('batchExpiryReport.title')" />
    <AppLayout>
        <template #header>
            <Link :href="route('reports.index')" class="text-xs text-text-secondary hover:text-text-primary">{{ t('reports.title') }}</Link>
        </template>
        <PageHeader :title="t('batchExpiryReport.title')" :description="t('batchExpiryReport.description')">
            <template #actions>
                <Button as="Link" :href="route('reports.index')" variant="outline"><ArrowLeft :size="14" />{{ t('common.back') }}</Button>
                <Button as="a" :href="exportUrl"><Download :size="14" />{{ t('batchExpiryReport.export') }}</Button>
            </template>
        </PageHeader>

        <p class="mt-4 text-sm text-text-secondary">{{ t('batchExpiryReport.scope') }}</p>
        <p class="mt-1 text-xs text-text-tertiary">{{ t('batchExpiryReport.asOf', { date: formatDay(asOf) }) }}</p>

        <Card class="mt-5">
            <form class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end" @submit.prevent="apply">
                <div>
                    <label for="batch-expiry-search" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('batchExpiryReport.search') }}</label>
                    <input id="batch-expiry-search" v-model="form.search" type="search" maxlength="200" :class="inputClass" />
                </div>
                <div>
                    <label for="batch-expiry-window" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('batchExpiryReport.window') }}</label>
                    <select id="batch-expiry-window" v-model="form.window" :class="inputClass">
                        <option v-for="window in windows" :key="window" :value="window">{{ t(`batchExpiryReport.windows.${window}`) }}</option>
                    </select>
                </div>
                <Button type="submit">{{ t('common.applyFilters') }}</Button>
            </form>
        </Card>

        <section class="mt-4 grid gap-4 sm:grid-cols-3">
            <StatTile :label="t('batchExpiryReport.units')" :value="formatNumber(summary.units)" :hint="t('batchExpiryReport.lotCount', { count: formatNumber(summary.batches) })" />
            <StatTile :label="t('batchExpiryReport.expiredUnits')" :value="formatNumber(summary.expired_units)" />
            <StatTile :label="t('batchExpiryReport.notExpiredUnits')" :value="formatNumber(summary.not_expired_units)" />
        </section>

        <p v-if="summary.batches > exportLimit" class="mt-4 text-sm text-status-warning" role="status">{{ t('batchExpiryReport.exportLimit', { count: formatNumber(exportLimit) }) }}</p>

        <Card class="mt-4" :padded="false">
            <p v-if="batches.data.length === 0" class="px-5 py-12 text-center text-sm text-text-secondary">{{ t('batchExpiryReport.empty') }}</p>
            <div v-else class="overflow-x-auto ds-focus-ring" role="region" :aria-label="t('batchExpiryReport.title')" tabindex="0">
                <table class="w-full min-w-[48rem] text-sm">
                    <caption class="sr-only">{{ t('batchExpiryReport.title') }}</caption>
                    <thead>
                        <tr class="border-b border-border-subtle">
                            <th v-for="column in columns" :key="column.key" scope="col" :aria-sort="ariaSort(column.key)" class="px-4 py-3 text-left text-xs font-medium text-text-secondary">
                                <button type="button" class="inline-flex items-center gap-1 rounded ds-focus-ring" @click="sort(column.key)">
                                    {{ t(column.label) }}<ArrowUpDown :size="12" aria-hidden="true" />
                                </button>
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-text-secondary">{{ t('batchExpiryReport.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="batch in batches.data" :key="batch.id" class="border-b border-border-subtle last:border-0 hover:bg-surface-overlay">
                            <td class="min-w-64 max-w-sm px-4 py-3">
                                <span v-if="batch.archived" class="font-medium text-text-primary">{{ batch.product_name }}</span>
                                <Link v-else :href="route('products.show', batch.product_id)" class="font-medium text-text-primary hover:text-brand">{{ batch.product_name }}</Link>
                                <p class="font-mono text-xs text-text-tertiary">{{ batch.sku }}</p>
                                <Badge v-if="batch.archived" variant="neutral" size="sm">{{ t('batchExpiryReport.archived') }}</Badge>
                            </td>
                            <td class="px-4 py-3 font-mono text-text-secondary">{{ batch.batch_number }}</td>
                            <td class="px-4 py-3 tabular-nums text-text-primary">{{ formatNumber(batch.quantity) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-text-secondary">{{ formatDay(batch.expiry_date) }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="batch.status === 'expired' ? 'danger' : batch.status === 'today' ? 'warning' : 'info'" size="sm">{{ t(`batchExpiryReport.statuses.${batch.status}`) }}</Badge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav v-if="batches.total > 0" :aria-label="t('common.pagination')" class="flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle px-4 py-3">
                <p class="text-xs text-text-tertiary">{{ t('reportBuilder.pagination', { from: batches.from, to: batches.to, total: batches.total }) }}</p>
                <div class="flex gap-2">
                    <Button v-if="batches.prev_page_url" as="Link" :href="batches.prev_page_url" variant="outline" size="sm" preserve-scroll>{{ t('common.previous') }}</Button>
                    <Button v-else disabled variant="outline" size="sm">{{ t('common.previous') }}</Button>
                    <Button v-if="batches.next_page_url" as="Link" :href="batches.next_page_url" variant="outline" size="sm" preserve-scroll>{{ t('common.next') }}</Button>
                    <Button v-else disabled variant="outline" size="sm">{{ t('common.next') }}</Button>
                </div>
            </nav>
        </Card>
    </AppLayout>
</template>
