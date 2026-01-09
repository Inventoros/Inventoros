<script setup>
import { computed } from 'vue';

const props = defineProps({
    activities: {
        type: Array,
        default: () => []
    }
});

const getActionColor = (action) => {
    const colors = {
        'created': 'bg-green-500',
        'updated': 'bg-blue-500',
        'deleted': 'bg-red-500',
        'viewed': 'bg-gray-500',
    };
    return colors[action] || 'bg-gray-500';
};

const getActionBgColor = (action) => {
    const colors = {
        'created': 'bg-green-900/30 text-green-300 border-green-800',
        'updated': 'bg-blue-900/30 text-blue-300 border-blue-800',
        'deleted': 'bg-red-900/30 text-red-300 border-red-800',
        'viewed': 'bg-gray-900/30 text-gray-300 border-gray-800',
    };
    return colors[action] || 'bg-gray-900/30 text-gray-300 border-gray-800';
};

const getActionIcon = (action) => {
    const icons = {
        'created': 'M12 4v16m8-8H4',
        'updated': 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
        'deleted': 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
        'viewed': 'M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z',
    };
    return icons[action] || 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
};

const formatRelativeTime = (dateString) => {
    const date = new Date(dateString);
    const now = new Date();
    const diff = now - date;

    const seconds = Math.floor(diff / 1000);
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);

    if (days > 0) return `${days}d ago`;
    if (hours > 0) return `${hours}h ago`;
    if (minutes > 0) return `${minutes}m ago`;
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
    <div class="flow-root">
        <div v-if="activities.length === 0" class="text-center py-6 text-gray-500 dark:text-gray-400">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p>No activity recorded yet</p>
        </div>

        <ul v-else role="list" class="-mb-8">
            <li v-for="(activity, idx) in activities" :key="activity.id">
                <div class="relative pb-8">
                    <!-- Connecting line -->
                    <span
                        v-if="idx !== activities.length - 1"
                        class="absolute left-4 top-4 -ml-px h-full w-0.5 bg-gray-200 dark:bg-dark-border"
                        aria-hidden="true"
                    />

                    <div class="relative flex space-x-3">
                        <!-- Icon node -->
                        <div>
                            <span :class="['h-8 w-8 rounded-full flex items-center justify-center ring-4 ring-white dark:ring-dark-card', getActionColor(activity.action)]">
                                <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getActionIcon(activity.action)" />
                                </svg>
                            </span>
                        </div>

                        <!-- Content -->
                        <div class="flex-1 min-w-0 pt-1">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-sm">
                                    <span class="font-medium text-gray-900 dark:text-gray-100">
                                        {{ activity.user?.name || 'System' }}
                                    </span>
                                    <span class="text-gray-500 dark:text-gray-400">
                                        {{ activity.action }} this product
                                    </span>
                                </div>
                                <span class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                    {{ formatRelativeTime(activity.created_at) }}
                                </span>
                            </div>

                            <!-- Changes (for updates) -->
                            <div v-if="activity.action === 'updated' && activity.properties && getChangedFields(activity.properties).length > 0" class="mt-2">
                                <div class="text-xs space-y-1">
                                    <div
                                        v-for="change in getChangedFields(activity.properties).slice(0, 3)"
                                        :key="change.field"
                                        class="flex items-center gap-2 text-gray-500 dark:text-gray-400"
                                    >
                                        <span class="font-medium">{{ formatFieldName(change.field) }}:</span>
                                        <span class="text-red-400 line-through">{{ formatValue(change.old) }}</span>
                                        <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                        </svg>
                                        <span class="text-green-400">{{ formatValue(change.new) }}</span>
                                    </div>
                                    <div v-if="getChangedFields(activity.properties).length > 3" class="text-gray-400 italic">
                                        +{{ getChangedFields(activity.properties).length - 3 }} more changes
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
