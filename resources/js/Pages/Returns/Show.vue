<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Check, PackageCheck, CheckCircle2, X, PackageOpen } from '@lucide/vue';

const { t } = useI18n();
const { hasPermission } = usePermissions();

const props = defineProps({
    returnOrder: Object,
});

const processing = ref(false);
const showRejectModal = ref(false);
const rejectNotes = ref('');

const statusVariant = (status) =>
    ({
        pending: 'warning',
        approved: 'info',
        received: 'brand',
        completed: 'success',
        rejected: 'danger',
    }[status] || 'neutral');

const typeVariant = (type) => (type === 'exchange' ? 'info' : 'warning');

const conditionVariant = (condition) =>
    ({
        new: 'success',
        used: 'warning',
        damaged: 'danger',
    }[condition] || 'neutral');

const getConditionLabel = (condition) => {
    const labels = { new: 'New (Unopened)', used: 'Used (Opened)', damaged: 'Damaged' };
    return labels[condition] || condition;
};

const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const performAction = (action) => {
    processing.value = true;
    router.post(route(`returns.${action}`, props.returnOrder.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};

const submitReject = () => {
    processing.value = true;
    router.post(route('returns.reject', props.returnOrder.id), {
        notes: rejectNotes.value,
    }, {
        onFinish: () => {
            processing.value = false;
            showRejectModal.value = false;
        },
    });
};

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="`Return ${returnOrder.return_number}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('returns.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('returns.index')" class="text-text-tertiary hover:text-text-primary">Returns</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">#{{ returnOrder.return_number }}</span>
            </div>
        </template>

        <PageHeader
            :title="`Return #${returnOrder.return_number}`"
            :description="`Created on ${formatDate(returnOrder.created_at)}`"
        >
            <template #actions>
                <Badge :variant="typeVariant(returnOrder.type)" size="sm" class="capitalize">{{ returnOrder.type }}</Badge>
                <Badge :variant="statusVariant(returnOrder.status)" size="sm" dot class="capitalize">{{ returnOrder.status }}</Badge>

                <Button
                    v-if="returnOrder.status === 'pending'"
                    variant="default"
                    size="sm"
                    :disabled="processing"
                    @click="performAction('approve')"
                >
                    <Check :size="14" />
                    Approve Return
                </Button>
                <Button
                    v-if="returnOrder.status === 'approved'"
                    variant="default"
                    size="sm"
                    :disabled="processing"
                    @click="performAction('receive')"
                >
                    <PackageCheck :size="14" />
                    Mark as Received
                </Button>
                <Button
                    v-if="returnOrder.status === 'received'"
                    variant="default"
                    size="sm"
                    :disabled="processing"
                    @click="performAction('complete')"
                >
                    <CheckCircle2 :size="14" />
                    Complete Return
                </Button>
                <Button
                    v-if="returnOrder.status === 'pending'"
                    variant="danger"
                    size="sm"
                    :disabled="processing"
                    @click="showRejectModal = true"
                >
                    <X :size="14" />
                    Reject Return
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('returns.index')">
                    <ArrowLeft :size="14" />
                    Back to Returns
                </Button>
            </template>
        </PageHeader>

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Left Column: Items & Details -->
            <div class="space-y-4 lg:col-span-2">
                <!-- Return Items -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Return Items</h3></div>
                    <div class="p-5">
                        <div v-if="returnOrder.items && returnOrder.items.length > 0" class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                            <table class="min-w-full">
                                <thead>
                                    <tr class="border-b border-border-subtle">
                                        <th :class="thClass">Product</th>
                                        <th :class="thClass">SKU</th>
                                        <th :class="[thClass, 'text-center']">Qty</th>
                                        <th :class="[thClass, 'text-center']">Condition</th>
                                        <th :class="[thClass, 'text-center']">Restock</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="item in returnOrder.items"
                                        :key="item.id"
                                        class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                                    >
                                        <td class="px-4 py-3 text-sm font-medium text-text-primary">
                                            {{ item.product?.name || item.order_item?.product_name || 'Unknown Product' }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-text-tertiary">
                                            {{ item.product?.sku || item.order_item?.sku || '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-center text-sm font-medium tabular-nums text-text-primary">
                                            {{ item.quantity }}
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <Badge :variant="conditionVariant(item.condition)" size="sm">
                                                {{ getConditionLabel(item.condition) }}
                                            </Badge>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <Badge v-if="item.restock" variant="success" size="sm">Yes</Badge>
                                            <Badge v-else variant="neutral" size="sm">No</Badge>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
                            <PackageOpen :size="22" class="text-text-tertiary" />
                            <p class="text-sm text-text-tertiary">No items on this return.</p>
                        </div>
                    </div>
                </Card>

                <!-- Reason & Notes -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Details</h3></div>
                    <div class="p-5">
                        <dl class="space-y-3">
                            <div>
                                <dt class="text-xs text-text-tertiary">Reason</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ returnOrder.reason }}</dd>
                            </div>
                            <div v-if="returnOrder.notes">
                                <dt class="text-xs text-text-tertiary">Notes</dt>
                                <dd class="mt-1 whitespace-pre-line text-sm text-text-secondary">{{ returnOrder.notes }}</dd>
                            </div>
                            <div v-if="returnOrder.order">
                                <dt class="text-xs text-text-tertiary">For Order</dt>
                                <dd class="mt-1 text-sm">
                                    <Link :href="route('orders.show', returnOrder.order_id)" class="text-brand hover:underline">
                                        #{{ returnOrder.order.order_number }}
                                    </Link>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </Card>
            </div>

            <!-- Right Column: Summary & Actions -->
            <div class="space-y-4">
                <!-- Summary -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Summary</h3></div>
                    <div class="p-5">
                        <dl class="space-y-3">
                            <div class="flex justify-between text-sm">
                                <dt class="text-text-secondary">Return Number</dt>
                                <dd class="font-medium text-text-primary">{{ returnOrder.return_number }}</dd>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <dt class="text-text-secondary">Type</dt>
                                <dd>
                                    <Badge :variant="typeVariant(returnOrder.type)" size="sm" class="capitalize">{{ returnOrder.type }}</Badge>
                                </dd>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <dt class="text-text-secondary">Status</dt>
                                <dd>
                                    <Badge :variant="statusVariant(returnOrder.status)" size="sm" dot class="capitalize">{{ returnOrder.status }}</Badge>
                                </dd>
                            </div>
                            <div class="flex justify-between text-sm">
                                <dt class="text-text-secondary">Created</dt>
                                <dd class="font-medium text-text-primary">{{ formatDate(returnOrder.created_at) }}</dd>
                            </div>
                            <div v-if="returnOrder.completed_at" class="flex justify-between text-sm">
                                <dt class="text-text-secondary">Completed</dt>
                                <dd class="font-medium text-text-primary">{{ formatDate(returnOrder.completed_at) }}</dd>
                            </div>
                            <div v-if="returnOrder.processor" class="flex justify-between text-sm">
                                <dt class="text-text-secondary">Processed By</dt>
                                <dd class="font-medium text-text-primary">{{ returnOrder.processor.name }}</dd>
                            </div>
                            <div class="border-t border-border-subtle pt-3">
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm font-semibold text-text-primary">Refund Amount</dt>
                                    <dd class="text-xl font-bold tabular-nums text-brand">${{ parseFloat(returnOrder.refund_amount || 0).toFixed(2) }}</dd>
                                </div>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Actions -->
                <Card v-if="returnOrder.status !== 'completed' && returnOrder.status !== 'rejected'" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Actions</h3></div>
                    <div class="p-5">
                        <div class="space-y-2">
                            <Button
                                v-if="returnOrder.status === 'pending'"
                                variant="default"
                                class="w-full"
                                :disabled="processing"
                                @click="performAction('approve')"
                            >
                                Approve Return
                            </Button>
                            <Button
                                v-if="returnOrder.status === 'approved'"
                                variant="default"
                                class="w-full"
                                :disabled="processing"
                                @click="performAction('receive')"
                            >
                                Mark as Received
                            </Button>
                            <Button
                                v-if="returnOrder.status === 'received'"
                                variant="default"
                                class="w-full"
                                :disabled="processing"
                                @click="performAction('complete')"
                            >
                                Complete Return
                            </Button>
                        </div>

                        <p class="mt-3 text-xs text-text-tertiary">
                            <template v-if="returnOrder.status === 'pending'">
                                Approve to proceed or reject the return.
                            </template>
                            <template v-else-if="returnOrder.status === 'approved'">
                                Mark as received when items arrive. Items marked for restock will be added back to inventory.
                            </template>
                            <template v-else-if="returnOrder.status === 'received'">
                                Complete the return to finalize the process.
                            </template>
                        </p>
                    </div>
                </Card>

                <!-- Danger Zone -->
                <Card v-if="returnOrder.status === 'pending'" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Danger Zone</h3></div>
                    <div class="p-5">
                        <Button variant="danger" class="w-full" :disabled="processing" @click="showRejectModal = true">
                            Reject Return
                        </Button>
                        <p class="mt-2 text-xs text-text-tertiary">
                            Rejecting closes this return. This cannot be undone.
                        </p>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Reject Modal -->
        <Teleport to="body">
            <div v-if="showRejectModal" class="fixed inset-0 z-50 flex items-center justify-center" @click="showRejectModal = false">
                <div class="fixed inset-0 bg-black/50"></div>

                <div class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg" @click.stop>
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">Reject Return</h3>
                        <button
                            @click="showRejectModal = false"
                            class="text-text-tertiary transition-colors hover:text-text-primary"
                        >
                            <X :size="18" />
                        </button>
                    </div>

                    <div class="mb-6">
                        <label class="mb-1 block text-sm font-medium text-text-secondary">Reason for rejection</label>
                        <textarea
                            v-model="rejectNotes"
                            rows="3"
                            class="w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            placeholder="Why is this return being rejected?"
                        ></textarea>
                    </div>

                    <div class="flex justify-end gap-3">
                        <Button variant="secondary" :disabled="processing" @click="showRejectModal = false">
                            Cancel
                        </Button>
                        <Button variant="danger" :loading="processing" :disabled="processing" @click="submitReject">
                            <span v-if="processing">Rejecting...</span>
                            <span v-else>Reject Return</span>
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

