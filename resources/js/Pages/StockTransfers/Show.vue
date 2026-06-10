<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, CheckCircle2, XCircle } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    transfer: Object,
});

const processing = ref(false);

const statusVariant = (status) =>
    ({
        pending: 'warning',
        in_transit: 'info',
        completed: 'success',
        cancelled: 'danger',
    }[status] || 'neutral');

const getStatusLabel = (status) => {
    const labels = {
        'pending': 'Pending',
        'in_transit': 'In Transit',
        'completed': 'Completed',
        'cancelled': 'Cancelled',
    };
    return labels[status] || status;
};

const formatDate = (dateStr) => {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const canComplete = ['pending', 'in_transit'].includes(props.transfer.status);
const canCancel = ['pending', 'in_transit'].includes(props.transfer.status);

const completeTransfer = () => {
    if (!confirm('Are you sure you want to complete this transfer? Stock levels will be adjusted.')) return;
    processing.value = true;
    router.post(route('stock-transfers.complete', props.transfer.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};

const cancelTransfer = () => {
    if (!confirm('Are you sure you want to cancel this transfer?')) return;
    processing.value = true;
    router.post(route('stock-transfers.cancel', props.transfer.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="`Transfer ${transfer.transfer_number}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('stock-transfers.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('stock-transfers.index')" class="text-text-tertiary hover:text-text-primary">Stock Transfers</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ transfer.transfer_number }}</span>
            </div>
        </template>

        <PageHeader :title="transfer.transfer_number" description="Stock transfer details">
            <template #actions>
                <Badge :variant="statusVariant(transfer.status)" size="md" dot>{{ getStatusLabel(transfer.status) }}</Badge>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-transfers.index')">
                    <ArrowLeft :size="14" />
                    Back to List
                </Button>
            </template>
        </PageHeader>

        <div class="mt-6 space-y-4">
            <!-- Flash Messages -->
            <div v-if="$page.props.flash?.success" class="rounded-lg border border-status-success/20 bg-status-success-soft p-4">
                <p class="text-sm text-status-success">{{ $page.props.flash.success }}</p>
            </div>
            <div v-if="$page.props.flash?.error" class="rounded-lg border border-status-danger/20 bg-status-danger-soft p-4">
                <p class="text-sm text-status-danger">{{ $page.props.flash.error }}</p>
            </div>

            <!-- Transfer Info -->
            <Card :padded="false">
                <div class="flex items-center justify-between px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">Transfer Details</h3>
                    <Badge :variant="statusVariant(transfer.status)" size="md" dot>{{ getStatusLabel(transfer.status) }}</Badge>
                </div>
                <div class="p-5">
                    <dl class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <dt class="mb-1 text-xs text-text-tertiary">Transfer Number</dt>
                            <dd class="text-sm font-medium text-text-primary">{{ transfer.transfer_number }}</dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-xs text-text-tertiary">Transferred By</dt>
                            <dd class="text-sm font-medium text-text-primary">{{ transfer.transferred_by_user?.name || '-' }}</dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-xs text-text-tertiary">From Location</dt>
                            <dd class="text-sm font-medium text-text-primary">
                                {{ transfer.from_location?.name }}
                                <span v-if="transfer.from_location?.code" class="text-text-tertiary">({{ transfer.from_location.code }})</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-xs text-text-tertiary">To Location</dt>
                            <dd class="text-sm font-medium text-text-primary">
                                {{ transfer.to_location?.name }}
                                <span v-if="transfer.to_location?.code" class="text-text-tertiary">({{ transfer.to_location.code }})</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-xs text-text-tertiary">Created</dt>
                            <dd class="text-sm font-medium text-text-primary">{{ formatDate(transfer.created_at) }}</dd>
                        </div>
                        <div v-if="transfer.completed_at">
                            <dt class="mb-1 text-xs text-text-tertiary">Completed</dt>
                            <dd class="text-sm font-medium text-text-primary">{{ formatDate(transfer.completed_at) }}</dd>
                        </div>
                    </dl>

                    <div v-if="transfer.notes" class="mt-6 border-t border-border-subtle pt-6">
                        <p class="mb-1 text-xs text-text-tertiary">Notes</p>
                        <p class="text-sm text-text-primary">{{ transfer.notes }}</p>
                    </div>
                </div>
            </Card>

            <!-- Transfer Items -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Transfer Items</h3></div>
                <div class="p-5">
                    <div class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                        <table class="min-w-full">
                            <thead>
                                <tr class="border-b border-border-subtle">
                                    <th :class="thClass">Product</th>
                                    <th :class="thClass">SKU</th>
                                    <th :class="[thClass, 'text-right']">Quantity</th>
                                    <th :class="thClass">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in transfer.items" :key="item.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                                    <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-text-primary">
                                        {{ item.product?.name || '-' }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-text-tertiary">
                                        {{ item.product?.sku || '-' }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium tabular-nums text-text-primary">
                                        {{ item.quantity }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-text-tertiary">
                                        {{ item.notes || '-' }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-border-subtle">
                                    <td colspan="2" class="px-4 py-3 text-sm font-medium text-text-primary">Total</td>
                                    <td class="px-4 py-3 text-right text-sm font-bold tabular-nums text-text-primary">
                                        {{ transfer.items?.reduce((sum, item) => sum + item.quantity, 0) || 0 }}
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </Card>

            <!-- Actions -->
            <Card v-if="canComplete || canCancel" :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Actions</h3></div>
                <div class="p-5">
                    <div class="flex flex-wrap gap-3">
                        <Button
                            v-if="canComplete"
                            variant="default"
                            :loading="processing"
                            :disabled="processing"
                            @click="completeTransfer"
                        >
                            <CheckCircle2 :size="16" />
                            {{ processing ? 'Processing...' : 'Complete Transfer' }}
                        </Button>
                        <Button
                            v-if="canCancel"
                            variant="danger"
                            :loading="processing"
                            :disabled="processing"
                            @click="cancelTransfer"
                        >
                            <XCircle :size="16" />
                            {{ processing ? 'Processing...' : 'Cancel Transfer' }}
                        </Button>
                    </div>
                    <p v-if="canComplete" class="mt-3 text-xs text-text-tertiary">
                        Completing the transfer will deduct stock from the source location and add it to the destination location.
                    </p>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>

