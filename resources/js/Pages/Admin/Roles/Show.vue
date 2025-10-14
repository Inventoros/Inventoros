<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    role: Object,
    rolePermissions: Array,
});

const showDeleteModal = ref(false);
const deleting = ref(false);

const groupedPermissions = computed(() => {
    const groups = {};
    props.rolePermissions.forEach(permission => {
        const category = permission.category || 'Other';
        if (!groups[category]) {
            groups[category] = [];
        }
        groups[category].push(permission);
    });
    return groups;
});

const deleteRole = () => {
    deleting.value = true;
    router.delete(route('roles.destroy', props.role.id), {
        onFinish: () => {
            deleting.value = false;
            showDeleteModal.value = false;
        },
    });
};
</script>

<template>
    <Head :title="`Role: ${role.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <div class="flex items-center gap-3">
                        <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">{{ role.name }}</h2>
                        <span v-if="role.is_system" class="px-2 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300 text-xs font-semibold rounded">
                            System Role
                        </span>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Role Details & Permissions</p>
                </div>
                <div class="flex gap-3">
                    <Link
                        v-if="!role.is_system && role.slug !== 'system-administrator'"
                        :href="route('roles.edit', role.id)"
                        class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                    >
                        Edit Role
                    </Link>
                    <Link
                        :href="route('roles.index')"
                        class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                    >
                        Back to Roles
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Main Info -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Role Information Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-6">
                                Role Information
                            </h3>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Role Name
                                    </label>
                                    <p class="text-gray-900 dark:text-gray-100">{{ role.name }}</p>
                                </div>

                                <div v-if="role.description">
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Description
                                    </label>
                                    <p class="text-gray-900 dark:text-gray-100">{{ role.description }}</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Role Type
                                    </label>
                                    <span v-if="role.is_system" class="px-3 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300 text-sm font-medium rounded-full">
                                        System Role
                                    </span>
                                    <span v-else class="px-3 py-1 bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300 text-sm font-medium rounded-full">
                                        Custom Role
                                    </span>
                                </div>

                                <div v-if="role.users && role.users.length > 0">
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">
                                        Users with this Role
                                    </label>
                                    <div class="space-y-2">
                                        <div
                                            v-for="user in role.users"
                                            :key="user.id"
                                            class="flex items-center gap-3 p-3 bg-gray-50 dark:bg-dark-bg rounded-lg"
                                        >
                                            <div class="flex-shrink-0 w-10 h-10 bg-primary-400 rounded-full flex items-center justify-center text-white font-semibold">
                                                {{ user.name.charAt(0).toUpperCase() }}
                                            </div>
                                            <div>
                                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ user.name }}</p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ user.email }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-else>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">
                                        Users with this Role
                                    </label>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">No users assigned to this role yet.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Permissions Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-6">
                                Permissions ({{ rolePermissions.length }})
                            </h3>

                            <div v-if="rolePermissions.length > 0" class="space-y-6">
                                <div v-for="(permissions, category) in groupedPermissions" :key="category">
                                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 uppercase tracking-wide">
                                        {{ category }}
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <div
                                            v-for="permission in permissions"
                                            :key="permission.value"
                                            class="flex items-start gap-3 p-3 bg-gray-50 dark:bg-dark-bg rounded-lg border border-gray-200 dark:border-dark-border"
                                        >
                                            <svg class="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <div>
                                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ permission.label }}</p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ permission.description }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="text-center py-8">
                                <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                                <p class="text-gray-500 dark:text-gray-400">No permissions assigned to this role.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6">
                        <!-- Details Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-6">
                                Details
                            </h3>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Created
                                    </label>
                                    <p class="text-sm text-gray-900 dark:text-gray-100">
                                        {{ new Date(role.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) }}
                                    </p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Last Updated
                                    </label>
                                    <p class="text-sm text-gray-900 dark:text-gray-100">
                                        {{ new Date(role.updated_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) }}
                                    </p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Role Slug
                                    </label>
                                    <p class="text-sm font-mono text-gray-900 dark:text-gray-100 bg-gray-50 dark:bg-dark-bg px-2 py-1 rounded">
                                        {{ role.slug }}
                                    </p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                                        Users Count
                                    </label>
                                    <p class="text-sm text-gray-900 dark:text-gray-100">
                                        {{ role.users ? role.users.length : 0 }} {{ role.users && role.users.length === 1 ? 'user' : 'users' }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Actions Card -->
                        <div v-if="!role.is_system && role.slug !== 'system-administrator'" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                Actions
                            </h3>

                            <div class="space-y-3">
                                <Link
                                    :href="route('roles.edit', role.id)"
                                    class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                    Edit Role
                                </Link>

                                <button
                                    @click="showDeleteModal = true"
                                    :disabled="role.users && role.users.length > 0"
                                    class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                                    :title="role.users && role.users.length > 0 ? 'Cannot delete role with assigned users' : 'Delete role'"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                    Delete Role
                                </button>

                                <p v-if="role.users && role.users.length > 0" class="text-xs text-gray-500 dark:text-gray-400 text-center">
                                    Reassign users before deleting
                                </p>
                            </div>
                        </div>

                        <!-- System Role Notice -->
                        <div v-else class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                            <div class="flex gap-3">
                                <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <div>
                                    <p class="text-sm font-medium text-blue-900 dark:text-blue-100">System Role</p>
                                    <p class="text-xs text-blue-700 dark:text-blue-300 mt-1">
                                        This is a system role and cannot be modified or deleted.
                                    </p>
                                </div>
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
                                Delete Role
                            </h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-4">
                                Are you sure you want to delete the <strong>{{ role.name }}</strong> role? This action cannot be undone.
                            </p>
                            <div class="flex gap-3 justify-end">
                                <button
                                    @click="showDeleteModal = false"
                                    class="px-4 py-2 bg-gray-200 dark:bg-dark-bg text-gray-700 dark:text-gray-300 font-medium rounded-lg hover:bg-gray-300 dark:hover:bg-dark-bg/70 transition"
                                >
                                    Cancel
                                </button>
                                <button
                                    @click="deleteRole"
                                    :disabled="deleting"
                                    class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition disabled:opacity-50"
                                >
                                    {{ deleting ? 'Deleting...' : 'Delete Role' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
