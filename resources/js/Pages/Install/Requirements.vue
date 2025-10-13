<script setup>
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    requirements: Array,
    allMet: Boolean,
});
</script>

<template>
    <Head title="System Requirements" />

    <div class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 flex items-center justify-center p-4">
        <div class="max-w-3xl w-full">
            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-900 mb-2">System Requirements</h1>
                <p class="text-gray-600">Checking your server configuration</p>
            </div>

            <!-- Requirements Card -->
            <div class="bg-white rounded-lg shadow-lg p-8">
                <!-- Status Banner -->
                <div v-if="allMet" class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                    <div class="flex">
                        <svg class="w-6 h-6 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                        <div class="ml-3">
                            <h3 class="text-sm font-semibold text-green-900">All requirements met!</h3>
                            <p class="text-sm text-green-800 mt-1">Your server meets all the requirements to run Inventoros.</p>
                        </div>
                    </div>
                </div>

                <div v-else class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                    <div class="flex">
                        <svg class="w-6 h-6 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                        </svg>
                        <div class="ml-3">
                            <h3 class="text-sm font-semibold text-red-900">Some requirements are not met</h3>
                            <p class="text-sm text-red-800 mt-1">Please fix the issues below before continuing.</p>
                        </div>
                    </div>
                </div>

                <!-- Requirements List -->
                <div class="space-y-3">
                    <div
                        v-for="(requirement, index) in requirements"
                        :key="index"
                        class="flex items-center justify-between p-4 border rounded-lg"
                        :class="requirement.met ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'"
                    >
                        <div class="flex items-center flex-1">
                            <div
                                class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center"
                                :class="requirement.met ? 'bg-green-100' : 'bg-red-100'"
                            >
                                <svg
                                    v-if="requirement.met"
                                    class="w-6 h-6 text-green-600"
                                    fill="currentColor"
                                    viewBox="0 0 20 20"
                                >
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                                <svg
                                    v-else
                                    class="w-6 h-6 text-red-600"
                                    fill="currentColor"
                                    viewBox="0 0 20 20"
                                >
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-4">
                                <h3 class="font-semibold" :class="requirement.met ? 'text-green-900' : 'text-red-900'">
                                    {{ requirement.name }}
                                </h3>
                                <div class="text-sm mt-1">
                                    <span :class="requirement.met ? 'text-green-700' : 'text-red-700'">
                                        Current: {{ requirement.current }}
                                    </span>
                                    <span class="text-gray-500 mx-2">•</span>
                                    <span class="text-gray-600">
                                        Required: {{ requirement.required }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex justify-between mt-8 pt-6 border-t">
                    <Link
                        :href="route('install.index')"
                        class="inline-flex items-center px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition"
                    >
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                        Back
                    </Link>

                    <Link
                        v-if="allMet"
                        :href="route('install.database')"
                        class="inline-flex items-center px-6 py-2 bg-gray-900 text-white font-semibold rounded-lg hover:bg-gray-800 transition"
                    >
                        Continue
                        <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                    </Link>

                    <button
                        v-else
                        disabled
                        class="inline-flex items-center px-6 py-2 bg-gray-300 text-gray-500 font-semibold rounded-lg cursor-not-allowed"
                    >
                        Fix Issues First
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
