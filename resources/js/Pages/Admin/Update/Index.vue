<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';
import {
    CircleCheck,
    TriangleAlert,
    Info,
    ExternalLink,
    Database,
    Trash2,
    RotateCcw,
} from 'lucide-vue-next';

import { useI18n } from 'vue-i18n';
import { displayDateTime } from '@/lib/dates';
const props = defineProps({
    currentVersion: String,
    latestRelease: Object,
    updateAvailable: Boolean,
    backups: Array,
    githubRepo: String,
});


const { t } = useI18n();
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
    return displayDateTime(new Date(timestamp * 1000));
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
        alert(t('admin.update.checkFailed'));
    } finally {
        isCheckingUpdate.value = false;
    }
};

const performUpdate = async () => {
    if (!confirm(t('admin.update.confirmUpdate'))) {
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
            message: error.response?.data?.message || t('admin.update.updateFailed'),
        };
    } finally {
        isUpdating.value = false;
    }
};

const createBackup = async () => {
    if (!confirm(t('admin.update.confirmBackup'))) {
        return;
    }

    isCreatingBackup.value = true;

    try {
        const response = await axios.post(route('admin.update.backup'));

        if (response.data.success) {
            alert(response.data.message || t('admin.update.backupCreated'));
            router.reload();
        }
    } catch (error) {
        console.error('Backup failed:', error);
        alert(error.response?.data?.message || t('admin.update.backupFailed'));
    } finally {
        isCreatingBackup.value = false;
    }
};

const restoreBackup = async (backupFile) => {
    if (!confirm(t('admin.update.confirmRestore', { file: backupFile }))) {
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
            alert(t('admin.update.restoreCompleted'));
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            alert(response.data.message || t('admin.update.restoreFailed'));
        }
    } catch (error) {
        console.error('Restore failed:', error);
        alert(t('admin.update.restoreError'));
    } finally {
        isRestoring.value = false;
    }
};

const deleteBackup = async (backupFile) => {
    if (!confirm(t('admin.update.confirmDeleteBackup', { file: backupFile }))) {
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
        alert(t('admin.update.deleteBackupFailed'));
    }
};

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('admin.update.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('settings.account.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('settings.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.settings') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('nav.updates') }}</span>
            </div>
        </template>

        <PageHeader
            :title="t('admin.update.managerTitle')"
            :description="t('admin.update.subtitle')"
        >
            <template #actions>
                <Button
                    variant="secondary"
                    size="sm"
                    :loading="isCheckingUpdate"
                    :disabled="isCheckingUpdate"
                    @click="checkForUpdates"
                >
                    {{ isCheckingUpdate ? t('admin.update.checking') : t('admin.update.checkForUpdates') }}
                </Button>
            </template>
        </PageHeader>

        <div class="mt-6 space-y-4">
            <!-- Current Version Card -->
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('admin.update.currentVersion') }}</h3>
                </div>
                <div class="p-5">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <p class="text-3xl font-bold tabular-nums text-brand">{{ currentVersion }}</p>
                            <Badge :variant="updateAvailable ? 'warning' : 'success'" size="md" dot>
                                {{ updateAvailable ? t('admin.update.updateAvailable') : t('admin.update.upToDateBadge') }}
                            </Badge>
                        </div>
                        <component
                            :is="updateAvailable ? TriangleAlert : CircleCheck"
                            :size="40"
                            :class="updateAvailable ? 'text-status-warning' : 'text-status-success'"
                        />
                    </div>
                    <p class="mt-2 text-xs text-text-tertiary">{{ t('admin.update.installedVersion') }}</p>
                </div>
            </Card>

            <!-- Update Available Card -->
            <Card v-if="updateAvailable && latestRelease" :padded="false">
                <div class="flex items-start justify-between gap-4 px-5 pt-5">
                    <div>
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('admin.update.updateAvailable') }}</h3>
                        <p class="mt-0.5 text-xs text-text-secondary">{{ t('admin.update.newVersionReady') }}</p>
                    </div>
                    <Badge variant="brand" size="md">{{ latestRelease.version }}</Badge>
                </div>
                <div class="p-5">
                    <div v-if="latestRelease.name" class="mb-3">
                        <h4 class="text-sm font-semibold text-text-primary">{{ latestRelease.name }}</h4>
                    </div>

                    <div v-if="latestRelease.body" class="mb-4">
                        <h4 class="mb-2 text-xs font-medium uppercase tracking-wider text-text-tertiary">{{ t('admin.update.releaseNotes') }}</h4>
                        <div class="whitespace-pre-wrap rounded-lg border border-border-subtle bg-surface-canvas p-4 text-sm text-text-secondary">{{ latestRelease.body }}</div>
                    </div>

                    <div v-if="latestRelease.html_url" class="mb-4">
                        <a
                            :href="latestRelease.html_url"
                            target="_blank"
                            class="inline-flex items-center gap-1.5 text-sm text-brand hover:underline"
                        >
                            {{ t('admin.update.viewOnGithub') }}
                            <ExternalLink :size="14" />
                        </a>
                    </div>

                    <Button
                        variant="default"
                        class="w-full"
                        :loading="isUpdating"
                        :disabled="isUpdating"
                        @click="performUpdate"
                    >
                        {{ isUpdating ? t('admin.update.updating') : t('admin.update.installUpdate') }}
                    </Button>

                    <p class="mt-2 text-center text-xs text-text-tertiary">
                        {{ t('admin.update.backupNote') }}
                    </p>
                </div>
            </Card>

            <!-- No Update Available -->
            <Card v-else-if="!updateAvailable">
                <div class="flex items-center gap-4">
                    <CircleCheck :size="32" class="shrink-0 text-status-success" />
                    <div>
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('admin.update.upToDate') }}</h3>
                        <p class="mt-0.5 text-sm text-text-secondary">{{ t('admin.update.latestVersion') }}</p>
                    </div>
                </div>
            </Card>

            <!-- Update Progress -->
            <Card v-if="isUpdating || updateResult" :padded="false">
                <div class="px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('admin.update.updateProgress') }}</h3>
                </div>
                <div class="p-5">
                    <div v-if="isUpdating" class="space-y-3">
                        <div class="flex items-center gap-3">
                            <span class="h-4 w-4 animate-spin rounded-full border-2 border-brand border-r-transparent"></span>
                            <span class="text-sm text-text-secondary">{{ t('admin.update.updatingApp') }}</span>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-surface-sunken">
                            <div class="h-full w-1/3 animate-pulse rounded-full bg-brand"></div>
                        </div>
                    </div>

                    <div v-if="updateResult" :class="isUpdating ? 'mt-4' : ''">
                        <div
                            v-if="updateResult.success"
                            class="rounded-lg border border-status-success/20 bg-status-success-soft p-4"
                        >
                            <div class="flex items-start gap-3">
                                <CircleCheck :size="18" class="mt-0.5 shrink-0 text-status-success" />
                                <div class="text-sm text-status-success">
                                    <p class="font-semibold">{{ updateResult.message }}</p>
                                    <p v-if="updateResult.new_version" class="mt-1">
                                        {{ t('admin.update.updatedTo', { version: updateResult.new_version }) }}
                                    </p>
                                    <p class="mt-1">{{ t('admin.update.willReload') }}</p>
                                </div>
                            </div>
                        </div>
                        <div
                            v-else
                            class="rounded-lg border border-status-danger/20 bg-status-danger-soft p-4"
                        >
                            <div class="flex items-start gap-3">
                                <TriangleAlert :size="18" class="mt-0.5 shrink-0 text-status-danger" />
                                <p class="text-sm font-semibold text-status-danger">{{ updateResult.message }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Backup Management -->
            <Card :padded="false">
                <div class="flex items-start justify-between gap-4 px-5 pt-5">
                    <div>
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('admin.update.backupManagement') }}</h3>
                        <p class="mt-0.5 text-xs text-text-secondary">
                            {{ t('admin.update.backupHint') }}
                        </p>
                    </div>
                    <Button
                        variant="secondary"
                        size="sm"
                        :loading="isCreatingBackup"
                        :disabled="isCreatingBackup"
                        @click="createBackup"
                    >
                        <Database :size="14" />
                        {{ isCreatingBackup ? t('admin.update.creatingBackup') : t('admin.update.createBackup') }}
                    </Button>
                </div>

                <div class="p-5">
                    <div
                        v-if="backups && backups.length > 0"
                        class="w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised"
                    >
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border-subtle">
                                    <th :class="thClass">{{ t('admin.update.filename') }}</th>
                                    <th :class="thClass">{{ t('admin.update.size') }}</th>
                                    <th :class="thClass">{{ t('common.createdAt') }}</th>
                                    <th :class="[thClass, 'text-right']">{{ t('common.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="backup in backups"
                                    :key="backup.filename"
                                    class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                                >
                                    <td class="px-4 py-3 font-mono text-xs text-text-secondary">{{ backup.filename }}</td>
                                    <td class="px-4 py-3 tabular-nums text-text-secondary">{{ formatBytes(backup.size) }}</td>
                                    <td class="px-4 py-3 text-text-secondary">{{ formatDate(backup.created_at) }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-end gap-1">
                                            <button
                                                @click="restoreBackup(backup.filename)"
                                                :disabled="isRestoring"
                                                class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-brand disabled:opacity-50"
                                                :title="t('admin.update.restore')"
                                            >
                                                <RotateCcw :size="16" />
                                            </button>
                                            <button
                                                @click="deleteBackup(backup.filename)"
                                                class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-danger"
                                                :title="t('common.delete')"
                                            >
                                                <Trash2 :size="16" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
                        <Database :size="22" class="text-text-tertiary" />
                        <p class="text-sm text-text-tertiary">{{ t('admin.update.noBackups') }}</p>
                    </div>
                </div>
            </Card>

            <!-- Information Card -->
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('admin.update.updateInfo') }}</h3>
                </div>
                <div class="p-5">
                    <ul class="space-y-2.5 text-sm text-text-secondary">
                        <li class="flex items-start gap-2.5">
                            <Info :size="16" class="mt-0.5 shrink-0 text-brand" />
                            <span>{{ t('admin.update.fetchedFromRepo', { repo: githubRepo }) }}</span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <Info :size="16" class="mt-0.5 shrink-0 text-brand" />
                            <span>{{ t('admin.update.autoBackup') }}</span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <Info :size="16" class="mt-0.5 shrink-0 text-brand" />
                            <span>{{ t('admin.update.maintenanceMode') }}</span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <Info :size="16" class="mt-0.5 shrink-0 text-brand" />
                            <span>{{ t('admin.update.autoMigrations') }}</span>
                        </li>
                    </ul>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
