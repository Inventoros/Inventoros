<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { UploadCloud, Loader2, Puzzle, ExternalLink } from '@lucide/vue';

const props = defineProps({
    plugins: Array,
});

const { t } = useI18n();
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
    <Head :title="t('nav.plugins')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('plugins.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('plugins.title')" description="Extend your workspace with installable plugins." />

        <!-- Upload Section -->
        <Card class="mt-6">
            <h3 class="text-sm font-semibold text-text-primary">Upload New Plugin</h3>
            <div
                @dragover.prevent="isDragging = true"
                @dragleave.prevent="isDragging = false"
                @drop.prevent="handleDrop"
                :class="[
                    'mt-4 rounded-lg border-2 border-dashed p-8 text-center transition-colors',
                    isDragging
                        ? 'border-brand bg-brand-soft'
                        : 'border-border-subtle bg-surface-canvas',
                ]"
            >
                <UploadCloud :size="40" class="mx-auto text-text-tertiary" />
                <div class="mt-4 text-sm">
                    <label
                        for="plugin-upload"
                        class="cursor-pointer font-medium text-brand transition-colors hover:text-brand-hover"
                    >
                        Choose a ZIP file
                    </label>
                    <span class="text-text-secondary"> or drag and drop</span>
                    <input
                        ref="fileInput"
                        id="plugin-upload"
                        type="file"
                        accept=".zip"
                        class="hidden"
                        @change="handleFileSelect"
                    />
                </div>
                <p class="mt-2 text-xs text-text-tertiary">ZIP files only, max 50MB</p>
            </div>
            <div v-if="uploadForm.processing" class="mt-4 text-center">
                <div class="inline-flex items-center gap-2 rounded-md bg-brand-soft px-4 py-2 text-sm text-brand">
                    <Loader2 :size="16" class="animate-spin" />
                    <span>Uploading plugin...</span>
                </div>
            </div>
        </Card>

        <!-- Plugins List -->
        <div class="mt-6 space-y-4">
            <Card v-for="plugin in plugins" :key="plugin.slug">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="mb-2 flex items-center gap-3">
                            <h3 class="text-base font-semibold text-text-primary">
                                {{ plugin.name }}
                            </h3>
                            <Badge :variant="plugin.is_active ? 'success' : 'neutral'" size="sm" dot>
                                {{ plugin.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </div>
                        <p class="mb-3 text-sm text-text-secondary">
                            {{ plugin.description }}
                        </p>
                        <div class="flex flex-wrap gap-4 text-xs text-text-tertiary">
                            <span>Version: {{ plugin.version }}</span>
                            <span class="inline-flex items-center gap-1">Author:
                                <a
                                    v-if="plugin.author_url"
                                    :href="plugin.author_url"
                                    target="_blank"
                                    class="inline-flex items-center gap-1 text-brand transition-colors hover:text-brand-hover"
                                >
                                    {{ plugin.author }}
                                    <ExternalLink :size="11" />
                                </a>
                                <span v-else>{{ plugin.author }}</span>
                            </span>
                            <span>Requires: {{ plugin.requires }}</span>
                        </div>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <Button
                            v-if="!plugin.is_active"
                            variant="default"
                            size="sm"
                            @click="activatePlugin(plugin.slug)"
                        >
                            Activate
                        </Button>
                        <Button
                            v-else
                            variant="secondary"
                            size="sm"
                            @click="deactivatePlugin(plugin.slug)"
                        >
                            Deactivate
                        </Button>
                        <Button
                            variant="danger"
                            size="sm"
                            :disabled="plugin.is_active"
                            @click="deletePlugin(plugin.slug, plugin.name)"
                        >
                            {{ t('common.delete') }}
                        </Button>
                    </div>
                </div>
            </Card>

            <!-- Empty State -->
            <Card v-if="plugins.length === 0">
                <div class="flex flex-col items-center gap-3 py-12 text-center">
                    <Puzzle :size="28" class="text-text-tertiary" />
                    <h3 class="text-sm font-medium text-text-primary">No plugins installed</h3>
                    <p class="text-sm text-text-tertiary">
                        Get started by uploading your first plugin above.
                    </p>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>

