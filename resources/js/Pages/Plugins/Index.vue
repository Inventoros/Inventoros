<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    plugins: Array,
});

const uploadForm = useForm({
    plugin: null,
});

const fileInput = ref(null);
const isDragging = ref(false);

const handleFileSelect = (event) => {
    const file = event.target.files[0];
    if (file && file.name.endsWith('.zip')) {
        uploadForm.plugin = file;
        submitUpload();
    }
};

const handleDrop = (event) => {
    isDragging.value = false;
    const file = event.dataTransfer.files[0];
    if (file && file.name.endsWith('.zip')) {
        uploadForm.plugin = file;
        submitUpload();
    }
};

const submitUpload = () => {
    uploadForm.post(route('plugins.upload'), {
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset();
            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
};

const activatePlugin = (slug) => {
    router.post(route('plugins.activate', slug), {}, {
        preserveScroll: true,
    });
};

const deactivatePlugin = (slug) => {
    router.post(route('plugins.deactivate', slug), {}, {
        preserveScroll: true,
    });
};

const deletePlugin = (slug, name) => {
    if (confirm(`Are you sure you want to delete "${name}"? This action cannot be undone.`)) {
        router.delete(route('plugins.destroy', slug), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head title="Plugins" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                Plugins
            </h2>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Upload Section -->
                <div class="mb-6 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Upload New Plugin</h3>
                        <div
                            @dragover.prevent="isDragging = true"
                            @dragleave.prevent="isDragging = false"
                            @drop.prevent="handleDrop"
                            :class="[
                                'border-2 border-dashed rounded-lg p-8 text-center transition-colors',
                                isDragging
                                    ? 'border-primary-400 bg-primary-900/20'
                                    : 'border-dark-border bg-dark-bg/50'
                            ]"
                        >
                            <svg
                                class="mx-auto h-12 w-12 text-gray-500 dark:text-gray-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"
                                />
                            </svg>
                            <div class="mt-4">
                                <label
                                    for="plugin-upload"
                                    class="cursor-pointer text-primary-400 hover:text-primary-300 font-medium"
                                >
                                    Choose a ZIP file
                                </label>
                                <span class="text-gray-500 dark:text-gray-400"> or drag and drop</span>
                                <input
                                    ref="fileInput"
                                    id="plugin-upload"
                                    type="file"
                                    accept=".zip"
                                    class="hidden"
                                    @change="handleFileSelect"
                                />
                            </div>
                            <p class="text-xs text-gray-500 mt-2">ZIP files only, max 50MB</p>
                        </div>
                        <div v-if="uploadForm.processing" class="mt-4 text-center">
                            <div class="inline-flex items-center px-4 py-2 bg-primary-900/20 rounded-lg">
                                <svg class="animate-spin h-5 w-5 text-primary-400 mr-3" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span class="text-primary-400">Uploading plugin...</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Plugins List -->
                <div class="space-y-4">
                    <div
                        v-for="plugin in plugins"
                        :key="plugin.slug"
                        class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg"
                    >
                        <div class="p-6">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2">
                                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                                            {{ plugin.name }}
                                        </h3>
                                        <span
                                            :class="[
                                                'px-2 py-1 text-xs font-semibold rounded-full',
                                                plugin.is_active
                                                    ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300'
                                                    : 'bg-gray-900/30 text-gray-400'
                                            ]"
                                        >
                                            {{ plugin.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                                        {{ plugin.description }}
                                    </p>
                                    <div class="flex flex-wrap gap-4 text-xs text-gray-500">
                                        <span>Version: {{ plugin.version }}</span>
                                        <span>Author:
                                            <a
                                                v-if="plugin.author_url"
                                                :href="plugin.author_url"
                                                target="_blank"
                                                class="text-primary-400 hover:text-primary-300"
                                            >
                                                {{ plugin.author }}
                                            </a>
                                            <span v-else>{{ plugin.author }}</span>
                                        </span>
                                        <span>Requires: {{ plugin.requires }}</span>
                                    </div>
                                </div>
                                <div class="flex gap-2 ml-4">
                                    <button
                                        v-if="!plugin.is_active"
                                        @click="activatePlugin(plugin.slug)"
                                        class="px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 transition"
                                    >
                                        Activate
                                    </button>
                                    <button
                                        v-else
                                        @click="deactivatePlugin(plugin.slug)"
                                        class="px-4 py-2 bg-amber-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-amber-700 transition"
                                    >
                                        Deactivate
                                    </button>
                                    <button
                                        @click="deletePlugin(plugin.slug, plugin.name)"
                                        :disabled="plugin.is_active"
                                        :class="[
                                            'px-4 py-2 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest transition',
                                            plugin.is_active
                                                ? 'bg-gray-700 cursor-not-allowed opacity-50'
                                                : 'bg-red-600 hover:bg-red-700'
                                        ]"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div
                        v-if="plugins.length === 0"
                        class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg"
                    >
                        <div class="p-12 text-center">
                            <svg
                                class="mx-auto h-12 w-12 text-gray-600"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
                                />
                            </svg>
                            <h3 class="mt-4 text-lg font-medium text-gray-600 dark:text-gray-300">No plugins installed</h3>
                            <p class="mt-2 text-sm text-gray-500">
                                Get started by uploading your first plugin above.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
