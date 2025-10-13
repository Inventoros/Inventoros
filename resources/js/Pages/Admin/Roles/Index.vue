<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    roles: Object,
    filters: Object,
});
</script>

<template>
    <Head title="Roles" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Roles & Permissions</h2>
                <Link
                    :href="route('roles.create')"
                    class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                >
                    Create Role
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-dark-card shadow-sm sm:rounded-lg border border-gray-200 dark:border-dark-border overflow-hidden">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <h3 class="text-lg font-semibold mb-4">Role Management</h3>
                        <p class="text-gray-500 dark:text-gray-400 mb-6">
                            Manage roles and permissions for your organization. Create custom roles with specific permission sets to control access across the application.
                        </p>

                        <!-- Roles Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div
                                v-if="roles && roles.data && roles.data.length > 0"
                                v-for="role in roles.data"
                                :key="role.id"
                                class="bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-lg p-6 hover:border-primary-400/30 transition"
                            >
                                <div class="flex items-start justify-between mb-4">
                                    <div>
                                        <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ role.name }}</h4>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1" v-if="role.description">{{ role.description }}</p>
                                    </div>
                                    <span
                                        v-if="role.is_system"
                                        class="px-2 py-1 text-xs font-semibold rounded-full bg-primary-400/10 text-primary-400"
                                    >
                                        System
                                    </span>
                                </div>

                                <div class="mb-4">
                                    <div class="flex items-center text-sm text-gray-500 dark:text-gray-400">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                        </svg>
                                        <span>{{ role.users_count || 0 }} users</span>
                                    </div>
                                    <div class="flex items-center text-sm text-gray-500 dark:text-gray-400 mt-2">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                        </svg>
                                        <span>{{ role.permissions ? role.permissions.length : 0 }} permissions</span>
                                    </div>
                                </div>

                                <div class="flex gap-2">
                                    <Link
                                        :href="route('roles.show', role.id)"
                                        class="flex-1 px-3 py-2 text-center text-sm font-medium text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-dark-bg/50 hover:bg-gray-200 dark:hover:bg-dark-bg rounded-md transition"
                                    >
                                        View
                                    </Link>
                                    <Link
                                        v-if="role.slug !== 'system-administrator'"
                                        :href="route('roles.edit', role.id)"
                                        class="flex-1 px-3 py-2 text-center text-sm font-medium text-primary-400 bg-primary-400/10 hover:bg-primary-400/20 rounded-md transition"
                                    >
                                        Edit
                                    </Link>
                                    <div
                                        v-else
                                        class="flex-1 px-3 py-2 text-center text-sm font-medium text-gray-600 bg-gray-50 dark:bg-dark-bg/30 rounded-md cursor-not-allowed"
                                        title="Administrator role cannot be edited"
                                    >
                                        Locked
                                    </div>
                                </div>
                            </div>

                            <div v-if="!roles || !roles.data || roles.data.length === 0" class="col-span-full text-center py-12">
                                <svg class="w-16 h-16 mx-auto text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                </svg>
                                <p class="text-gray-500 dark:text-gray-400 mb-4">No roles found. Click "Create Role" to define your first custom role.</p>
                            </div>
                        </div>

                        <!-- Pagination -->
                        <div v-if="roles && roles.links && roles.links.length > 3" class="mt-6 flex justify-center">
                            <nav class="flex gap-2">
                                <Link
                                    v-for="(link, index) in roles.links"
                                    :key="index"
                                    :href="link.url"
                                    :class="[
                                        'px-3 py-2 rounded-md text-sm font-medium transition',
                                        link.active
                                            ? 'bg-primary-500 text-white'
                                            : link.url
                                            ? 'bg-gray-100 dark:bg-dark-bg text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-dark-bg/80 hover:text-gray-900 dark:hover:text-gray-200'
                                            : 'bg-gray-100 dark:bg-dark-bg/50 text-gray-400 dark:text-gray-600 cursor-not-allowed opacity-50'
                                    ]"
                                    :disabled="!link.url"
                                    v-html="link.label"
                                />
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
