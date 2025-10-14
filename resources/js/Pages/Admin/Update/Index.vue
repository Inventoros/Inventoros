<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
    currentVersion: String,
    latestRelease: Object,
    updateAvailable: Boolean,
    backups: Array,
    githubRepo: String,
});

const isUpdating = ref(false);
const isCheckingUpdate = ref(false);
const isCreatingBackup = ref(false);
const updateProgress = ref([]);
const updateResult = ref(null);
const showBackups = ref(false);
const selectedBackup = ref(null);
const isRestoring = ref(false);

const formatBytes = (bytes) => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const formatDate = (timestamp) => {
    return new Date(timestamp * 1000).toLocaleString();
};

const checkForUpdates = async () => {
    isCheckingUpdate.value = true;
    try {
        const response = await axios.get(route('admin.update.check'));
        if (response.data.success) {
            router.reload();
        }
    } catch (error) {
        console.error('Failed to check for updates:', error);
        alert('Failed to check for updates. Please try again.');
    } finally {
        isCheckingUpdate.value = false;
    }
};

const performUpdate = async () => {
    if (!confirm('Are you sure you want to update the application? A backup will be created automatically.')) {
        return;
    }

    isUpdating.value = true;
    updateProgress.value = [];
    updateResult.value = null;

    try {
        const response = await axios.post(route('admin.update.perform'), {}, {
            timeout: 600000, // 10 minutes
        });

        updateResult.value = response.data;

        if (response.data.success) {
            setTimeout(() => {
                window.location.reload();
            }, 3000);
        }
    } catch (error) {
        console.error('Update failed:', error);
        updateResult.value = {
            success: false,
            message: error.response?.data?.message || 'Update failed. Please check the logs.',
        };
    } finally {
        isUpdating.value = false;
    }
};

const createBackup = async () => {
    if (!confirm('Create a backup of the current installation?')) {
        return;
    }

    isCreatingBackup.value = true;

    try {
        const response = await axios.post(route('admin.update.backup'));

        if (response.data.success) {
            alert('Backup created successfully!');
            router.reload();
        }
    } catch (error) {
        console.error('Backup failed:', error);
        alert('Failed to create backup. Please check the logs.');
    } finally {
        isCreatingBackup.value = false;
    }
};

const restoreBackup = async (backupFile) => {
    if (!confirm(`Are you sure you want to restore from ${backupFile}? This will overwrite the current installation.`)) {
        return;
    }

    isRestoring.value = true;

    try {
        const response = await axios.post(route('admin.update.restore'), {
            backup_file: backupFile,
        }, {
            timeout: 600000, // 10 minutes
        });

        if (response.data.success) {
            alert('Restore completed successfully!');
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            alert(response.data.message || 'Restore failed.');
        }
    } catch (error) {
        console.error('Restore failed:', error);
        alert('Failed to restore backup. Please check the logs.');
    } finally {
        isRestoring.value = false;
    }
};

const deleteBackup = async (backupFile) => {
    if (!confirm(`Are you sure you want to delete ${backupFile}?`)) {
        return;
    }

    try {
        const response = await axios.delete(route('admin.update.backup.delete'), {
            data: { backup_file: backupFile },
        });

        if (response.data.success) {
            router.reload();
        }
    } catch (error) {
        console.error('Failed to delete backup:', error);
        alert('Failed to delete backup. Please try again.');
    }
};
</script>

<template>
    <Head title="System Update" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">System Update</h2>
                <button
                    @click="checkForUpdates"
                    :disabled="isCheckingUpdate"
                    class="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-lg transition disabled:opacity-50"
                >
                    {{ isCheckingUpdate ? 'Checking...' : 'Check for Updates' }}
                </button>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

                <!-- Current Version Card -->
                <div class="bg-white dark:bg-dark-card shadow-sm sm:rounded-lg border border-gray-200 dark:border-dark-border">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold mb-4 text-gray-900 dark:text-gray-100">Current Version</h3>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-3xl font-bold text-primary-500">{{ currentVersion }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Installed version</p>
                            </div>
                            <div v-if="updateAvailable" class="text-yellow-500">
                                <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <div v-else class="text-green-500">
                                <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Update Available Card -->
                <div v-if="updateAvailable && latestRelease" class="bg-gradient-to-r from-yellow-50 to-yellow-100 dark:from-yellow-900/20 dark:to-yellow-800/20 shadow-sm sm:rounded-lg border border-yellow-200 dark:border-yellow-800">
                    <div class="p-6">
                        <div class="flex items-start justify-between mb-4">
                            <div>
                                <h3 class="text-lg font-semibold text-yellow-900 dark:text-yellow-100">Update Available</h3>
                                <p class="text-sm text-yellow-700 dark:text-yellow-300 mt-1">A new version is ready to install</p>
                            </div>
                            <span class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">{{ latestRelease.version }}</span>
                        </div>

                        <div v-if="latestRelease.name" class="mb-3">
                            <h4 class="font-semibold text-yellow-900 dark:text-yellow-100">{{ latestRelease.name }}</h4>
                        </div>

                        <div v-if="latestRelease.body" class="mb-4 p-4 bg-white dark:bg-dark-bg/50 rounded-lg">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">Release Notes:</h4>
                            <div class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ latestRelease.body }}</div>
                        </div>

                        <div v-if="latestRelease.html_url" class="mb-4">
                            <a :href="latestRelease.html_url" target="_blank" class="text-sm text-yellow-700 dark:text-yellow-300 hover:underline">
                                View on GitHub →
                            </a>
                        </div>

                        <button
                            @click="performUpdate"
                            :disabled="isUpdating"
                            class="w-full px-4 py-3 bg-yellow-500 hover:bg-yellow-600 text-white font-semibold rounded-lg transition disabled:opacity-50"
                        >
                            {{ isUpdating ? 'Updating...' : 'Install Update' }}
                        </button>

                        <p class="text-xs text-yellow-700 dark:text-yellow-300 mt-2 text-center">
                            A backup will be created automatically before updating
                        </p>
                    </div>
                </div>

                <!-- No Update Available -->
                <div v-else-if="!updateAvailable" class="bg-gradient-to-r from-green-50 to-green-100 dark:from-green-900/20 dark:to-green-800/20 shadow-sm sm:rounded-lg border border-green-200 dark:border-green-800">
                    <div class="p-6">
                        <div class="flex items-center">
                            <svg class="w-12 h-12 text-green-500 mr-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <div>
                                <h3 class="text-lg font-semibold text-green-900 dark:text-green-100">You're up to date!</h3>
                                <p class="text-sm text-green-700 dark:text-green-300 mt-1">You're running the latest version of the application</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Update Progress -->
                <div v-if="isUpdating || updateResult" class="bg-white dark:bg-dark-card shadow-sm sm:rounded-lg border border-gray-200 dark:border-dark-border">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold mb-4 text-gray-900 dark:text-gray-100">Update Progress</h3>

                        <div v-if="isUpdating" class="space-y-2">
                            <div class="flex items-center">
                                <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-primary-500 mr-3"></div>
                                <span class="text-gray-600 dark:text-gray-400">Updating application... This may take several minutes.</span>
                            </div>
                        </div>

                        <div v-if="updateResult" class="mt-4">
                            <div v-if="updateResult.success" class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                                <p class="text-green-800 dark:text-green-200 font-semibold">✓ {{ updateResult.message }}</p>
                                <p v-if="updateResult.new_version" class="text-sm text-green-700 dark:text-green-300 mt-2">
                                    Updated to version: {{ updateResult.new_version }}
                                </p>
                                <p class="text-sm text-green-700 dark:text-green-300 mt-2">
                                    The page will reload automatically...
                                </p>
                            </div>
                            <div v-else class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                                <p class="text-red-800 dark:text-red-200 font-semibold">✗ {{ updateResult.message }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Backup Management -->
                <div class="bg-white dark:bg-dark-card shadow-sm sm:rounded-lg border border-gray-200 dark:border-dark-border">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Backup Management</h3>
                            <button
                                @click="createBackup"
                                :disabled="isCreatingBackup"
                                class="px-4 py-2 bg-green-500 hover:bg-green-600 text-white font-medium rounded-lg transition disabled:opacity-50"
                            >
                                {{ isCreatingBackup ? 'Creating...' : 'Create Backup' }}
                            </button>
                        </div>

                        <p class="text-gray-500 dark:text-gray-400 mb-4">
                            Backups are created automatically before each update. You can also create manual backups here.
                        </p>

                        <div v-if="backups && backups.length > 0" class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                                <thead class="bg-gray-50 dark:bg-dark-bg/50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Filename</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Size</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Created</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-dark-border">
                                    <tr v-for="backup in backups" :key="backup.filename">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-600 dark:text-gray-300">
                                            {{ backup.filename }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ formatBytes(backup.size) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ formatDate(backup.created_at) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-3">
                                            <button
                                                @click="restoreBackup(backup.filename)"
                                                :disabled="isRestoring"
                                                class="text-blue-500 hover:text-blue-600 disabled:opacity-50"
                                            >
                                                Restore
                                            </button>
                                            <button
                                                @click="deleteBackup(backup.filename)"
                                                class="text-red-500 hover:text-red-600"
                                            >
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="text-center py-8 text-gray-500 dark:text-gray-400">
                            No backups available. Click "Create Backup" to create your first backup.
                        </div>
                    </div>
                </div>

                <!-- Information Card -->
                <div class="bg-blue-50 dark:bg-blue-900/20 shadow-sm sm:rounded-lg border border-blue-200 dark:border-blue-800">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-3">Update Information</h3>
                        <ul class="space-y-2 text-sm text-blue-800 dark:text-blue-200">
                            <li class="flex items-start">
                                <svg class="w-5 h-5 mr-2 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                </svg>
                                <span>Updates are fetched from <strong>{{ githubRepo }}</strong> on GitHub</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="w-5 h-5 mr-2 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                </svg>
                                <span>A backup is automatically created before each update</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="w-5 h-5 mr-2 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                </svg>
                                <span>The application will be in maintenance mode during updates</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="w-5 h-5 mr-2 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                </svg>
                                <span>Database migrations are run automatically during updates</span>
                            </li>
                        </ul>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
