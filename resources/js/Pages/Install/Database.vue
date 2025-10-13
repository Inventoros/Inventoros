<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    currentConfig: Object,
});

const form = useForm({
    host: props.currentConfig.host || 'localhost',
    port: props.currentConfig.port || 3306,
    database: props.currentConfig.database || '',
    username: props.currentConfig.username || '',
    password: '',
});

const testing = ref(false);
const testResult = ref(null);
const installing = ref(false);

const testConnection = async () => {
    testing.value = true;
    testResult.value = null;

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const response = await fetch(route('install.database.test'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(form.data()),
        });

        const data = await response.json();
        testResult.value = data;
    } catch (error) {
        testResult.value = {
            success: false,
            message: 'Failed to test connection: ' + error.message,
        };
    } finally {
        testing.value = false;
    }
};

const install = async () => {
    installing.value = true;

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const response = await fetch(route('install.database.install'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(form.data()),
        });

        const data = await response.json();

        if (data.success) {
            window.location.href = route('install.admin');
        } else {
            testResult.value = data;
        }
    } catch (error) {
        testResult.value = {
            success: false,
            message: 'Installation failed: ' + error.message,
        };
    } finally {
        installing.value = false;
    }
};
</script>

<template>
    <Head title="Database Configuration" />

    <div class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 flex items-center justify-center p-4">
        <div class="max-w-2xl w-full">
            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-900 mb-2">Database Configuration</h1>
                <p class="text-gray-600">Configure your database connection</p>
            </div>

            <!-- Database Form Card -->
            <div class="bg-white rounded-lg shadow-lg p-8">
                <!-- Test Result Alert -->
                <div v-if="testResult" class="mb-6">
                    <div
                        v-if="testResult.success"
                        class="bg-green-50 border border-green-200 rounded-lg p-4"
                    >
                        <div class="flex">
                            <svg class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                            <div class="ml-3">
                                <p class="text-sm font-semibold text-green-900">{{ testResult.message }}</p>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else
                        class="bg-red-50 border border-red-200 rounded-lg p-4"
                    >
                        <div class="flex">
                            <svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                            <div class="ml-3">
                                <p class="text-sm font-semibold text-red-900">{{ testResult.message }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <form @submit.prevent="install" class="space-y-6">
                    <!-- Database Host -->
                    <div>
                        <label for="host" class="block text-sm font-medium text-gray-700 mb-2">
                            Database Host
                        </label>
                        <input
                            id="host"
                            v-model="form.host"
                            type="text"
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-900 focus:border-transparent"
                            placeholder="localhost"
                        />
                    </div>

                    <!-- Database Port -->
                    <div>
                        <label for="port" class="block text-sm font-medium text-gray-700 mb-2">
                            Database Port
                        </label>
                        <input
                            id="port"
                            v-model.number="form.port"
                            type="number"
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-900 focus:border-transparent"
                            placeholder="3306"
                        />
                    </div>

                    <!-- Database Name -->
                    <div>
                        <label for="database" class="block text-sm font-medium text-gray-700 mb-2">
                            Database Name
                        </label>
                        <input
                            id="database"
                            v-model="form.database"
                            type="text"
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-900 focus:border-transparent"
                            placeholder="inventoros"
                        />
                    </div>

                    <!-- Database Username -->
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-2">
                            Database Username
                        </label>
                        <input
                            id="username"
                            v-model="form.username"
                            type="text"
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-900 focus:border-transparent"
                            placeholder="root"
                        />
                    </div>

                    <!-- Database Password -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                            Database Password
                        </label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-900 focus:border-transparent"
                            placeholder="Leave blank if no password"
                        />
                    </div>

                    <!-- Test Connection Button -->
                    <div>
                        <button
                            type="button"
                            @click="testConnection"
                            :disabled="testing"
                            class="w-full px-4 py-2 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition disabled:bg-blue-300"
                        >
                            <span v-if="testing">Testing Connection...</span>
                            <span v-else>Test Connection</span>
                        </button>
                    </div>

                    <!-- Info Box -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="flex">
                            <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                            </svg>
                            <div class="ml-3">
                                <p class="text-sm text-blue-800">
                                    Make sure your database exists before proceeding. The installer will create the necessary tables.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation -->
                    <div class="flex justify-between pt-6 border-t">
                        <Link
                            :href="route('install.requirements')"
                            class="inline-flex items-center px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                            Back
                        </Link>

                        <button
                            type="submit"
                            :disabled="installing || !testResult?.success"
                            class="inline-flex items-center px-6 py-2 bg-gray-900 text-white font-semibold rounded-lg hover:bg-gray-800 transition disabled:bg-gray-300 disabled:cursor-not-allowed"
                        >
                            <span v-if="installing">Installing...</span>
                            <span v-else>Install Database</span>
                            <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                            </svg>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
