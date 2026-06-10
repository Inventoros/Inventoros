<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Pencil, Download, Trash2, Database, Columns3, Rows3, FileSpreadsheet } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    report: Object,
    data: Array,
    columnLabels: Object,
    dataSources: Object,
});

const dataSourceLabel = (key) => {
    return props.dataSources?.[key]?.label || key;
};

const exportReport = () => {
    window.location.href = route('reports.builder.export', props.report.id);
};

const deleteReport = () => {
    if (confirm(t('reportBuilder.confirmDelete'))) {
        router.delete(route('reports.builder.destroy', props.report.id));
    }
};

const formatValue = (value, col) => {
    if (value === null || value === undefined) return '-';
    const sourceConfig = props.dataSources?.[props.report.data_source]?.columns?.[col];
    if (!sourceConfig) return value;
    if (sourceConfig.type === 'currency' && !isNaN(value)) {
        return new Intl.NumberFormat('en-CA', { style: 'currency', currency: 'CAD' }).format(Number(value));
    }
    if (sourceConfig.type === 'boolean') {
        return value ? 'Yes' : 'No';
    }
    if (sourceConfig.type === 'date' && value) {
        return new Date(value).toLocaleDateString();
    }
    return value;
};
</script>

<template>
    <Head :title="report.name" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('reports.builder.index')" class="text-text-tertiary hover:text-text-primary">{{ t('reports.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ report.name }}</span>
            </div>
        </template>

        <PageHeader :title="report.name" :description="report.description || undefined">
            <template #actions>
                <Badge v-if="report.is_shared" variant="success" size="sm" dot>{{ t('reportBuilder.shared') }}</Badge>
                <Button
                    v-if="report.is_owner"
                    variant="default"
                    size="sm"
                    as="Link"
                    :href="route('reports.builder.edit', report.id)"
                >
                    <Pencil :size="14" />
                    {{ t('reportBuilder.actions.edit') }}
                </Button>
                <Button variant="secondary" size="sm" @click="exportReport">
                    <Download :size="14" />
                    {{ t('reportBuilder.exportCSV') }}
                </Button>
                <Button v-if="report.is_owner" variant="danger" size="sm" @click="deleteReport">
                    <Trash2 :size="14" />
                    {{ t('reportBuilder.actions.delete') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('reports.builder.index')">
                    <ArrowLeft :size="14" />
                    {{ t('reportBuilder.backToList') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Summary metrics -->
        <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <StatTile
                :label="t('reportBuilder.columnsLabel')"
                :value="report.columns.length"
                icon-tone="brand"
            >
                <template #icon><Columns3 :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reportBuilder.rowsLabel')"
                :value="data.length"
                icon-tone="violet"
            >
                <template #icon><Rows3 :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reportBuilder.columns.dataSource')"
                :value="dataSourceLabel(report.data_source)"
                icon-tone="info"
            >
                <template #icon><Database :size="18" /></template>
            </StatTile>
        </section>

        <!-- Report configuration -->
        <Card :padded="false" class="mt-4">
            <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('reportBuilder.title') }}</h3></div>
            <div class="p-5">
                <div class="flex flex-wrap items-center gap-3">
                    <Badge variant="info" size="md">{{ dataSourceLabel(report.data_source) }}</Badge>
                    <span class="text-sm text-text-tertiary">{{ report.columns.length }} {{ t('reportBuilder.columnsLabel') }}</span>
                    <span v-if="report.filters && report.filters.length > 0" class="text-sm text-text-tertiary">{{ report.filters.length }} {{ t('reportBuilder.filtersLabel') }}</span>
                    <span v-if="report.sort" class="text-sm text-text-tertiary">{{ t('reportBuilder.sortedBy') }}: {{ columnLabels[report.sort.field] || report.sort.field }} ({{ report.sort.direction }})</span>
                </div>
            </div>
        </Card>

        <!-- Results -->
        <Card :padded="false" class="mt-4">
            <div class="flex items-center justify-between px-5 pt-5">
                <h3 class="text-sm font-semibold text-text-primary">{{ report.name }}</h3>
                <span class="text-xs text-text-tertiary">{{ data.length }} {{ t('reportBuilder.rowsLabel') }}</span>
            </div>
            <div class="p-5">
                <!-- Empty state -->
                <div v-if="data.length === 0" class="flex flex-col items-center gap-2 py-12 text-center">
                    <FileSpreadsheet :size="28" class="text-text-tertiary" />
                    <h4 class="text-sm font-medium text-text-secondary">{{ t('reportBuilder.noData') }}</h4>
                    <p class="text-xs text-text-tertiary">{{ t('reportBuilder.noDataDesc') }}</p>
                </div>

                <!-- Results table -->
                <div v-else class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-border-subtle">
                                <th class="w-12 px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary">#</th>
                                <th
                                    v-for="col in report.columns"
                                    :key="col"
                                    class="whitespace-nowrap px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary"
                                >
                                    {{ columnLabels[col] || col }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, idx) in data"
                                :key="idx"
                                class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                            >
                                <td class="px-4 py-3 text-xs tabular-nums text-text-tertiary">{{ idx + 1 }}</td>
                                <td
                                    v-for="col in report.columns"
                                    :key="col"
                                    class="whitespace-nowrap px-4 py-3 text-sm text-text-primary"
                                >
                                    {{ formatValue(row[col], col) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </Card>
    </AppLayout>
</template>

