<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { onMounted } from 'vue';

onMounted(() => {
    // Apply dark mode based on localStorage
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark' || !savedTheme) {
        document.documentElement.classList.add('dark');
    }
});
</script>

<template>
    <Head title="Access Denied - 403" />

    <div class="min-h-screen bg-gray-50 dark:bg-dark-bg flex items-center justify-center px-4">
        <div class="max-w-md w-full">
            <!-- Card -->
            <div class="bg-white dark:bg-dark-card rounded-lg shadow-xl border border-gray-200 dark:border-dark-border overflow-hidden">
                <!-- Header with icon -->
                <div class="bg-red-50 dark:bg-red-900/20 border-b border-red-100 dark:border-red-900/30 px-6 py-8 text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-red-100 dark:bg-red-900/30 rounded-full mb-4">
                        <svg class="w-8 h-8 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100 mb-2">Access Denied</h1>
                    <p class="text-red-600 dark:text-red-400 font-semibold">Error 403</p>
                </div>

                <!-- Content -->
                <div class="px-6 py-8">
                    <p class="text-gray-600 dark:text-gray-300 text-center mb-6">
                        You do not have the required permissions to perform this action. If you believe this is an error, please contact your administrator.
                    </p>

                    <!-- Info box -->
                    <div class="bg-blue-50 dark:bg-blue-900/10 border border-blue-200 dark:border-blue-900/30 rounded-lg p-4 mb-6">
                        <div class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <div class="text-sm">
                                <p class="font-semibold text-blue-800 dark:text-blue-300 mb-1">Need Access?</p>
                                <p class="text-blue-600 dark:text-blue-400">
                                    Contact your organization administrator to request the necessary permissions for this feature.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex flex-col gap-3">
                        <Link
                            :href="route('dashboard')"
                            class="w-full inline-flex items-center justify-center px-4 py-3 bg-primary-500 hover:bg-primary-600 text-white font-semibold rounded-lg transition"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                            </svg>
                            Go to Dashboard
                        </Link>
                        <button
                            @click="$inertia.visit($page.props.previousUrl || route('dashboard'))"
                            class="w-full inline-flex items-center justify-center px-4 py-3 bg-gray-100 dark:bg-dark-bg hover:bg-gray-200 dark:hover:bg-dark-bg/80 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition border border-gray-200 dark:border-dark-border"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                            Go Back
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
