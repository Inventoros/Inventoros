<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const form = useForm({
    organization_name: '',
    admin_name: '',
    admin_email: '',
    admin_password: '',
    admin_password_confirmation: '',
});

const creating = ref(false);
const error = ref(null);

const createAdmin = async () => {
    creating.value = true;
    error.value = null;

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const response = await fetch(route('install.admin.create'), {
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
            window.location.href = route('install.complete');
        } else {
            error.value = data.message;
        }
    } catch (err) {
        error.value = 'Failed to create admin account: ' + err.message;
    } finally {
        creating.value = false;
    }
};
</script>

<template>
    <Head title="Create Admin Account" />

    <div class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 flex items-center justify-center p-4">
        <div class="max-w-2xl w-full">
            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-900 mb-2">Create Admin Account</h1>
                <p class="text-gray-600">Set up your organization and admin user</p>
            </div>

            <!-- Admin Form Card -->
            <div class="bg-white rounded-lg shadow-lg p-8">
                <!-- Error Alert -->
                <div v-if="error" class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
                    <div class="flex">
                        <svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                        </svg>
                        <div class="ml-3">
                            <p class="text-sm font-semibold text-red-900">{{ error }}</p>
                        </div>
                    </div>
                </div>

                <form @submit.prevent="createAdmin" class="space-y-6">
                    <!-- Organization Name -->
                    <div>
                        <label for="organization_name" class="block text-sm font-medium text-gray-700 mb-2">
                            Organization Name
                        </label>
                        <input
                            id="organization_name"
                            v-model="form.organization_name"
                            type="text"
                            required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-900 focus:border-transparent"
                            placeholder="Acme Corporation"
                        />
                        <p class="mt-1 text-sm text-gray-500">The name of your company or organization</p>
                    </div>

                    <div class="pt-4 border-t">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Admin User Details</h3>

                        <!-- Admin Name -->
                        <div class="mb-6">
                            <label for="admin_name" class="block text-sm font-medium text-gray-700 mb-2">
                                Full Name
                            </label>
                            <input
                                id="admin_name"
                                v-model="form.admin_name"
                                type="text"
                                required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-900 focus:border-transparent"
                                placeholder="John Doe"
                            />
                        </div>

                        <!-- Admin Email -->
                        <div class="mb-6">
                            <label for="admin_email" class="block text-sm font-medium text-gray-700 mb-2">
                                Email Address
                            </label>
                            <input
                                id="admin_email"
                                v-model="form.admin_email"
                                type="email"
                                required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-900 focus:border-transparent"
                                placeholder="admin@example.com"
                            />
                        </div>

                        <!-- Admin Password -->
                        <div class="mb-6">
                            <label for="admin_password" class="block text-sm font-medium text-gray-700 mb-2">
                                Password
                            </label>
                            <input
                                id="admin_password"
                                v-model="form.admin_password"
                                type="password"
                                required
                                minlength="8"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-900 focus:border-transparent"
                                placeholder="At least 8 characters"
                            />
                        </div>

                        <!-- Confirm Password -->
                        <div>
                            <label for="admin_password_confirmation" class="block text-sm font-medium text-gray-700 mb-2">
                                Confirm Password
                            </label>
                            <input
                                id="admin_password_confirmation"
                                v-model="form.admin_password_confirmation"
                                type="password"
                                required
                                minlength="8"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-900 focus:border-transparent"
                                placeholder="Confirm your password"
                            />
                        </div>
                    </div>

                    <!-- Info Box -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="flex">
                            <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                            </svg>
                            <div class="ml-3">
                                <p class="text-sm text-blue-800">
                                    This admin account will have full access to your Inventoros installation.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation -->
                    <div class="flex justify-between pt-6 border-t">
                        <Link
                            :href="route('install.database')"
                            class="inline-flex items-center px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                            Back
                        </Link>

                        <button
                            type="submit"
                            :disabled="creating"
                            class="inline-flex items-center px-6 py-2 bg-gray-900 text-white font-semibold rounded-lg hover:bg-gray-800 transition disabled:bg-gray-300 disabled:cursor-not-allowed"
                        >
                            <span v-if="creating">Creating Account...</span>
                            <span v-else>Complete Installation</span>
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
