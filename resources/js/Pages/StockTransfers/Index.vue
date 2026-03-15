<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    transfers: Object,
    filters: Object,
});

const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || '');

const applyFilters = () => {
    router.get(route('stock-transfers.index'), {
        search: search.value,
        status: selectedStatus.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    selectedStatus.value = '';
    applyFilters();
};

const getStatusBadgeClass = (status) => {
    const classes = {
        'pending': 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
        'in_transit': 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        'completed': 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
        'cancelled': 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
    };
    return classes[status] || classes.pending;
};

const getStatusLabel = (status) => {
    const labels = {
        'pending': 'Pending',
        'in_transit': 'In Transit',
        'completed': 'Completed',
        'cancelled': 'Cancelled',
    };
    return labels[status] || status;
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
    <Head title="Stock Transfers" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Stock Transfers</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Transfer inventory between locations</p>
                </div>
                <Link
                    :href="route('stock-transfers.create')"
                    class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                >
                    New Transfer
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Filters -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6 mb-6">
                    <div class="flex flex-wrap gap-4 items-end">
                        <div class="flex-1 min-w-[200px]">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Search</label>
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Search by transfer number..."
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                @keyup.enter="applyFilters"
                            />
                        </div>
                        <div class="w-48">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Status</label>
                            <select
                                v-model="selectedStatus"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                @change="applyFilters"
                            >
                                <option value="">All Statuses</option>
                                <option value="pending">Pending</option>
                                <option value="in_transit">In Transit</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <button
                                @click="applyFilters"
                                class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                            >
                                Filter
                            </button>
                            <button
                                @click="clearFilters"
                                class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                            >
                                Clear
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                            <thead class="bg-gray-50 dark:bg-dark-bg">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Transfer #</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">From</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">To</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Items</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-dark-card divide-y divide-gray-200 dark:divide-dark-border">
                                <tr v-for="transfer in transfers.data" :key="transfer.id" class="hover:bg-gray-50 dark:hover:bg-dark-bg/50 transition">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <Link
                                            :href="route('stock-transfers.show', transfer.id)"
                                            class="text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300 font-medium"
                                        >
                                            {{ transfer.transfer_number }}
                                        </Link>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                        {{ transfer.from_location?.name || '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                        {{ transfer.to_location?.name || '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span
                                            :class="getStatusBadgeClass(transfer.status)"
                                            class="px-2.5 py-0.5 rounded-full text-xs font-medium"
                                        >
                                            {{ getStatusLabel(transfer.status) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                        {{ transfer.items?.length || 0 }} item(s)
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                        {{ formatDate(transfer.created_at) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <Link
                                            :href="route('stock-transfers.show', transfer.id)"
                                            class="text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300 text-sm font-medium"
                                        >
                                            View
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-if="!transfers.data?.length">
                                    <td colspan="7" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                                        No stock transfers found. Create your first transfer to move inventory between locations.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="transfers.links && transfers.links.length > 3" class="px-6 py-4 border-t border-gray-200 dark:border-dark-border">
                        <div class="flex justify-between items-center">
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                Showing {{ transfers.from }} to {{ transfers.to }} of {{ transfers.total }} transfers
                            </p>
                            <div class="flex gap-1">
                                <template v-for="link in transfers.links" :key="link.label">
                                    <Link
                                        v-if="link.url"
                                        :href="link.url"
                                        v-html="link.label"
                                        :class="[
                                            'px-3 py-1 text-sm rounded border transition',
                                            link.active
                                                ? 'bg-primary-500 text-white border-primary-500'
                                                : 'bg-white dark:bg-dark-card text-gray-700 dark:text-gray-300 border-gray-200 dark:border-dark-border hover:bg-gray-50 dark:hover:bg-dark-bg'
                                        ]"
                                    />
                                    <span
                                        v-else
                                        v-html="link.label"
                                        class="px-3 py-1 text-sm rounded border border-gray-200 dark:border-dark-border text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-dark-bg"
                                    />
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
