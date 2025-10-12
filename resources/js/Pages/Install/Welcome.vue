<script setup>
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Welcome to Inventoros" />

    <div class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 flex items-center justify-center p-4">
        <div class="max-w-2xl w-full">
            <!-- Logo and Title -->
            <div class="text-center mb-8">
                <div class="flex justify-center mb-4">
                    <svg class="w-16 h-16 text-gray-800" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                </div>
                <h1 class="text-4xl font-bold text-gray-900 mb-2">Welcome to Inventoros</h1>
                <p class="text-lg text-gray-600">Inventory Management for the Rest of Us</p>
            </div>

            <!-- Welcome Card -->
            <div class="bg-white rounded-lg shadow-lg p-8">
                <div class="mb-6">
                    <h2 class="text-2xl font-semibold text-gray-900 mb-4">Installation Wizard</h2>
                    <p class="text-gray-600 mb-4">
                        Thank you for choosing Inventoros! This installation wizard will guide you through the setup process.
                    </p>
                    <p class="text-gray-600">
                        The installation process includes:
                    </p>
                </div>

                <!-- Installation Steps -->
                <div class="space-y-4 mb-8">
                    <div class="flex items-start">
                        <div class="flex-shrink-0 w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center text-gray-700 font-semibold">
                            1
                        </div>
                        <div class="ml-4">
                            <h3 class="font-semibold text-gray-900">System Requirements Check</h3>
                            <p class="text-sm text-gray-600">Verify your server meets all requirements</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <div class="flex-shrink-0 w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center text-gray-700 font-semibold">
                            2
                        </div>
                        <div class="ml-4">
                            <h3 class="font-semibold text-gray-900">Database Configuration</h3>
                            <p class="text-sm text-gray-600">Set up your database connection</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <div class="flex-shrink-0 w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center text-gray-700 font-semibold">
                            3
                        </div>
                        <div class="ml-4">
                            <h3 class="font-semibold text-gray-900">Admin Account Creation</h3>
                            <p class="text-sm text-gray-600">Create your organization and admin user</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <div class="flex-shrink-0 w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center text-gray-700 font-semibold">
                            4
                        </div>
                        <div class="ml-4">
                            <h3 class="font-semibold text-gray-900">Complete Setup</h3>
                            <p class="text-sm text-gray-600">Finalize installation and start using Inventoros</p>
                        </div>
                    </div>
                </div>

                <!-- Requirements Notice -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                    <div class="flex">
                        <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                        </svg>
                        <div class="ml-3">
                            <h3 class="text-sm font-semibold text-blue-900">Before you begin</h3>
                            <p class="text-sm text-blue-800 mt-1">
                                Make sure you have PHP 8.2+, MySQL/PostgreSQL, and the required PHP extensions installed.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Start Button -->
                <div class="flex justify-end">
                    <Link
                        :href="route('install.requirements')"
                        class="inline-flex items-center px-6 py-3 bg-gray-900 text-white font-semibold rounded-lg hover:bg-gray-800 transition"
                    >
                        Start Installation
                        <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                    </Link>
                </div>
            </div>

            <!-- Footer -->
            <div class="text-center mt-6 text-sm text-gray-600">
                <p>Open-source under MIT License</p>
            </div>
        </div>
    </div>
</template>
