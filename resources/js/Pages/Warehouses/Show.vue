<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { usePermissions } from '@/composables/usePermissions';

const { t } = useI18n();
const { hasPermission } = usePermissions();

const props = defineProps({
    warehouse: Object,
    locations: Array,
    assignedUsers: Array,
    stats: Object,
});

const deleteWarehouse = () => {
    if (confirm(`Are you sure you want to delete "${props.warehouse.name}"? This action cannot be undone.`)) {
        router.delete(route('warehouses.destroy', props.warehouse.id));
    }
};
</script>

<template>
    <Head :title="warehouse.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    {{ warehouse.name }}
                </h2>
                <div class="flex items-center gap-2">
                    <Link
                        v-if="hasPermission('edit_warehouses')"
                        :href="route('warehouses.edit', warehouse.id)"
                        class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500"
                    >
                        Edit
                    </Link>
                    <Link
                        :href="route('warehouses.index')"
                        class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                    >
                        Back
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Status & Badges -->
                <div class="flex items-center gap-3 mb-6">
                    <span
                        :class="[
                            'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium',
                            warehouse.is_active
                                ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
                                : 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400'
                        ]"
                    >
                        {{ warehouse.is_active ? 'Active' : 'Inactive' }}
                    </span>
                    <span
                        v-if="warehouse.is_default"
                        class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400"
                    >
                        Default Warehouse
                    </span>
                    <span class="text-gray-500 dark:text-gray-400">
                        Code: {{ warehouse.code }}
                    </span>
                </div>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg p-6">
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Locations</dt>
                        <dd class="mt-1 text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ stats?.locations_count || 0 }}</dd>
                    </div>
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg p-6">
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Products Stored</dt>
                        <dd class="mt-1 text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ stats?.products_count || 0 }}</dd>
                    </div>
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg p-6">
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Assigned Users</dt>
                        <dd class="mt-1 text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ assignedUsers?.length || 0 }}</dd>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Main Info -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Warehouse Details -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Warehouse Details</h3>
                            </div>
                            <div class="p-6">
                                <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Manager</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ warehouse.manager_name || '-' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Email</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                            <a v-if="warehouse.email" :href="`mailto:${warehouse.email}`" class="text-primary-400 hover:underline">
                                                {{ warehouse.email }}
                                            </a>
                                            <span v-else>-</span>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Phone</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ warehouse.phone || '-' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Timezone</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ warehouse.timezone || '-' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Currency</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ warehouse.currency || '-' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Priority</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ warehouse.priority ?? 0 }}</dd>
                                    </div>
                                    <div v-if="warehouse.description" class="sm:col-span-2">
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Description</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 whitespace-pre-wrap">{{ warehouse.description }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        <!-- Address -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Address</h3>
                            </div>
                            <div class="p-6">
                                <p class="text-sm text-gray-900 dark:text-gray-100">
                                    <template v-if="warehouse.address_line_1 || warehouse.city || warehouse.province || warehouse.postal_code || warehouse.country">
                                        <span v-if="warehouse.address_line_1">{{ warehouse.address_line_1 }}<br></span>
                                        <span v-if="warehouse.address_line_2">{{ warehouse.address_line_2 }}<br></span>
                                        <span v-if="warehouse.city">{{ warehouse.city }}, </span>
                                        <span v-if="warehouse.province">{{ warehouse.province }} </span>
                                        <span v-if="warehouse.postal_code">{{ warehouse.postal_code }}<br></span>
                                        <span v-if="warehouse.country">{{ warehouse.country }}</span>
                                    </template>
                                    <template v-else>
                                        <span class="text-gray-500 dark:text-gray-400">No address provided</span>
                                    </template>
                                </p>
                            </div>
                        </div>

                        <!-- Locations -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Locations ({{ locations?.length || 0 }})</h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table v-if="locations && locations.length > 0" class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                                    <thead class="bg-gray-50 dark:bg-dark-bg">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Code</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Products</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-dark-card divide-y divide-gray-200 dark:divide-dark-border">
                                        <tr v-for="location in locations" :key="location.id" class="hover:bg-gray-50 dark:hover:bg-dark-bg/50">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {{ location.name }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                <span class="px-2 py-0.5 text-xs font-mono rounded bg-gray-50 dark:bg-dark-bg text-gray-600 dark:text-gray-300">
                                                    {{ location.code }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ location.products_count || 0 }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div v-else class="p-6 text-center text-gray-500 dark:text-gray-400">
                                    No locations in this warehouse
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6">
                        <!-- Assigned Users -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Assigned Users ({{ assignedUsers?.length || 0 }})</h3>
                            </div>
                            <div class="p-6">
                                <div v-if="assignedUsers && assignedUsers.length > 0" class="space-y-3">
                                    <div
                                        v-for="user in assignedUsers"
                                        :key="user.id"
                                        class="flex items-center gap-3 py-2 border-b border-gray-100 dark:border-dark-border last:border-0"
                                    >
                                        <div class="w-8 h-8 bg-primary-400/20 rounded-full flex items-center justify-center flex-shrink-0">
                                            <span class="text-xs font-medium text-primary-400">{{ user.name.charAt(0).toUpperCase() }}</span>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ user.name }}</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ user.email }}</div>
                                        </div>
                                    </div>
                                </div>
                                <p v-else class="text-sm text-gray-500 dark:text-gray-400">No users assigned to this warehouse.</p>
                            </div>
                        </div>

                        <!-- Actions Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Actions</h3>
                            </div>
                            <div class="p-6 space-y-3">
                                <Link
                                    v-if="hasPermission('edit_warehouses')"
                                    :href="route('warehouses.edit', warehouse.id)"
                                    class="w-full inline-flex justify-center items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500"
                                >
                                    Edit Warehouse
                                </Link>
                                <button
                                    v-if="hasPermission('delete_warehouses') && !warehouse.is_default"
                                    @click="deleteWarehouse"
                                    class="w-full inline-flex justify-center items-center px-4 py-2 bg-red-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-600"
                                >
                                    Delete Warehouse
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
