<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    webhook: Object,
    deliveries: Object,
    availableEvents: Array,
    eventGroups: Object,
});

// State
const showSecret = ref(false);
const copiedSecret = ref(false);
const showEditModal = ref(false);
const expandedDelivery = ref(null);

// Edit form
const editForm = useForm({
    name: props.webhook.name,
    url: props.webhook.url,
    events: [...props.webhook.events],
    is_active: props.webhook.is_active,
});

const openEditModal = () => {
    editForm.name = props.webhook.name;
    editForm.url = props.webhook.url;
    editForm.events = [...props.webhook.events];
    editForm.is_active = props.webhook.is_active;
    showEditModal.value = true;
};

const closeEditModal = () => {
    showEditModal.value = false;
};

const submitEdit = () => {
    editForm.put(route('webhooks.update', props.webhook.id), {
        onSuccess: () => {
            closeEditModal();
        },
    });
};

const deleteWebhook = () => {
    if (confirm(`Are you sure you want to delete "${props.webhook.name}"? This action cannot be undone.`)) {
        router.delete(route('webhooks.destroy', props.webhook.id));
    }
};

const regenerateSecret = () => {
    if (confirm('Are you sure you want to regenerate the secret? You will need to update the secret in your receiving application.')) {
        router.post(route('webhooks.regenerate-secret', props.webhook.id));
    }
};

const sendTest = () => {
    router.post(route('webhooks.test', props.webhook.id));
};

const retryDelivery = (delivery) => {
    router.post(route('webhook-deliveries.retry', delivery.id));
};

const copySecret = async () => {
    try {
        await navigator.clipboard.writeText(props.webhook.secret);
        copiedSecret.value = true;
        setTimeout(() => {
            copiedSecret.value = false;
        }, 2000);
    } catch (err) {
        console.error('Failed to copy secret:', err);
    }
};

const toggleDeliveryExpand = (deliveryId) => {
    if (expandedDelivery.value === deliveryId) {
        expandedDelivery.value = null;
    } else {
        expandedDelivery.value = deliveryId;
    }
};

const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleString();
};

const formatJson = (data) => {
    if (!data) return '-';
    try {
        if (typeof data === 'string') {
            data = JSON.parse(data);
        }
        return JSON.stringify(data, null, 2);
    } catch {
        return data;
    }
};

const getStatusColor = (status) => {
    switch (status) {
        case 'success':
            return 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400';
        case 'pending':
            return 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-400';
        case 'failed':
            return 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-400';
        default:
            return 'bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-400';
    }
};

const getResponseCodeColor = (code) => {
    if (!code) return 'text-gray-500 dark:text-gray-400';
    if (code >= 200 && code < 300) return 'text-green-600 dark:text-green-400';
    if (code >= 400 && code < 500) return 'text-yellow-600 dark:text-yellow-400';
    if (code >= 500) return 'text-red-600 dark:text-red-400';
    return 'text-gray-600 dark:text-gray-400';
};

const maskedSecret = computed(() => {
    if (!props.webhook.secret) return '';
    return props.webhook.secret.substring(0, 8) + '...' + props.webhook.secret.substring(props.webhook.secret.length - 8);
});

const getEventDescription = (event) => {
    for (const group in props.eventGroups) {
        if (props.eventGroups[group][event]) {
            return props.eventGroups[group][event];
        }
    }
    return null;
};

const getEventGroup = (event) => {
    for (const group in props.eventGroups) {
        if (props.eventGroups[group][event]) {
            return group;
        }
    }
    return 'Other';
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
        groupEvents.forEach(event => {
            const index = form.events.indexOf(event);
            if (index > -1) {
                form.events.splice(index, 1);
            }
        });
    } else {
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
</script>

<template>
    <Head :title="`Webhook: ${webhook.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link
                        :href="route('webhooks.index')"
                        class="text-gray-400 hover:text-gray-300"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                    </Link>
                    <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                        {{ webhook.name }}
                    </h2>
                    <span
                        :class="[
                            'px-2 py-1 text-xs font-semibold rounded-full',
                            webhook.is_active
                                ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400'
                                : 'bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-400'
                        ]"
                    >
                        {{ webhook.is_active ? 'Active' : 'Inactive' }}
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        @click="sendTest"
                        class="inline-flex items-center px-3 py-2 bg-blue-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-600 transition"
                    >
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                        </svg>
                        Send Test
                    </button>
                    <button
                        @click="openEditModal"
                        class="inline-flex items-center px-3 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 transition"
                    >
                        Edit
                    </button>
                    <Link
                        :href="route('webhooks.index')"
                        class="inline-flex items-center px-3 py-2 bg-gray-100 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-200 dark:hover:bg-dark-bg/50 transition"
                    >
                        Back
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Main Content -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Webhook Details Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Webhook Details</h3>
                            </div>
                            <div class="p-6">
                                <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                                    <div class="sm:col-span-2">
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Endpoint URL</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 font-mono break-all">
                                            {{ webhook.url }}
                                        </dd>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 flex items-center justify-between">
                                            <span>Secret</span>
                                            <div class="flex items-center gap-2">
                                                <button
                                                    @click="showSecret = !showSecret"
                                                    class="text-xs text-primary-400 hover:text-primary-300"
                                                >
                                                    {{ showSecret ? 'Hide' : 'Reveal' }}
                                                </button>
                                                <button
                                                    @click="copySecret"
                                                    class="text-xs text-primary-400 hover:text-primary-300"
                                                >
                                                    {{ copiedSecret ? 'Copied!' : 'Copy' }}
                                                </button>
                                            </div>
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 font-mono bg-gray-50 dark:bg-dark-bg rounded px-3 py-2 break-all">
                                            {{ showSecret ? webhook.secret : maskedSecret }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Created By</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                            {{ webhook.creator?.name || 'Unknown' }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Created At</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                            {{ formatDate(webhook.created_at) }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        <!-- Events Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                                    Subscribed Events ({{ webhook.events?.length || 0 }})
                                </h3>
                            </div>
                            <div class="p-6">
                                <div v-if="webhook.events?.length > 0" class="flex flex-wrap gap-2">
                                    <span
                                        v-for="event in webhook.events"
                                        :key="event"
                                        class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300"
                                        :title="getEventDescription(event)"
                                    >
                                        {{ event }}
                                    </span>
                                </div>
                                <p v-else class="text-sm text-gray-500 dark:text-gray-400">
                                    No events subscribed
                                </p>
                            </div>
                        </div>

                        <!-- Delivery Logs -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                                    Delivery Logs
                                </h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                                    <thead class="bg-gray-50 dark:bg-dark-bg/50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                Event
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                Status
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                Attempts
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                Response
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                Created
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-dark-card divide-y divide-gray-200 dark:divide-dark-border">
                                        <tr v-if="deliveries.data.length === 0">
                                            <td colspan="6" class="px-6 py-12 text-center">
                                                <svg class="w-12 h-12 text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                                </svg>
                                                <p class="text-gray-500 dark:text-gray-400">No delivery logs yet</p>
                                                <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">
                                                    Logs will appear here when events are triggered
                                                </p>
                                            </td>
                                        </tr>
                                        <template v-for="delivery in deliveries.data" :key="delivery.id">
                                            <tr
                                                class="hover:bg-gray-50 dark:hover:bg-dark-bg/50 cursor-pointer"
                                                @click="toggleDeliveryExpand(delivery.id)"
                                            >
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="text-sm font-mono text-gray-900 dark:text-gray-100">
                                                        {{ delivery.event }}
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span :class="['px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full', getStatusColor(delivery.status)]">
                                                        {{ delivery.status }}
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                                    {{ delivery.attempts }}
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span :class="['text-sm font-mono', getResponseCodeColor(delivery.response_status)]">
                                                        {{ delivery.response_status || '-' }}
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                    {{ formatDate(delivery.created_at) }}
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    <div class="flex items-center justify-end gap-2">
                                                        <button
                                                            @click.stop="toggleDeliveryExpand(delivery.id)"
                                                            class="text-gray-400 hover:text-gray-300"
                                                            :title="expandedDelivery === delivery.id ? 'Collapse' : 'Expand'"
                                                        >
                                                            <svg
                                                                :class="['w-5 h-5 transition-transform', expandedDelivery === delivery.id ? 'rotate-180' : '']"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                viewBox="0 0 24 24"
                                                            >
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                                            </svg>
                                                        </button>
                                                        <button
                                                            v-if="delivery.status === 'failed'"
                                                            @click.stop="retryDelivery(delivery)"
                                                            class="text-primary-400 hover:text-primary-300"
                                                            title="Retry"
                                                        >
                                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <!-- Expanded Details Row -->
                                            <tr v-if="expandedDelivery === delivery.id">
                                                <td colspan="6" class="px-6 py-4 bg-gray-50 dark:bg-dark-bg/50">
                                                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                                        <div>
                                                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Payload</h4>
                                                            <pre class="text-xs font-mono bg-gray-900 text-green-400 rounded-md p-4 overflow-x-auto max-h-64">{{ formatJson(delivery.payload) }}</pre>
                                                        </div>
                                                        <div>
                                                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Response</h4>
                                                            <pre class="text-xs font-mono bg-gray-900 text-gray-300 rounded-md p-4 overflow-x-auto max-h-64">{{ delivery.response_body || 'No response body' }}</pre>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <div v-if="deliveries.data.length > 0" class="bg-white dark:bg-dark-card px-4 py-3 border-t border-gray-200 dark:border-dark-border sm:px-6">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1 flex justify-between sm:hidden">
                                        <Link
                                            v-if="deliveries.prev_page_url"
                                            :href="deliveries.prev_page_url"
                                            class="relative inline-flex items-center px-4 py-2 border border-gray-200 dark:border-dark-border text-sm font-semibold rounded-md text-gray-600 dark:text-gray-300 bg-white dark:bg-dark-card hover:bg-gray-50 dark:hover:bg-dark-bg/50 transition"
                                        >
                                            Previous
                                        </Link>
                                        <Link
                                            v-if="deliveries.next_page_url"
                                            :href="deliveries.next_page_url"
                                            class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-200 dark:border-dark-border text-sm font-semibold rounded-md text-gray-600 dark:text-gray-300 bg-white dark:bg-dark-card hover:bg-gray-50 dark:hover:bg-dark-bg/50 transition"
                                        >
                                            Next
                                        </Link>
                                    </div>
                                    <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                        <div>
                                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                                Showing
                                                <span class="font-medium">{{ deliveries.from }}</span>
                                                to
                                                <span class="font-medium">{{ deliveries.to }}</span>
                                                of
                                                <span class="font-medium">{{ deliveries.total }}</span>
                                                results
                                            </p>
                                        </div>
                                        <div>
                                            <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                                <template v-for="link in deliveries.links" :key="link.label">
                                                    <Link
                                                        v-if="link.url"
                                                        :href="link.url"
                                                        :class="[
                                                            'relative inline-flex items-center px-4 py-2 border text-sm font-medium',
                                                            link.active
                                                                ? 'z-10 bg-primary-100 dark:bg-primary-900/30 border-primary-400 text-primary-600 dark:text-primary-400'
                                                                : 'bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-dark-bg/50'
                                                        ]"
                                                        v-html="link.label"
                                                    />
                                                    <span
                                                        v-else
                                                        :class="[
                                                            'relative inline-flex items-center px-4 py-2 border text-sm font-medium',
                                                            'bg-gray-100 dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-400 dark:text-gray-500 opacity-50 cursor-not-allowed'
                                                        ]"
                                                        v-html="link.label"
                                                    />
                                                </template>
                                            </nav>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6">
                        <!-- Quick Actions Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Actions</h3>
                            </div>
                            <div class="p-6 space-y-3">
                                <button
                                    @click="sendTest"
                                    class="w-full inline-flex justify-center items-center px-4 py-2 bg-blue-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-600 transition"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                    </svg>
                                    Send Test Webhook
                                </button>
                                <button
                                    @click="openEditModal"
                                    class="w-full inline-flex justify-center items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 transition"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                    Edit Webhook
                                </button>
                                <button
                                    @click="regenerateSecret"
                                    class="w-full inline-flex justify-center items-center px-4 py-2 bg-yellow-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-yellow-600 transition"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    Regenerate Secret
                                </button>
                                <button
                                    @click="deleteWebhook"
                                    class="w-full inline-flex justify-center items-center px-4 py-2 bg-red-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-600 transition"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                    Delete Webhook
                                </button>
                            </div>
                        </div>

                        <!-- Integration Guide Card -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Integration Guide</h3>
                            </div>
                            <div class="p-6 space-y-4">
                                <div>
                                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Signature Header</h4>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                                        All webhook payloads include a signature in the <code class="bg-gray-100 dark:bg-dark-bg px-1 rounded">X-Webhook-Signature</code> header.
                                    </p>
                                </div>
                                <div>
                                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Verification</h4>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                                        Verify the signature by computing HMAC-SHA256 of the raw request body using your secret.
                                    </p>
                                    <pre class="text-xs font-mono bg-gray-900 text-green-400 rounded-md p-3 overflow-x-auto">hash_hmac('sha256', $payload, $secret)</pre>
                                </div>
                                <div>
                                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Response</h4>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        Return a 2xx status code to acknowledge receipt. Failed deliveries will be retried up to 5 times.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

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
                                        :id="`show-edit-group-${group}`"
                                        type="checkbox"
                                        :checked="isGroupSelected(editForm, group)"
                                        :indeterminate="isGroupPartiallySelected(editForm, group)"
                                        @change="selectAllInGroup(editForm, group)"
                                        class="rounded border-gray-300 dark:border-dark-border text-primary-400 focus:ring-primary-400 bg-gray-50 dark:bg-dark-bg"
                                    />
                                    <label :for="`show-edit-group-${group}`" class="ml-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ group }}
                                    </label>
                                </div>
                                <div class="ml-6 space-y-1">
                                    <div v-for="(description, event) in events" :key="event" class="flex items-start">
                                        <input
                                            :id="`show-edit-event-${event}`"
                                            type="checkbox"
                                            :checked="isEventSelected(editForm, event)"
                                            @change="toggleEvent(editForm, event)"
                                            class="mt-1 rounded border-gray-300 dark:border-dark-border text-primary-400 focus:ring-primary-400 bg-gray-50 dark:bg-dark-bg"
                                        />
                                        <label :for="`show-edit-event-${event}`" class="ml-2">
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
                            id="show-edit-active"
                            v-model="editForm.is_active"
                            type="checkbox"
                            class="rounded border-gray-300 dark:border-dark-border text-primary-400 focus:ring-primary-400 bg-gray-50 dark:bg-dark-bg"
                        />
                        <label for="show-edit-active" class="ml-2 text-sm text-gray-900 dark:text-gray-100">
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
