<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Search, Download, User, ChevronRight, FileText, Plus, Pencil, Trash2, Eye } from '@lucide/vue';

import { useI18n } from 'vue-i18n';
const props = defineProps({
    activities: Object,
    filters: Object,
    users: Array,
    actions: Array,
    subjectTypes: Array,
});


const { t } = useI18n();
const search = ref(props.filters.search || '');
const user_id = ref(props.filters.user_id || '');
const action = ref(props.filters.action || '');
const subject_type = ref(props.filters.subject_type || '');
const date_from = ref(props.filters.date_from || '');
const date_to = ref(props.filters.date_to || '');

const applyFilters = () => {
    router.get(route('activity-log.index'), {
        search: search.value,
        user_id: user_id.value,
        action: action.value,
        subject_type: subject_type.value,
        date_from: date_from.value,
        date_to: date_to.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    user_id.value = '';
    action.value = '';
    subject_type.value = '';
    date_from.value = '';
    date_to.value = '';
    router.get(route('activity-log.index'));
};

const exportUrl = (format) => {
    const params = new URLSearchParams();
    params.append('format', format);
    if (user_id.value) params.append('user_id', user_id.value);
    if (action.value) params.append('action', action.value);
    if (date_from.value) params.append('date_from', date_from.value);
    if (date_to.value) params.append('date_to', date_to.value);
    return route('activity-log.export') + '?' + params.toString();
};

const actionVariant = (actionType) =>
    ({ created: 'success', updated: 'info', deleted: 'danger', viewed: 'neutral' }[actionType] || 'neutral');

const actionIcon = (actionType) =>
    ({ created: Plus, updated: Pencil, deleted: Trash2, viewed: Eye }[actionType] || FileText);

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleString();
};

const formatRelativeTime = (dateString) => {
    const date = new Date(dateString);
    const now = new Date();
    const diff = now - date;

    const seconds = Math.floor(diff / 1000);
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);

    if (days > 0) return `${days} day${days > 1 ? 's' : ''} ago`;
    if (hours > 0) return `${hours} hour${hours > 1 ? 's' : ''} ago`;
    if (minutes > 0) return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
    return 'Just now';
};

const formatFieldName = (field) => {
    return field.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
};

const formatValue = (value) => {
    if (value === null || value === undefined) return '-';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
};

const getChangedFields = (properties) => {
    if (!properties) return [];
    const oldVals = properties.old || {};
    const newVals = properties.new || {};
    const allKeys = new Set([...Object.keys(oldVals), ...Object.keys(newVals)]);
    const changes = [];

    for (const key of allKeys) {
        const oldVal = oldVals[key];
        const newVal = newVals[key];
        if (JSON.stringify(oldVal) !== JSON.stringify(newVal)) {
            changes.push({ field: key, old: oldVal, new: newVal });
        }
    }
    return changes;
};

const selectClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
</script>

<template>
    <Head :title="t('admin.activityLog.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('admin.activityLog.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('admin.activityLog.title')" description="Audit trail of every change across your workspace.">
            <template #actions>
                <Button variant="secondary" size="sm" as="a" :href="exportUrl('csv')">
                    <Download :size="14" />
                    Export CSV
                </Button>
                <Button variant="secondary" size="sm" as="a" :href="exportUrl('xlsx')">
                    <Download :size="14" />
                    Export XLSX
                </Button>
            </template>
        </PageHeader>

        <!-- Filters -->
        <Card class="mt-6">
            <form @submit.prevent="applyFilters" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <!-- Search -->
                    <div class="lg:col-span-3">
                        <label for="search" class="mb-1 block text-xs font-medium text-text-secondary">Search Description</label>
                        <div class="relative">
                            <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                            <input
                                id="search"
                                v-model="search"
                                type="text"
                                placeholder="Search activity descriptions..."
                                class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            />
                        </div>
                    </div>

                    <!-- User Filter -->
                    <div>
                        <label for="user_id" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('common.user') }}</label>
                        <select id="user_id" v-model="user_id" :class="selectClass">
                            <option value="">All Users</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                    </div>

                    <!-- Action Filter -->
                    <div>
                        <label for="action" class="mb-1 block text-xs font-medium text-text-secondary">Action</label>
                        <select id="action" v-model="action" :class="selectClass">
                            <option value="">All Actions</option>
                            <option v-for="act in actions" :key="act" :value="act">{{ act.charAt(0).toUpperCase() + act.slice(1) }}</option>
                        </select>
                    </div>

                    <!-- Subject Type Filter -->
                    <div>
                        <label for="subject_type" class="mb-1 block text-xs font-medium text-text-secondary">Subject Type</label>
                        <select id="subject_type" v-model="subject_type" :class="selectClass">
                            <option value="">{{ t('common.allTypes') }}</option>
                            <option v-for="type in subjectTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                        </select>
                    </div>

                    <!-- Date From -->
                    <div>
                        <label for="date_from" class="mb-1 block text-xs font-medium text-text-secondary">From Date</label>
                        <input id="date_from" v-model="date_from" type="date" :class="selectClass" />
                    </div>

                    <!-- Date To -->
                    <div>
                        <label for="date_to" class="mb-1 block text-xs font-medium text-text-secondary">To Date</label>
                        <input id="date_to" v-model="date_to" type="date" :class="selectClass" />
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Button type="submit" variant="default" size="sm">
                        <Search :size="14" />
                        {{ t('common.applyFilters') }}
                    </Button>
                    <Button type="button" variant="secondary" size="sm" @click="clearFilters">{{ t('common.clearFilters') }}</Button>
                </div>
            </form>
        </Card>

        <!-- Activity List -->
        <div class="mt-4 w-full overflow-hidden rounded-lg border border-border-subtle bg-surface-raised">
            <div v-if="activities.data.length > 0" class="divide-y divide-border-subtle">
                <div
                    v-for="activity in activities.data"
                    :key="activity.id"
                    class="p-4 transition-colors hover:bg-surface-overlay"
                >
                    <div class="flex items-start gap-4">
                        <!-- Icon -->
                        <span :class="[
                            'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border',
                            {
                                created: 'border-status-success/20 bg-status-success-soft text-status-success',
                                updated: 'border-status-info/20 bg-status-info-soft text-status-info',
                                deleted: 'border-status-danger/20 bg-status-danger-soft text-status-danger',
                            }[activity.action] || 'border-border-subtle bg-surface-overlay text-text-secondary',
                        ]">
                            <component :is="actionIcon(activity.action)" :size="16" />
                        </span>

                        <!-- Content -->
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-text-primary">{{ activity.description }}</p>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-text-tertiary">
                                        <span class="flex items-center gap-1">
                                            <User :size="13" />
                                            {{ activity.user?.name || 'Unknown User' }}
                                        </span>
                                        <span>&middot;</span>
                                        <Badge :variant="actionVariant(activity.action)" size="sm">{{ activity.action }}</Badge>
                                        <span>&middot;</span>
                                        <span>{{ activity.subject_type.split('\\').pop() }}</span>
                                        <span v-if="activity.ip_address">&middot;</span>
                                        <span v-if="activity.ip_address" class="font-mono">{{ activity.ip_address }}</span>
                                    </div>

                                    <!-- Properties (old/new values) -->
                                    <div v-if="activity.properties && (activity.properties.old || activity.properties.new) && getChangedFields(activity.properties).length > 0" class="mt-3 space-y-2">
                                        <details class="group">
                                            <summary class="inline-flex cursor-pointer items-center gap-1 text-xs font-medium text-brand hover:underline">
                                                <ChevronRight :size="14" class="transition-transform group-open:rotate-90" />
                                                View {{ getChangedFields(activity.properties).length }} Change{{ getChangedFields(activity.properties).length > 1 ? 's' : '' }}
                                            </summary>
                                            <div class="mt-2 overflow-x-auto rounded-lg border border-border-subtle bg-surface-canvas p-3">
                                                <table class="w-full text-xs">
                                                    <thead>
                                                        <tr class="border-b border-border-subtle">
                                                            <th class="px-3 py-2 text-left font-medium text-text-secondary">Field</th>
                                                            <th class="px-3 py-2 text-left font-medium text-status-danger">{{ t('stockAdjustments.show.stockBefore') }}</th>
                                                            <th class="px-3 py-2 text-left font-medium text-status-success">{{ t('stockAdjustments.show.stockAfter') }}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-border-subtle">
                                                        <tr v-for="change in getChangedFields(activity.properties)" :key="change.field">
                                                            <td class="px-3 py-2 font-medium text-text-secondary">{{ formatFieldName(change.field) }}</td>
                                                            <td class="max-w-xs truncate px-3 py-2 font-mono text-status-danger" :title="formatValue(change.old)">
                                                                <span class="line-through opacity-75">{{ formatValue(change.old) }}</span>
                                                            </td>
                                                            <td class="max-w-xs truncate px-3 py-2 font-mono text-status-success" :title="formatValue(change.new)">
                                                                {{ formatValue(change.new) }}
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </details>
                                    </div>
                                </div>

                                <!-- Timestamp -->
                                <div class="shrink-0 text-right">
                                    <p class="text-xs text-text-secondary">{{ formatRelativeTime(activity.created_at) }}</p>
                                    <p class="mt-0.5 text-xs text-text-tertiary">{{ formatDate(activity.created_at) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="flex flex-col items-center gap-3 px-4 py-12 text-center">
                <FileText :size="22" class="text-text-tertiary" />
                <p class="text-sm font-medium text-text-primary">No activity found</p>
                <p class="text-sm text-text-tertiary">Try adjusting your filters to see more results</p>
            </div>
        </div>

        <!-- Pagination -->
        <div v-if="activities.data.length > 0" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-xs text-text-tertiary">
                {{ t('common.showing') }} <span class="font-medium text-text-secondary">{{ activities.from }}</span>
                {{ t('common.to') }} <span class="font-medium text-text-secondary">{{ activities.to }}</span>
                {{ t('common.of') }} <span class="font-medium text-text-secondary">{{ activities.total }}</span> {{ t('common.results') }}
            </p>
            <nav class="inline-flex items-center gap-1">
                <template v-for="link in activities.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-colors',
                            link.active
                                ? 'border-brand bg-brand text-brand-foreground'
                                : 'border-border-subtle bg-surface-canvas text-text-secondary hover:bg-surface-overlay',
                        ]"
                        v-html="link.label"
                    />
                    <span v-else class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-2.5 text-xs text-text-tertiary opacity-50" v-html="link.label" />
                </template>
            </nav>
        </div>
    </AppLayout>
</template>

