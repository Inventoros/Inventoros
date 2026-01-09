<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    activities: Object,
    filters: Object,
    users: Array,
    actions: Array,
    subjectTypes: Array,
});

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

const getActionColor = (actionType) => {
    const colors = {
        'created': 'bg-green-900/30 text-green-300 border-green-800',
        'updated': 'bg-blue-900/30 text-blue-300 border-blue-800',
        'deleted': 'bg-red-900/30 text-red-300 border-red-800',
        'viewed': 'bg-gray-900/30 text-gray-300 border-gray-800',
    };
    return colors[actionType] || 'bg-gray-900/30 text-gray-300 border-gray-800';
};

const getActionIcon = (actionType) => {
    const icons = {
        'created': 'M12 4v16m8-8H4',
        'updated': 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
        'deleted': 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
        'viewed': 'M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z',
    };
    return icons[actionType] || 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
};

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
</script>

<template>
    <Head title="Activity Log" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                Activity Log
            </h2>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Filters -->
                <div class="mb-6 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <!-- Search -->
                        <div class="lg:col-span-3">
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">
                                Search Description
                            </label>
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Search activity descriptions..."
                                class="w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            />
                        </div>

                        <!-- User Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">
                                User
                            </label>
                            <select
                                v-model="user_id"
                                class="w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            >
                                <option value="">All Users</option>
                                <option v-for="user in users" :key="user.id" :value="user.id">
                                    {{ user.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Action Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">
                                Action
                            </label>
                            <select
                                v-model="action"
                                class="w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            >
                                <option value="">All Actions</option>
                                <option v-for="act in actions" :key="act" :value="act">
                                    {{ act.charAt(0).toUpperCase() + act.slice(1) }}
                                </option>
                            </select>
                        </div>

                        <!-- Subject Type Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">
                                Subject Type
                            </label>
                            <select
                                v-model="subject_type"
                                class="w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            >
                                <option value="">All Types</option>
                                <option v-for="type in subjectTypes" :key="type.value" :value="type.value">
                                    {{ type.label }}
                                </option>
                            </select>
                        </div>

                        <!-- Date From -->
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">
                                From Date
                            </label>
                            <input
                                v-model="date_from"
                                type="date"
                                class="w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            />
                        </div>

                        <!-- Date To -->
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">
                                To Date
                            </label>
                            <input
                                v-model="date_to"
                                type="date"
                                class="w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            />
                        </div>
                    </div>

                    <!-- Filter Actions -->
                    <div class="mt-4 flex gap-3">
                        <button
                            @click="applyFilters"
                            class="px-4 py-2 bg-primary-400 text-white rounded-md hover:bg-primary-500 transition font-medium text-sm"
                        >
                            Apply Filters
                        </button>
                        <button
                            @click="clearFilters"
                            class="px-4 py-2 bg-gray-200 dark:bg-dark-bg text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-300 dark:hover:bg-dark-bg/80 transition font-medium text-sm border border-gray-200 dark:border-dark-border"
                        >
                            Clear Filters
                        </button>
                    </div>
                </div>

                <!-- Activity List -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm overflow-hidden">
                    <div v-if="activities.data.length > 0" class="divide-y divide-gray-200 dark:divide-dark-border">
                        <div
                            v-for="activity in activities.data"
                            :key="activity.id"
                            class="p-6 hover:bg-gray-50 dark:hover:bg-dark-bg/50 transition"
                        >
                            <div class="flex items-start gap-4">
                                <!-- Icon -->
                                <div :class="['flex-shrink-0 w-10 h-10 rounded-full border flex items-center justify-center', getActionColor(activity.action)]">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getActionIcon(activity.action)" />
                                    </svg>
                                </div>

                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="flex-1">
                                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {{ activity.description }}
                                            </p>
                                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                                <span class="flex items-center gap-1">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                                    </svg>
                                                    {{ activity.user?.name || 'Unknown User' }}
                                                </span>
                                                <span>•</span>
                                                <span :class="['px-2 py-0.5 rounded-full border text-xs font-medium', getActionColor(activity.action)]">
                                                    {{ activity.action }}
                                                </span>
                                                <span>•</span>
                                                <span>{{ activity.subject_type.split('\\').pop() }}</span>
                                                <span v-if="activity.ip_address">•</span>
                                                <span v-if="activity.ip_address" class="font-mono">{{ activity.ip_address }}</span>
                                            </div>

                                            <!-- Properties (old/new values) -->
                                            <div v-if="activity.properties && (activity.properties.old || activity.properties.new) && getChangedFields(activity.properties).length > 0" class="mt-3 space-y-2">
                                                <details class="group">
                                                    <summary class="cursor-pointer text-xs text-primary-400 hover:text-primary-300 font-medium inline-flex items-center gap-1">
                                                        <svg class="w-4 h-4 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                                        </svg>
                                                        View {{ getChangedFields(activity.properties).length }} Change{{ getChangedFields(activity.properties).length > 1 ? 's' : '' }}
                                                    </summary>
                                                    <div class="mt-2 p-3 bg-gray-50 dark:bg-dark-bg rounded-lg border border-gray-200 dark:border-dark-border overflow-x-auto">
                                                        <table class="w-full text-xs">
                                                            <thead>
                                                                <tr class="border-b border-gray-200 dark:border-dark-border">
                                                                    <th class="text-left py-2 px-3 font-semibold text-gray-600 dark:text-gray-300">Field</th>
                                                                    <th class="text-left py-2 px-3 font-semibold text-red-400">Before</th>
                                                                    <th class="text-left py-2 px-3 font-semibold text-green-400">After</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-gray-100 dark:divide-dark-border">
                                                                <tr v-for="change in getChangedFields(activity.properties)" :key="change.field">
                                                                    <td class="py-2 px-3 font-medium text-gray-700 dark:text-gray-300">
                                                                        {{ formatFieldName(change.field) }}
                                                                    </td>
                                                                    <td class="py-2 px-3 text-red-400 font-mono max-w-xs truncate" :title="formatValue(change.old)">
                                                                        <span class="line-through opacity-75">{{ formatValue(change.old) }}</span>
                                                                    </td>
                                                                    <td class="py-2 px-3 text-green-400 font-mono max-w-xs truncate" :title="formatValue(change.new)">
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
                                        <div class="text-right flex-shrink-0">
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ formatRelativeTime(activity.created_at) }}
                                            </p>
                                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                                                {{ formatDate(activity.created_at) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div v-else class="p-12 text-center">
                        <svg class="w-16 h-16 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">
                            No activity found
                        </p>
                        <p class="text-gray-400 dark:text-gray-500 text-sm mt-1">
                            Try adjusting your filters to see more results
                        </p>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="activities.data.length > 0" class="mt-6">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            Showing {{ activities.from }} to {{ activities.to }} of {{ activities.total }} results
                        </div>

                        <div class="flex gap-2">
                            <Link
                                v-for="link in activities.links"
                                :key="link.label"
                                :href="link.url"
                                :class="[
                                    'px-3 py-2 text-sm rounded-md border transition',
                                    link.active
                                        ? 'bg-primary-400 text-white border-primary-400'
                                        : 'bg-white dark:bg-dark-card text-gray-600 dark:text-gray-300 border-gray-200 dark:border-dark-border hover:bg-gray-100 dark:hover:bg-dark-bg',
                                    !link.url && 'opacity-50 cursor-not-allowed'
                                ]"
                                :disabled="!link.url"
                                v-html="link.label"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
