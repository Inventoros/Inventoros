<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Plus, Info, Eye, Pencil, Trash2, Webhook, X } from '@lucide/vue';

import { useI18n } from 'vue-i18n';
const props = defineProps({
    webhooks: Array,
    availableEvents: Array,
    eventGroups: Object,
});


const { t } = useI18n();
// Modal state
const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingWebhook = ref(null);

// Create form
const createForm = useForm({
    name: '',
    url: '',
    events: [],
    is_active: true,
});

// Edit form
const editForm = useForm({
    name: '',
    url: '',
    events: [],
    is_active: true,
});

const openCreateModal = () => {
    createForm.reset();
    createForm.is_active = true;
    showCreateModal.value = true;
};

const closeCreateModal = () => {
    showCreateModal.value = false;
    createForm.reset();
};

const openEditModal = (webhook) => {
    editingWebhook.value = webhook;
    editForm.name = webhook.name;
    editForm.url = webhook.url;
    editForm.events = [...webhook.events];
    editForm.is_active = webhook.is_active;
    showEditModal.value = true;
};

const closeEditModal = () => {
    showEditModal.value = false;
    editingWebhook.value = null;
    editForm.reset();
};

const submitCreate = () => {
    createForm.post(route('webhooks.store'), {
        onSuccess: () => {
            closeCreateModal();
        },
    });
};

const submitEdit = () => {
    editForm.put(route('webhooks.update', editingWebhook.value.id), {
        onSuccess: () => {
            closeEditModal();
        },
    });
};

const deleteWebhook = (webhook) => {
    if (confirm(`Are you sure you want to delete "${webhook.name}"?`)) {
        router.delete(route('webhooks.destroy', webhook.id));
    }
};

const truncateUrl = (url, maxLength = 40) => {
    if (url.length <= maxLength) return url;
    return url.substring(0, maxLength) + '...';
};

const toggleEvent = (form, event) => {
    const index = form.events.indexOf(event);
    if (index > -1) {
        form.events.splice(index, 1);
    } else {
        form.events.push(event);
    }
};

const isEventSelected = (form, event) => {
    return form.events.includes(event);
};

const selectAllInGroup = (form, group) => {
    const groupEvents = Object.keys(props.eventGroups[group]);
    const allSelected = groupEvents.every(event => form.events.includes(event));

    if (allSelected) {
        // Deselect all
        groupEvents.forEach(event => {
            const index = form.events.indexOf(event);
            if (index > -1) {
                form.events.splice(index, 1);
            }
        });
    } else {
        // Select all
        groupEvents.forEach(event => {
            if (!form.events.includes(event)) {
                form.events.push(event);
            }
        });
    }
};

const isGroupSelected = (form, group) => {
    const groupEvents = Object.keys(props.eventGroups[group]);
    return groupEvents.every(event => form.events.includes(event));
};

const isGroupPartiallySelected = (form, group) => {
    const groupEvents = Object.keys(props.eventGroups[group]);
    const selectedCount = groupEvents.filter(event => form.events.includes(event)).length;
    return selectedCount > 0 && selectedCount < groupEvents.length;
};

const getDeliveryStats = (webhook) => {
    // This could be enhanced with actual delivery stats from the backend
    return {
        total: webhook.deliveries_count || 0,
    };
};

const thClass =
    'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('nav.webhooks')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('settings.account.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('settings.account.index')" class="text-text-tertiary hover:text-text-primary">Settings</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('settings.webhooks.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('settings.webhooks.title')" description="External services that receive real-time event notifications.">
            <template #actions>
                <Button variant="default" size="sm" @click="openCreateModal">
                    <Plus :size="14" />
                    Add Webhook
                </Button>
            </template>
        </PageHeader>

        <!-- Info Banner -->
        <div class="mt-6 flex items-start gap-3 rounded-lg border border-status-info/20 bg-status-info-soft p-4">
            <Info :size="18" class="mt-0.5 shrink-0 text-status-info" />
            <div>
                <h3 class="text-sm font-medium text-text-primary">About Webhooks</h3>
                <p class="mt-1 text-sm text-text-secondary">
                    Webhooks allow external services to receive real-time notifications when events occur in your organization.
                    All webhook payloads are signed with HMAC-SHA256 for security verification.
                </p>
            </div>
        </div>

        <!-- Webhooks table -->
        <div class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">{{ t('common.name') }}</th>
                        <th :class="thClass">URL</th>
                        <th :class="thClass">Events</th>
                        <th :class="thClass">{{ t('common.status') }}</th>
                        <th :class="thClass">Deliveries</th>
                        <th :class="[thClass, 'text-right']">{{ t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="webhooks.length === 0">
                        <td colspan="6" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <Webhook :size="22" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">No webhooks configured</p>
                                <Button variant="default" size="sm" @click="openCreateModal">
                                    <Plus :size="14" />
                                    Create Your First Webhook
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-for="webhook in webhooks" :key="webhook.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                        <td class="px-4 py-3">
                            <div class="font-medium text-text-primary">{{ webhook.name }}</div>
                            <div class="text-xs text-text-tertiary">Created by {{ webhook.creator?.name || 'Unknown' }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-mono text-sm text-text-primary" :title="webhook.url">{{ truncateUrl(webhook.url) }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <Badge variant="brand" size="sm">{{ webhook.events?.length || 0 }} events</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="webhook.is_active ? 'success' : 'neutral'" size="sm">
                                {{ webhook.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <span class="tabular-nums text-text-secondary">{{ getDeliveryStats(webhook).total }} total</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Link :href="route('webhooks.show', webhook.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" title="View Details"><Eye :size="16" /></Link>
                                <button @click="openEditModal(webhook)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-success" title="Edit"><Pencil :size="16" /></button>
                                <button @click="deleteWebhook(webhook)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-danger" title="Delete"><Trash2 :size="16" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Create Modal -->
        <Teleport to="body">
            <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="fixed inset-0 bg-black/50" @click="closeCreateModal"></div>
                <div class="relative mx-4 my-8 max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg">
                    <div class="mb-6 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">
                            {{ t('settings.webhooks.createWebhook') }}
                        </h3>
                        <button @click="closeCreateModal" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-text-primary">
                            <X :size="18" />
                        </button>
                    </div>

                    <form @submit.prevent="submitCreate" class="space-y-6">
                        <!-- Name -->
                        <div>
                            <label for="create-name" :class="fieldLabel">Name</label>
                            <input
                                id="create-name"
                                v-model="createForm.name"
                                type="text"
                                :class="fieldInput"
                                placeholder="My Webhook"
                            />
                            <p v-if="createForm.errors.name" :class="fieldError">{{ createForm.errors.name }}</p>
                        </div>

                        <!-- URL -->
                        <div>
                            <label for="create-url" :class="fieldLabel">Endpoint URL</label>
                            <input
                                id="create-url"
                                v-model="createForm.url"
                                type="url"
                                :class="[fieldInput, 'font-mono']"
                                placeholder="https://example.com/webhook"
                            />
                            <p v-if="createForm.errors.url" :class="fieldError">{{ createForm.errors.url }}</p>
                        </div>

                        <!-- Events -->
                        <div>
                            <label :class="fieldLabel">Events</label>
                            <p class="mb-3 text-sm text-text-tertiary">
                                Select the events you want to receive notifications for.
                            </p>
                            <div class="max-h-64 space-y-4 overflow-y-auto rounded-md border border-border-subtle p-4">
                                <div v-for="(events, group) in eventGroups" :key="group" class="space-y-2">
                                    <div class="flex items-center">
                                        <input
                                            :id="`create-group-${group}`"
                                            type="checkbox"
                                            :checked="isGroupSelected(createForm, group)"
                                            :indeterminate="isGroupPartiallySelected(createForm, group)"
                                            @change="selectAllInGroup(createForm, group)"
                                            class="rounded border-border-strong bg-surface-canvas text-brand focus:ring-brand"
                                        />
                                        <label :for="`create-group-${group}`" class="ml-2 text-sm font-medium text-text-primary">
                                            {{ group }}
                                        </label>
                                    </div>
                                    <div class="ml-6 space-y-1">
                                        <div v-for="(description, event) in events" :key="event" class="flex items-start">
                                            <input
                                                :id="`create-event-${event}`"
                                                type="checkbox"
                                                :checked="isEventSelected(createForm, event)"
                                                @change="toggleEvent(createForm, event)"
                                                class="mt-1 rounded border-border-strong bg-surface-canvas text-brand focus:ring-brand"
                                            />
                                            <label :for="`create-event-${event}`" class="ml-2">
                                                <span class="font-mono text-sm text-text-primary">{{ event }}</span>
                                                <p class="text-xs text-text-tertiary">{{ description }}</p>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p v-if="createForm.errors.events" :class="fieldError">{{ createForm.errors.events }}</p>
                        </div>

                        <!-- Active Toggle -->
                        <div class="flex items-center">
                            <input
                                id="create-active"
                                v-model="createForm.is_active"
                                type="checkbox"
                                class="rounded border-border-strong bg-surface-canvas text-brand focus:ring-brand"
                            />
                            <label for="create-active" class="ml-2 text-sm text-text-primary">
                                {{ t('common.active') }}
                            </label>
                            <span class="ml-2 text-xs text-text-tertiary">
                                (Inactive webhooks will not receive any deliveries)
                            </span>
                        </div>

                        <!-- Actions -->
                        <div class="flex justify-end gap-2 border-t border-border-subtle pt-4">
                            <Button type="button" variant="secondary" size="sm" @click="closeCreateModal">
                                {{ t('common.cancel') }}
                            </Button>
                            <Button type="submit" variant="default" size="sm" :loading="createForm.processing" :disabled="createForm.processing">
                                <span v-if="createForm.processing">Creating...</span>
                                <span v-else>{{ t('settings.webhooks.createWebhook') }}</span>
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Edit Modal -->
        <Teleport to="body">
            <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="fixed inset-0 bg-black/50" @click="closeEditModal"></div>
                <div class="relative mx-4 my-8 max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg">
                    <div class="mb-6 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">
                            Edit Webhook
                        </h3>
                        <button @click="closeEditModal" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-text-primary">
                            <X :size="18" />
                        </button>
                    </div>

                    <form @submit.prevent="submitEdit" class="space-y-6">
                        <!-- Name -->
                        <div>
                            <label for="edit-name" :class="fieldLabel">Name</label>
                            <input
                                id="edit-name"
                                v-model="editForm.name"
                                type="text"
                                :class="fieldInput"
                                placeholder="My Webhook"
                            />
                            <p v-if="editForm.errors.name" :class="fieldError">{{ editForm.errors.name }}</p>
                        </div>

                        <!-- URL -->
                        <div>
                            <label for="edit-url" :class="fieldLabel">Endpoint URL</label>
                            <input
                                id="edit-url"
                                v-model="editForm.url"
                                type="url"
                                :class="[fieldInput, 'font-mono']"
                                placeholder="https://example.com/webhook"
                            />
                            <p v-if="editForm.errors.url" :class="fieldError">{{ editForm.errors.url }}</p>
                        </div>

                        <!-- Events -->
                        <div>
                            <label :class="fieldLabel">Events</label>
                            <p class="mb-3 text-sm text-text-tertiary">
                                Select the events you want to receive notifications for.
                            </p>
                            <div class="max-h-64 space-y-4 overflow-y-auto rounded-md border border-border-subtle p-4">
                                <div v-for="(events, group) in eventGroups" :key="group" class="space-y-2">
                                    <div class="flex items-center">
                                        <input
                                            :id="`edit-group-${group}`"
                                            type="checkbox"
                                            :checked="isGroupSelected(editForm, group)"
                                            :indeterminate="isGroupPartiallySelected(editForm, group)"
                                            @change="selectAllInGroup(editForm, group)"
                                            class="rounded border-border-strong bg-surface-canvas text-brand focus:ring-brand"
                                        />
                                        <label :for="`edit-group-${group}`" class="ml-2 text-sm font-medium text-text-primary">
                                            {{ group }}
                                        </label>
                                    </div>
                                    <div class="ml-6 space-y-1">
                                        <div v-for="(description, event) in events" :key="event" class="flex items-start">
                                            <input
                                                :id="`edit-event-${event}`"
                                                type="checkbox"
                                                :checked="isEventSelected(editForm, event)"
                                                @change="toggleEvent(editForm, event)"
                                                class="mt-1 rounded border-border-strong bg-surface-canvas text-brand focus:ring-brand"
                                            />
                                            <label :for="`edit-event-${event}`" class="ml-2">
                                                <span class="font-mono text-sm text-text-primary">{{ event }}</span>
                                                <p class="text-xs text-text-tertiary">{{ description }}</p>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p v-if="editForm.errors.events" :class="fieldError">{{ editForm.errors.events }}</p>
                        </div>

                        <!-- Active Toggle -->
                        <div class="flex items-center">
                            <input
                                id="edit-active"
                                v-model="editForm.is_active"
                                type="checkbox"
                                class="rounded border-border-strong bg-surface-canvas text-brand focus:ring-brand"
                            />
                            <label for="edit-active" class="ml-2 text-sm text-text-primary">
                                {{ t('common.active') }}
                            </label>
                            <span class="ml-2 text-xs text-text-tertiary">
                                (Inactive webhooks will not receive any deliveries)
                            </span>
                        </div>

                        <!-- Actions -->
                        <div class="flex justify-end gap-2 border-t border-border-subtle pt-4">
                            <Button type="button" variant="secondary" size="sm" @click="closeEditModal">
                                {{ t('common.cancel') }}
                            </Button>
                            <Button type="submit" variant="default" size="sm" :loading="editForm.processing" :disabled="editForm.processing">
                                <span v-if="editForm.processing">Saving...</span>
                                <span v-else>Save Changes</span>
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

