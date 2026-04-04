<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

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
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">{{ report.name }}</h2>
                    <p v-if="report.description" class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ report.description }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <Link :href="route('reports.builder.index')" class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition">{{ t('reportBuilder.backToList') }}</Link>
                    <Link v-if="report.is_owner" :href="route('reports.builder.edit', report.id)" class="px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white font-medium rounded-lg transition">{{ t('reportBuilder.actions.edit') }}</Link>
                    <button @click="exportReport" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        {{ t('reportBuilder.exportCSV') }}
                    </button>
                    <button v-if="report.is_owner" @click="deleteReport" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition">{{ t('reportBuilder.actions.delete') }}</button>
                </div>
            </div>
        </template>
        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="mb-6 flex flex-wrap items-center gap-4">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300">{{ dataSourceLabel(report.data_source) }}</span>
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ report.columns.length }} {{ t('reportBuilder.columnsLabel') }}</span>
                    <span v-if="report.filters && report.filters.length > 0" class="text-sm text-gray-500 dark:text-gray-400">{{ report.filters.length }} {{ t('reportBuilder.filtersLabel') }}</span>
                    <span v-if="report.sort" class="text-sm text-gray-500 dark:text-gray-400">{{ t('reportBuilder.sortedBy') }}: {{ columnLabels[report.sort.field] || report.sort.field }} ({{ report.sort.direction }})</span>
                    <span v-if="report.is_shared" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300">{{ t('reportBuilder.shared') }}</span>
                    <span class="text-sm text-gray-400 dark:text-gray-500 ml-auto">{{ data.length }} {{ t('reportBuilder.rowsLabel') }}</span>
                </div>
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm overflow-hidden">
                    <div v-if="data.length === 0" class="p-12 text-center">
                        <svg class="w-16 h-16 text-gray-300 dark:text-gray-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        <h3 class="text-lg font-medium text-gray-500 dark:text-gray-400 mb-2">{{ t('reportBuilder.noData') }}</h3>
                        <p class="text-sm text-gray-400 dark:text-gray-500">{{ t('reportBuilder.noDataDesc') }}</p>
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                            <thead class="bg-gray-50 dark:bg-dark-bg">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-12">#</th>
                                    <th v-for="col in report.columns" :key="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap">{{ columnLabels[col] || col }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-dark-border">
                                <tr v-for="(row, idx) in data" :key="idx" class="hover:bg-gray-50 dark:hover:bg-dark-bg/50 transition">
                                    <td class="px-4 py-3 text-xs text-gray-400 dark:text-gray-500">{{ idx + 1 }}</td>
                                    <td v-for="col in report.columns" :key="col" class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ formatValue(row[col], col) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>