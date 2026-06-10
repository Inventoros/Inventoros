<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Search, Eye, Pencil, Trash2, Download, FileSpreadsheet } from '@lucide/vue';

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

const thClass =
    'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
</script>

<template>
    <Head :title="t('reportBuilder.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="text-text-tertiary">Reports</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('reportBuilder.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('reportBuilder.title')" :description="t('reportBuilder.subtitle')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('reports.index')">
                    {{ t('reports.backToReports') }}
                </Button>
                <Button variant="default" size="sm" as="Link" :href="route('reports.builder.create')">
                    <Plus :size="14" />
                    {{ t('reportBuilder.createReport') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Search -->
        <div class="mt-6">
            <div class="relative w-full md:w-96">
                <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                <input
                    v-model="search"
                    type="text"
                    :placeholder="t('reportBuilder.searchPlaceholder')"
                    class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                />
            </div>
        </div>

        <!-- Reports table -->
        <div class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">{{ t('reportBuilder.columns.name') }}</th>
                        <th :class="thClass">{{ t('reportBuilder.columns.dataSource') }}</th>
                        <th :class="thClass">{{ t('reportBuilder.columns.createdBy') }}</th>
                        <th :class="thClass">{{ t('reportBuilder.columns.shared') }}</th>
                        <th :class="thClass">{{ t('reportBuilder.columns.created') }}</th>
                        <th :class="[thClass, 'text-right']">{{ t('reportBuilder.columns.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="filteredReports.length === 0">
                        <td colspan="6" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <FileSpreadsheet :size="22" class="text-text-tertiary" />
                                <p class="text-sm font-medium text-text-primary">{{ t('reportBuilder.noReports') }}</p>
                                <p class="text-sm text-text-tertiary">{{ t('reportBuilder.noReportsDesc') }}</p>
                                <Button variant="default" size="sm" as="Link" :href="route('reports.builder.create')">
                                    <Plus :size="14" />
                                    {{ t('reportBuilder.createFirst') }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr
                        v-for="report in filteredReports"
                        :key="report.id"
                        class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                    >
                        <td class="px-4 py-3 text-text-primary">
                            <Link :href="route('reports.builder.show', report.id)" class="font-medium text-text-primary transition-colors hover:text-brand">
                                {{ report.name }}
                            </Link>
                            <p v-if="report.description" class="mt-0.5 max-w-xs truncate text-xs text-text-tertiary">{{ report.description }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <Badge variant="info" size="sm">{{ dataSourceLabel(report.data_source) }}</Badge>
                        </td>
                        <td class="px-4 py-3 text-text-secondary">
                            {{ report.creator?.name || '-' }}
                        </td>
                        <td class="px-4 py-3">
                            <Badge v-if="report.is_shared" variant="success" size="sm">{{ t('reportBuilder.shared') }}</Badge>
                            <span v-else class="text-xs text-text-tertiary">{{ t('reportBuilder.private') }}</span>
                        </td>
                        <td class="px-4 py-3 text-text-secondary">
                            {{ new Date(report.created_at).toLocaleDateString() }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Link :href="route('reports.builder.show', report.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" :title="t('reportBuilder.actions.view')"><Eye :size="16" /></Link>
                                <Link v-if="report.is_owner" :href="route('reports.builder.edit', report.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-success" :title="t('reportBuilder.actions.edit')"><Pencil :size="16" /></Link>
                                <button @click="exportReport(report)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" :title="t('reportBuilder.actions.export')"><Download :size="16" /></button>
                                <button v-if="report.is_owner" @click="deleteReport(report)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-danger" :title="t('reportBuilder.actions.delete')"><Trash2 :size="16" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

