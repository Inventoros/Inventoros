<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    user: Object,
});

const showDeleteModal = ref(false);
const deleting = ref(false);

const deleteUser = () => {
    deleting.value = true;
    router.delete(route('users.destroy', props.user.id), {
        onFinish: () => {
            deleting.value = false;
            showDeleteModal.value = false;
        },
    });
};

const getRoleBadgeClass = (role) => {
    return {
        'bg-primary-400/10 text-primary-400': role === 'admin',
        'bg-blue-400/10 text-blue-400': role === 'manager',
        'bg-gray-400/10 text-gray-400': role === 'member'
    };
};
</script>

<template>
    <Head :title="`User: ${user.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">{{ user.name }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">User Details</p>
                </div>
                <div class="flex gap-3">
                    <Link
                        :href="route('users.edit', user.id)"
                        class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                    >
                        Edit User
                    </Link>
                    <Link
                        :href="route('users.index')"
                        class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                    >
                        Back to Users
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Main Info -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- User Information Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-6">
                                User Information
                            </h3>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Full Name
                                    </label>
                                    <p class="text-gray-900 dark:text-gray-100">{{ user.name }}</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Email Address
                                    </label>
                                    <p class="text-gray-900 dark:text-gray-100">{{ user.email }}</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Primary Role
                                    </label>
                                    <span class="px-3 py-1 inline-flex text-sm font-semibold rounded-full" :class="getRoleBadgeClass(user.role)">
                                        {{ user.role }}
                                    </span>
                                </div>

                                <div v-if="user.roles && user.roles.length > 0">
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">
                                        Additional Roles
                                    </label>
                                    <div class="flex flex-wrap gap-2">
                                        <span
                                            v-for="role in user.roles"
                                            :key="role.id"
                                            class="px-3 py-1 bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300 text-sm font-medium rounded-full"
                                        >
                                            {{ role.name }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Organization Card -->
                        <div v-if="user.organization" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-6">
                                Organization
                            </h3>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Organization Name
                                    </label>
                                    <p class="text-gray-900 dark:text-gray-100">{{ user.organization.name }}</p>
                                </div>

                                <div v-if="user.organization.address">
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Address
                                    </label>
                                    <p class="text-gray-900 dark:text-gray-100 whitespace-pre-line">{{ user.organization.address }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6">
                        <!-- Account Details Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-6">
                                Account Details
                            </h3>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Member Since
                                    </label>
                                    <p class="text-gray-900 dark:text-gray-100">
                                        {{ new Date(user.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) }}
                                    </p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Last Updated
                                    </label>
                                    <p class="text-gray-900 dark:text-gray-100">
                                        {{ new Date(user.updated_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) }}
                                    </p>
                                </div>

                                <div v-if="user.email_verified_at">
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Email Verified
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <span class="text-sm text-green-600 dark:text-green-400">Verified</span>
                                    </div>
                                </div>
                                <div v-else>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Email Verified
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <svg class="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                        </svg>
                                        <span class="text-sm text-yellow-600 dark:text-yellow-400">Not Verified</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Actions Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                Actions
                            </h3>

                            <div class="space-y-3">
                                <Link
                                    :href="route('users.edit', user.id)"
                                    class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                    Edit User
                                </Link>

                                <button
                                    @click="showDeleteModal = true"
                                    class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                    Delete User
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div v-if="showDeleteModal" class="fixed inset-0 z-50 overflow-y-auto" @click="showDeleteModal = false">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>

                <div class="relative bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-xl max-w-lg w-full p-6" @click.stop>
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0">
                            <svg class="w-12 h-12 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">
                                Delete User
                            </h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-4">
                                Are you sure you want to delete <strong>{{ user.name }}</strong>? This action cannot be undone.
                            </p>
                            <div class="flex gap-3 justify-end">
                                <button
                                    @click="showDeleteModal = false"
                                    class="px-4 py-2 bg-gray-200 dark:bg-dark-bg text-gray-700 dark:text-gray-300 font-medium rounded-lg hover:bg-gray-300 dark:hover:bg-dark-bg/70 transition"
                                >
                                    Cancel
                                </button>
                                <button
                                    @click="deleteUser"
                                    :disabled="deleting"
                                    class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition disabled:opacity-50"
                                >
                                    {{ deleting ? 'Deleting...' : 'Delete User' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
