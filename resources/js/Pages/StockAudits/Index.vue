<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    audits: Object,
    filters: Object,
    statuses: Object,
    auditTypes: Object,
});

const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || '');
const selectedType = ref(props.filters?.audit_type || '');

const applyFilters = () => {
    router.get(route('stock-audits.index'), {
        search: search.value,
        status: selectedStatus.value,
        audit_type: selectedType.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    selectedStatus.value = '';
    selectedType.value = '';
    applyFilters();
};

const getStatusBadgeClass = (status) => {
    const classes = {
        'draft': 'bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-300',
        'in_progress': 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        'completed': 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
        'cancelled': 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
    };
    return classes[status] || classes.draft;
};

const getTypeBadgeClass = (type) => {
    const classes = {
        'full': 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300',
        'cycle': 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        'spot': 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
    };
    return classes[type] || classes.full;
};

const formatDate = (dateStr) => {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};
</script>

<template>
    <Head title="Stock Audits" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Stock Audits</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage stock audits and cycle counts</p>
                </div>
                <Link
                    :href="route('stock-audits.create')"
                    class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                >
                    New Audit
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Filters -->
                <div class="mb-6 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Filters</h3>

                    <form @submit.prevent="applyFilters" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Search</label>
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Search by audit number or name..."
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Status</label>
                            <select
                                v-model="selectedStatus"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            >
                                <option value="">All Statuses</option>
                                <option v-for="(label, value) in statuses" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Audit Type</label>
                            <select
                                v-model="selectedType"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            >
                                <option value="">All Types</option>
                                <option v-for="(label, value) in auditTypes" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </div>

                        <div class="md:col-span-2 lg:col-span-3 flex gap-3">
                            <button
                                type="submit"
                                class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                            >
                                Apply Filters
                            </button>
                            <button
                                type="button"
                                @click="clearFilters"
                                class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                            >
                                Clear Filters
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Audits Table -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                            <thead class="bg-gray-50 dark:bg-dark-bg/50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Audit #</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Location</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Items</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Created By</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-dark-border">
                                <tr v-if="audits.data && audits.data.length > 0" v-for="audit in audits.data" :key="audit.id" class="hover:bg-gray-50 dark:hover:bg-dark-bg/50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ audit.audit_number }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                        <div class="font-medium">{{ audit.name }}</div>
                                        <div v-if="audit.description" class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-xs">{{ audit.description }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full" :class="getTypeBadgeClass(audit.audit_type)">
                                            {{ auditTypes[audit.audit_type] || audit.audit_type }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full" :class="getStatusBadgeClass(audit.status)">
                                            {{ statuses[audit.status] || audit.status }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                        {{ audit.warehouse_location?.name || 'All Locations' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-600 dark:text-gray-300">
                                        {{ audit.items_count || 0 }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                        {{ audit.creator?.name || '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                        {{ formatDate(audit.created_at) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <Link :href="route('stock-audits.show', audit.id)" class="text-primary-400 hover:text-primary-300">
                                            View
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-else>
                                    <td colspan="9" class="px-6 py-12 text-center">
                                        <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                        </svg>
                                        <p class="text-gray-500 dark:text-gray-400 mb-4">No stock audits found</p>
                                        <Link
                                            :href="route('stock-audits.create')"
                                            class="inline-flex items-center px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                                        >
                                            Create First Audit
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="audits.links && audits.links.length > 3" class="px-6 py-4 border-t border-gray-200 dark:border-dark-border">
                        <nav class="flex justify-center gap-2">
                            <Link
                                v-for="(link, index) in audits.links"
                                :key="index"
                                :href="link.url"
                                :class="[
                                    'px-3 py-2 rounded-md text-sm font-medium transition',
                                    link.active
                                        ? 'bg-primary-500 text-white'
                                        : link.url
                                        ? 'bg-gray-100 dark:bg-dark-bg text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-dark-bg/80'
                                        : 'bg-gray-100 dark:bg-dark-bg/50 text-gray-400 cursor-not-allowed opacity-50'
                                ]"
                                :disabled="!link.url"
                                v-html="link.label"
                            />
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
