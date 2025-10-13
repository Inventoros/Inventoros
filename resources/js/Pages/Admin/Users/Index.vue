<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    users: Object,
    roles: Array,
    filters: Object,
});
</script>

<template>
    <Head title="Users" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Users</h2>
                <Link
                    :href="route('users.create')"
                    class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                >
                    Add User
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-dark-card shadow-sm sm:rounded-lg border border-gray-200 dark:border-dark-border overflow-hidden">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <h3 class="text-lg font-semibold mb-4">User Management</h3>
                        <p class="text-gray-500 dark:text-gray-400 mb-6">
                            Manage users in your organization. You can add, edit, and remove users, as well as assign roles and permissions.
                        </p>

                        <!-- Users Table Placeholder -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                                <thead class="bg-gray-50 dark:bg-dark-bg/50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Email</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Role</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-dark-border">
                                    <tr v-if="users && users.data && users.data.length > 0" v-for="user in users.data" :key="user.id">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">{{ user.name }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ user.email }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full"
                                                  :class="{
                                                      'bg-primary-400/10 text-primary-400': user.role === 'admin',
                                                      'bg-blue-400/10 text-blue-400': user.role === 'manager',
                                                      'bg-gray-400/10 text-gray-400': user.role === 'member'
                                                  }">
                                                {{ user.role }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <Link :href="route('users.edit', user.id)" class="text-primary-400 hover:text-primary-300 mr-4">Edit</Link>
                                            <Link :href="route('users.show', user.id)" class="text-blue-400 hover:text-blue-300">View</Link>
                                        </td>
                                    </tr>
                                    <tr v-else>
                                        <td colspan="4" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                            No users found. Click "Add User" to create your first user.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination would go here -->
                        <div v-if="users && users.links && users.links.length > 3" class="mt-6 flex justify-center">
                            <nav class="flex gap-2">
                                <Link
                                    v-for="(link, index) in users.links"
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
