<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    reports: Array,
    dataSources: Object,
});

const search = ref('');

const filteredReports = computed(() => {
    if (!search.value) return props.reports;
    const q = search.value.toLowerCase();
    return props.reports.filter(r =>
        r.name.toLowerCase().includes(q) ||
        r.data_source.toLowerCase().includes(q) ||
        (r.creator?.name || '').toLowerCase().includes(q)
    );
});

const dataSourceLabel = (key) => {
    return props.dataSources?.[key]?.label || key;
};

const deleteReport = (report) => {
    if (confirm(t('reportBuilder.confirmDelete'))) {
        router.delete(route('reports.builder.destroy', report.id));
    }
};

const exportReport = (report) => {
    window.location.href = route('reports.builder.export', report.id);
};
</script>

<template>
    <Head :title="t('reportBuilder.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">{{ t('reportBuilder.title') }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ t('reportBuilder.subtitle') }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <Link
                        :href="route('reports.index')"
                        class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                    >
                        {{ t('reports.backToReports') }}
                    </Link>
                    <Link
                        :href="route('reports.builder.create')"
                        class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-lg transition flex items-center gap-2"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        {{ t('reportBuilder.createReport') }}
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Search -->
                <div class="mb-6">
                    <input
                        v-model="search"
                        type="text"
                        :placeholder="t('reportBuilder.searchPlaceholder')"
                        class="w-full md:w-96 px-4 py-2 bg-white dark:bg-dark-card border border-gray-300 dark:border-dark-border rounded-lg text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition"
                    />
                </div>

                <!-- Reports Table -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm overflow-hidden">
                    <div v-if="filteredReports.length === 0" class="p-12 text-center">
                        <svg class="w-16 h-16 text-gray-300 dark:text-gray-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <h3 class="text-lg font-medium text-gray-500 dark:text-gray-400 mb-2">{{ t('reportBuilder.noReports') }}</h3>
                        <p class="text-sm text-gray-400 dark:text-gray-500 mb-4">{{ t('reportBuilder.noReportsDesc') }}</p>
                        <Link
                            :href="route('reports.builder.create')"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-lg transition"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            {{ t('reportBuilder.createFirst') }}
                        </Link>
                    </div>

                    <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                        <thead class="bg-gray-50 dark:bg-dark-bg">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ t('reportBuilder.columns.name') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ t('reportBuilder.columns.dataSource') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ t('reportBuilder.columns.createdBy') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ t('reportBuilder.columns.shared') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ t('reportBuilder.columns.created') }}</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ t('reportBuilder.columns.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-dark-border">
                            <tr
                                v-for="report in filteredReports"
                                :key="report.id"
                                class="hover:bg-gray-50 dark:hover:bg-dark-bg/50 transition"
                            >
                                <td class="px-6 py-4">
                                    <Link :href="route('reports.builder.show', report.id)" class="text-sm font-medium text-gray-900 dark:text-gray-100 hover:text-primary-400 transition">
                                        {{ report.name }}
                                    </Link>
                                    <p v-if="report.description" class="text-xs text-gray-400 dark:text-gray-500 mt-0.5 truncate max-w-xs">{{ report.description }}</p>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300">
                                        {{ dataSourceLabel(report.data_source) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                    {{ report.creator?.name || '-' }}
                                </td>
                                <td class="px-6 py-4">
                                    <span v-if="report.is_shared" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300">
                                        {{ t('reportBuilder.shared') }}
                                    </span>
                                    <span v-else class="text-xs text-gray-400 dark:text-gray-500">{{ t('reportBuilder.private') }}</span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                    {{ new Date(report.created_at).toLocaleDateString() }}
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link
                                            :href="route('reports.builder.show', report.id)"
                                            class="p-1.5 text-gray-400 hover:text-primary-400 transition"
                                            :title="t('reportBuilder.actions.view')"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        </Link>
                                        <Link
                                            v-if="report.is_owner"
                                            :href="route('reports.builder.edit', report.id)"
                                            class="p-1.5 text-gray-400 hover:text-yellow-400 transition"
                                            :title="t('reportBuilder.actions.edit')"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </Link>
                                        <button
                                            @click="exportReport(report)"
                                            class="p-1.5 text-gray-400 hover:text-green-400 transition"
                                            :title="t('reportBuilder.actions.export')"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                        </button>
                                        <button
                                            v-if="report.is_owner"
                                            @click="deleteReport(report)"
                                            class="p-1.5 text-gray-400 hover:text-red-400 transition"
                                            :title="t('reportBuilder.actions.delete')"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
