<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { ArrowLeft, Pencil, Trash2, Send, RefreshCw, RotateCcw, ChevronDown, Inbox, X } from '@lucide/vue';

import { useI18n } from 'vue-i18n';
const props = defineProps({
    webhook: Object,
    deliveries: Object,
    availableEvents: Array,
    eventGroups: Object,
});


const { t } = useI18n();
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

const statusVariant = (status) =>
    ({
        success: 'success',
        pending: 'warning',
        failed: 'danger',
    }[status] || 'neutral');

const responseCodeClass = (code) => {
    if (!code) return 'text-text-tertiary';
    if (code >= 200 && code < 300) return 'text-status-success';
    if (code >= 400 && code < 500) return 'text-status-warning';
    if (code >= 500) return 'text-status-danger';
    return 'text-text-secondary';
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

const thClass = 'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="`Webhook: ${webhook.name}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('settings.account.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('settings.account.index')" class="text-text-tertiary hover:text-text-primary">Settings</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('webhooks.index')" class="text-text-tertiary hover:text-text-primary">{{ t('settings.webhooks.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ webhook.name }}</span>
            </div>
        </template>

        <PageHeader :title="webhook.name" description="Webhook configuration and delivery activity.">
            <template #actions>
                <Badge :variant="webhook.is_active ? 'success' : 'neutral'" size="sm" dot>
                    {{ webhook.is_active ? 'Active' : 'Inactive' }}
                </Badge>
                <Button variant="secondary" size="sm" @click="sendTest">
                    <Send :size="14" />
                    Send Test
                </Button>
                <Button variant="default" size="sm" @click="openEditModal">
                    <Pencil :size="14" />
                    {{ t('common.edit') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('webhooks.index')">
                    <ArrowLeft :size="14" />
                    {{ t('common.back') }}
                </Button>
            </template>
        </PageHeader>

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Main Content -->
            <div class="space-y-4 lg:col-span-2">
                <!-- Webhook Details Card -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Webhook Details</h3></div>
                    <div class="p-5">
                        <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <dt class="text-xs text-text-tertiary">Endpoint URL</dt>
                                <dd class="mt-1 break-all font-mono text-sm text-text-primary">
                                    {{ webhook.url }}
                                </dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="flex items-center justify-between text-xs text-text-tertiary">
                                    <span>Secret</span>
                                    <div class="flex items-center gap-3">
                                        <button
                                            @click="showSecret = !showSecret"
                                            class="text-xs text-brand hover:underline"
                                        >
                                            {{ showSecret ? 'Hide' : 'Reveal' }}
                                        </button>
                                        <button
                                            @click="copySecret"
                                            class="text-xs text-brand hover:underline"
                                        >
                                            {{ copiedSecret ? 'Copied!' : 'Copy' }}
                                        </button>
                                    </div>
                                </dt>
                                <dd class="mt-1 break-all rounded-lg bg-slate-900 p-4 font-mono text-xs text-slate-300">
                                    {{ showSecret ? webhook.secret : maskedSecret }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('purchaseOrders.show.createdBy') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">
                                    {{ webhook.creator?.name || 'Unknown' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">Created At</dt>
                                <dd class="mt-1 text-sm text-text-primary">
                                    {{ formatDate(webhook.created_at) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Events Card -->
                <Card :padded="false">
                    <div class="px-5 pt-5">
                        <h3 class="text-sm font-semibold text-text-primary">
                            Subscribed Events ({{ webhook.events?.length || 0 }})
                        </h3>
                    </div>
                    <div class="p-5">
                        <div v-if="webhook.events?.length > 0" class="flex flex-wrap gap-2">
                            <Badge
                                v-for="event in webhook.events"
                                :key="event"
                                variant="brand"
                                size="md"
                                :title="getEventDescription(event)"
                            >
                                {{ event }}
                            </Badge>
                        </div>
                        <p v-else class="text-sm text-text-tertiary">
                            No events subscribed
                        </p>
                    </div>
                </Card>

                <!-- Delivery Logs -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Delivery Logs</h3></div>
                    <div class="p-5">
                        <div class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-border-subtle">
                                        <th :class="thClass">Event</th>
                                        <th :class="thClass">{{ t('common.status') }}</th>
                                        <th :class="thClass">Attempts</th>
                                        <th :class="thClass">Response</th>
                                        <th :class="thClass">Created</th>
                                        <th :class="[thClass, 'text-right']">{{ t('common.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="deliveries.data.length === 0">
                                        <td colspan="6" class="px-4 py-12 text-center">
                                            <div class="flex flex-col items-center gap-3">
                                                <Inbox :size="22" class="text-text-tertiary" />
                                                <p class="text-sm text-text-tertiary">No delivery logs yet</p>
                                                <p class="text-xs text-text-tertiary">
                                                    Logs will appear here when events are triggered
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                    <template v-for="delivery in deliveries.data" :key="delivery.id">
                                        <tr
                                            class="cursor-pointer border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                                            @click="toggleDeliveryExpand(delivery.id)"
                                        >
                                            <td class="px-4 py-3">
                                                <span class="font-mono text-sm text-text-primary">{{ delivery.event }}</span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <Badge :variant="statusVariant(delivery.status)" size="sm">
                                                    {{ delivery.status }}
                                                </Badge>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-text-primary">
                                                {{ delivery.attempts }}
                                            </td>
                                            <td class="px-4 py-3">
                                                <span :class="['font-mono text-sm', responseCodeClass(delivery.response_status)]">
                                                    {{ delivery.response_status || '-' }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-text-tertiary">
                                                {{ formatDate(delivery.created_at) }}
                                            </td>
                                            <td class="px-4 py-3 text-right">
                                                <div class="flex items-center justify-end gap-1">
                                                    <button
                                                        @click.stop="toggleDeliveryExpand(delivery.id)"
                                                        class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-text-primary"
                                                        :title="expandedDelivery === delivery.id ? 'Collapse' : 'Expand'"
                                                    >
                                                        <ChevronDown :size="16" :class="['transition-transform', expandedDelivery === delivery.id ? 'rotate-180' : '']" />
                                                    </button>
                                                    <button
                                                        v-if="delivery.status === 'failed'"
                                                        @click.stop="retryDelivery(delivery)"
                                                        class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand"
                                                        title="Retry"
                                                    >
                                                        <RotateCcw :size="16" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <!-- Expanded Details Row -->
                                        <tr v-if="expandedDelivery === delivery.id" class="border-b border-border-subtle last:border-b-0">
                                            <td colspan="6" class="bg-surface-canvas px-4 py-4">
                                                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                                    <div>
                                                        <h4 class="mb-2 text-sm font-medium text-text-secondary">Payload</h4>
                                                        <pre class="max-h-64 overflow-x-auto rounded-lg bg-slate-900 p-4 font-mono text-xs text-slate-300">{{ formatJson(delivery.payload) }}</pre>
                                                    </div>
                                                    <div>
                                                        <h4 class="mb-2 text-sm font-medium text-text-secondary">Response</h4>
                                                        <pre class="max-h-64 overflow-x-auto rounded-lg bg-slate-900 p-4 font-mono text-xs text-slate-300">{{ delivery.response_body || 'No response body' }}</pre>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div v-if="deliveries.data.length > 0" class="mt-4 flex items-center justify-between">
                            <p class="text-sm text-text-secondary">
                                Showing
                                <span class="font-medium text-text-primary">{{ deliveries.from }}</span>
                                to
                                <span class="font-medium text-text-primary">{{ deliveries.to }}</span>
                                of
                                <span class="font-medium text-text-primary">{{ deliveries.total }}</span>
                                results
                            </p>
                            <nav class="inline-flex items-center gap-1">
                                <template v-for="link in deliveries.links" :key="link.label">
                                    <Link
                                        v-if="link.url"
                                        :href="link.url"
                                        :class="[
                                            'inline-flex items-center rounded-md border px-3 py-1.5 text-sm font-medium transition-colors',
                                            link.active
                                                ? 'border-brand bg-brand-soft text-brand'
                                                : 'border-border-subtle bg-surface-raised text-text-secondary hover:bg-surface-overlay'
                                        ]"
                                        v-html="link.label"
                                    />
                                    <span
                                        v-else
                                        class="inline-flex cursor-not-allowed items-center rounded-md border border-border-subtle bg-surface-canvas px-3 py-1.5 text-sm font-medium text-text-tertiary opacity-50"
                                        v-html="link.label"
                                    />
                                </template>
                            </nav>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Sidebar -->
            <div class="space-y-4">
                <!-- Quick Actions Card -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('common.actions') }}</h3></div>
                    <div class="space-y-2 p-5">
                        <Button variant="secondary" class="w-full" @click="sendTest">
                            <Send :size="16" />
                            Send Test Webhook
                        </Button>
                        <Button variant="default" class="w-full" @click="openEditModal">
                            <Pencil :size="16" />
                            Edit Webhook
                        </Button>
                        <Button variant="secondary" class="w-full" @click="regenerateSecret">
                            <RefreshCw :size="16" />
                            Regenerate Secret
                        </Button>
                    </div>
                </Card>

                <!-- Integration Guide Card -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Integration Guide</h3></div>
                    <div class="space-y-4 p-5">
                        <div>
                            <h4 class="mb-1 text-sm font-medium text-text-secondary">Signature Header</h4>
                            <p class="text-xs text-text-tertiary">
                                All webhook payloads include a signature in the <code class="rounded bg-surface-overlay px-1 font-mono text-text-secondary">X-Webhook-Signature</code> header.
                            </p>
                        </div>
                        <div>
                            <h4 class="mb-1 text-sm font-medium text-text-secondary">Verification</h4>
                            <p class="mb-2 text-xs text-text-tertiary">
                                Verify the signature by computing HMAC-SHA256 of the raw request body using your secret.
                            </p>
                            <pre class="overflow-x-auto rounded-lg bg-slate-900 p-4 font-mono text-xs text-slate-300">hash_hmac('sha256', $payload, $secret)</pre>
                        </div>
                        <div>
                            <h4 class="mb-1 text-sm font-medium text-text-secondary">Response</h4>
                            <p class="text-xs text-text-tertiary">
                                Return a 2xx status code to acknowledge receipt. Failed deliveries will be retried up to 5 times.
                            </p>
                        </div>
                    </div>
                </Card>

                <!-- Danger Zone Card -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Danger Zone</h3></div>
                    <div class="p-5">
                        <Button variant="danger" class="w-full" @click="deleteWebhook">
                            <Trash2 :size="16" />
                            Delete Webhook
                        </Button>
                        <p class="mt-2 text-xs text-text-tertiary">
                            Permanently delete this webhook and all of its delivery logs. This action cannot be undone.
                        </p>
                    </div>
                </Card>
            </div>
        </div>

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
                                            :id="`show-edit-group-${group}`"
                                            type="checkbox"
                                            :checked="isGroupSelected(editForm, group)"
                                            :indeterminate="isGroupPartiallySelected(editForm, group)"
                                            @change="selectAllInGroup(editForm, group)"
                                            class="rounded border-border-strong bg-surface-canvas text-brand focus:ring-brand"
                                        />
                                        <label :for="`show-edit-group-${group}`" class="ml-2 text-sm font-medium text-text-primary">
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
                                                class="mt-1 rounded border-border-strong bg-surface-canvas text-brand focus:ring-brand"
                                            />
                                            <label :for="`show-edit-event-${event}`" class="ml-2">
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
                                id="show-edit-active"
                                v-model="editForm.is_active"
                                type="checkbox"
                                class="rounded border-border-strong bg-surface-canvas text-brand focus:ring-brand"
                            />
                            <label for="show-edit-active" class="ml-2 text-sm text-text-primary">
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

