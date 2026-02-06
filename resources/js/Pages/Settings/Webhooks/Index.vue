<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    webhooks: Array,
    availableEvents: Array,
    eventGroups: Object,
});

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
</script>

<template>
    <Head title="Webhooks" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    Webhooks
                </h2>
                <button
                    @click="openCreateModal"
                    class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 focus:bg-primary-500 active:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-400 focus:ring-offset-2 focus:ring-offset-dark-bg transition ease-in-out duration-150"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Add Webhook
                </button>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Info Banner -->
                <div class="mb-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                    <div class="flex">
                        <svg class="w-5 h-5 text-blue-400 mr-3 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <div>
                            <h3 class="text-sm font-medium text-blue-800 dark:text-blue-300">About Webhooks</h3>
                            <p class="mt-1 text-sm text-blue-700 dark:text-blue-400">
                                Webhooks allow external services to receive real-time notifications when events occur in your organization.
                                All webhook payloads are signed with HMAC-SHA256 for security verification.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Webhooks Table -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                            <thead class="bg-gray-50 dark:bg-dark-bg/50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Name
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        URL
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Events
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Deliveries
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-dark-card divide-y divide-gray-200 dark:divide-dark-border">
                                <tr v-if="webhooks.length === 0">
                                    <td colspan="6" class="px-6 py-12 text-center">
                                        <svg class="w-12 h-12 text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                        </svg>
                                        <p class="text-gray-500 dark:text-gray-400 mb-3">No webhooks configured</p>
                                        <button
                                            @click="openCreateModal"
                                            class="inline-flex items-center px-4 py-2 bg-primary-400 text-white text-sm font-semibold rounded-lg hover:bg-primary-500 transition"
                                        >
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                            </svg>
                                            Create Your First Webhook
                                        </button>
                                    </td>
                                </tr>
                                <tr v-for="webhook in webhooks" :key="webhook.id" class="hover:bg-gray-50 dark:hover:bg-dark-bg/50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ webhook.name }}
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            Created by {{ webhook.creator?.name || 'Unknown' }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-gray-100 font-mono" :title="webhook.url">
                                            {{ truncateUrl(webhook.url) }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300">
                                            {{ webhook.events?.length || 0 }} events
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span
                                            :class="[
                                                'px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full',
                                                webhook.is_active
                                                    ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400'
                                                    : 'bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-400'
                                            ]"
                                        >
                                            {{ webhook.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-gray-100">
                                            {{ getDeliveryStats(webhook).total }} total
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex items-center justify-end gap-2">
                                            <Link
                                                :href="route('webhooks.show', webhook.id)"
                                                class="text-primary-400 hover:text-primary-300"
                                                title="View Details"
                                            >
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </svg>
                                            </Link>
                                            <button
                                                @click="openEditModal(webhook)"
                                                class="text-green-400 hover:text-green-300"
                                                title="Edit"
                                            >
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </button>
                                            <button
                                                @click="deleteWebhook(webhook)"
                                                class="text-red-400 hover:text-red-300"
                                                title="Delete"
                                            >
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <Modal :show="showCreateModal" @close="closeCreateModal" max-width="2xl">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        Create Webhook
                    </h3>
                    <button @click="closeCreateModal" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="submitCreate" class="space-y-6">
                    <!-- Name -->
                    <div>
                        <InputLabel for="create-name" value="Name" />
                        <input
                            id="create-name"
                            v-model="createForm.name"
                            type="text"
                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            placeholder="My Webhook"
                        />
                        <InputError class="mt-2" :message="createForm.errors.name" />
                    </div>

                    <!-- URL -->
                    <div>
                        <InputLabel for="create-url" value="Endpoint URL" />
                        <input
                            id="create-url"
                            v-model="createForm.url"
                            type="url"
                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 font-mono text-sm"
                            placeholder="https://example.com/webhook"
                        />
                        <InputError class="mt-2" :message="createForm.errors.url" />
                    </div>

                    <!-- Events -->
                    <div>
                        <InputLabel value="Events" />
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400 mb-3">
                            Select the events you want to receive notifications for.
                        </p>
                        <div class="space-y-4 max-h-64 overflow-y-auto border border-gray-200 dark:border-dark-border rounded-md p-4">
                            <div v-for="(events, group) in eventGroups" :key="group" class="space-y-2">
                                <div class="flex items-center">
                                    <input
                                        :id="`create-group-${group}`"
                                        type="checkbox"
                                        :checked="isGroupSelected(createForm, group)"
                                        :indeterminate="isGroupPartiallySelected(createForm, group)"
                                        @change="selectAllInGroup(createForm, group)"
                                        class="rounded border-gray-300 dark:border-dark-border text-primary-400 focus:ring-primary-400 bg-gray-50 dark:bg-dark-bg"
                                    />
                                    <label :for="`create-group-${group}`" class="ml-2 text-sm font-medium text-gray-900 dark:text-gray-100">
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
                                            class="mt-1 rounded border-gray-300 dark:border-dark-border text-primary-400 focus:ring-primary-400 bg-gray-50 dark:bg-dark-bg"
                                        />
                                        <label :for="`create-event-${event}`" class="ml-2">
                                            <span class="text-sm text-gray-900 dark:text-gray-100 font-mono">{{ event }}</span>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ description }}</p>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <InputError class="mt-2" :message="createForm.errors.events" />
                    </div>

                    <!-- Active Toggle -->
                    <div class="flex items-center">
                        <input
                            id="create-active"
                            v-model="createForm.is_active"
                            type="checkbox"
                            class="rounded border-gray-300 dark:border-dark-border text-primary-400 focus:ring-primary-400 bg-gray-50 dark:bg-dark-bg"
                        />
                        <label for="create-active" class="ml-2 text-sm text-gray-900 dark:text-gray-100">
                            Active
                        </label>
                        <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">
                            (Inactive webhooks will not receive any deliveries)
                        </span>
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-end gap-3 pt-4 border-t border-gray-200 dark:border-dark-border">
                        <button
                            type="button"
                            @click="closeCreateModal"
                            class="px-4 py-2 bg-gray-100 dark:bg-dark-bg text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-200 dark:hover:bg-dark-bg/50 font-medium text-sm"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="createForm.processing"
                            class="px-4 py-2 bg-primary-400 text-white rounded-md hover:bg-primary-500 font-medium text-sm disabled:opacity-50"
                        >
                            <span v-if="createForm.processing">Creating...</span>
                            <span v-else>Create Webhook</span>
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Edit Modal -->
        <Modal :show="showEditModal" @close="closeEditModal" max-width="2xl">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        Edit Webhook
                    </h3>
                    <button @click="closeEditModal" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="submitEdit" class="space-y-6">
                    <!-- Name -->
                    <div>
                        <InputLabel for="edit-name" value="Name" />
                        <input
                            id="edit-name"
                            v-model="editForm.name"
                            type="text"
                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            placeholder="My Webhook"
                        />
                        <InputError class="mt-2" :message="editForm.errors.name" />
                    </div>

                    <!-- URL -->
                    <div>
                        <InputLabel for="edit-url" value="Endpoint URL" />
                        <input
                            id="edit-url"
                            v-model="editForm.url"
                            type="url"
                            class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 font-mono text-sm"
                            placeholder="https://example.com/webhook"
                        />
                        <InputError class="mt-2" :message="editForm.errors.url" />
                    </div>

                    <!-- Events -->
                    <div>
                        <InputLabel value="Events" />
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400 mb-3">
                            Select the events you want to receive notifications for.
                        </p>
                        <div class="space-y-4 max-h-64 overflow-y-auto border border-gray-200 dark:border-dark-border rounded-md p-4">
                            <div v-for="(events, group) in eventGroups" :key="group" class="space-y-2">
                                <div class="flex items-center">
                                    <input
                                        :id="`edit-group-${group}`"
                                        type="checkbox"
                                        :checked="isGroupSelected(editForm, group)"
                                        :indeterminate="isGroupPartiallySelected(editForm, group)"
                                        @change="selectAllInGroup(editForm, group)"
                                        class="rounded border-gray-300 dark:border-dark-border text-primary-400 focus:ring-primary-400 bg-gray-50 dark:bg-dark-bg"
                                    />
                                    <label :for="`edit-group-${group}`" class="ml-2 text-sm font-medium text-gray-900 dark:text-gray-100">
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
                                            class="mt-1 rounded border-gray-300 dark:border-dark-border text-primary-400 focus:ring-primary-400 bg-gray-50 dark:bg-dark-bg"
                                        />
                                        <label :for="`edit-event-${event}`" class="ml-2">
                                            <span class="text-sm text-gray-900 dark:text-gray-100 font-mono">{{ event }}</span>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ description }}</p>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <InputError class="mt-2" :message="editForm.errors.events" />
                    </div>

                    <!-- Active Toggle -->
                    <div class="flex items-center">
                        <input
                            id="edit-active"
                            v-model="editForm.is_active"
                            type="checkbox"
                            class="rounded border-gray-300 dark:border-dark-border text-primary-400 focus:ring-primary-400 bg-gray-50 dark:bg-dark-bg"
                        />
                        <label for="edit-active" class="ml-2 text-sm text-gray-900 dark:text-gray-100">
                            Active
                        </label>
                        <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">
                            (Inactive webhooks will not receive any deliveries)
                        </span>
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-end gap-3 pt-4 border-t border-gray-200 dark:border-dark-border">
                        <button
                            type="button"
                            @click="closeEditModal"
                            class="px-4 py-2 bg-gray-100 dark:bg-dark-bg text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-200 dark:hover:bg-dark-bg/50 font-medium text-sm"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="editForm.processing"
                            class="px-4 py-2 bg-primary-400 text-white rounded-md hover:bg-primary-500 font-medium text-sm disabled:opacity-50"
                        >
                            <span v-if="editForm.processing">Saving...</span>
                            <span v-else>Save Changes</span>
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
