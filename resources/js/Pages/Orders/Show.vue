<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Pencil, Download, Eye, Undo2, Trash2, X, AlertTriangle, PackageOpen } from 'lucide-vue-next';

const { t } = useI18n();

const { hasPermission } = usePermissions();

const props = defineProps({
    order: Object,
    canApprove: Boolean,
    pluginComponents: Object,
});

const showDeleteModal = ref(false);
const deleting = ref(false);

// Approval functionality
const showApprovalModal = ref(false);
const approvalAction = ref('approve');
const approvalNotes = ref('');
const processing = ref(false);

const openApprovalModal = (action) => {
    approvalAction.value = action;
    approvalNotes.value = '';
    showApprovalModal.value = true;
};

const submitApproval = () => {
    processing.value = true;
    const routeName = approvalAction.value === 'approve' ? 'orders.approve' : 'orders.reject';

    router.post(route(routeName, props.order.id), {
        notes: approvalNotes.value,
    }, {
        onFinish: () => {
            processing.value = false;
            showApprovalModal.value = false;
        },
    });
};

const statusVariant = (status) =>
    ({
        pending: 'warning',
        processing: 'info',
        shipped: 'brand',
        delivered: 'success',
        cancelled: 'danger',
    }[status] || 'neutral');

const approvalStatusVariant = (status) =>
    ({
        pending: 'warning',
        approved: 'success',
        rejected: 'danger',
    }[status] || 'neutral');

const deleteOrder = () => {
    deleting.value = true;
    router.delete(route('orders.destroy', props.order.id), {
        onFinish: () => {
            deleting.value = false;
            showDeleteModal.value = false;
        },
    });
};

const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

const formatDateShort = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
};
</script>

<template>
    <Head :title="t('orders.show.orderNumber', { number: order.order_number })" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('orders.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('orders.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">#{{ order.order_number }}</span>
            </div>
        </template>

        <PageHeader
            :title="`Order #${order.order_number}`"
            :description="`Created on ${formatDateShort(order.order_date)}`"
        >
            <template #actions>
                <Badge :variant="statusVariant(order.status)" size="sm" dot>{{ order.status }}</Badge>
                <Badge v-if="order.approval_status" :variant="approvalStatusVariant(order.approval_status)" size="sm" dot>{{ order.approval_status }}</Badge>
                <Button
                    v-if="hasPermission('view_orders')"
                    variant="secondary"
                    size="sm"
                    as="a"
                    :href="route('orders.invoice.download', order.id)"
                >
                    <Download :size="14" />
                    Download Invoice
                </Button>
                <Button
                    v-if="hasPermission('view_orders')"
                    variant="secondary"
                    size="sm"
                    as="a"
                    :href="route('orders.invoice.preview', order.id)"
                    target="_blank"
                >
                    <Eye :size="14" />
                    Preview Invoice
                </Button>
                <Button
                    v-if="hasPermission('manage_returns')"
                    variant="secondary"
                    size="sm"
                    as="Link"
                    :href="route('returns.create', { order_id: order.id })"
                >
                    <Undo2 :size="14" />
                    Create Return
                </Button>
                <Button
                    v-if="hasPermission('edit_orders')"
                    variant="default"
                    size="sm"
                    as="Link"
                    :href="route('orders.edit', order.id)"
                >
                    <Pencil :size="14" />
                    {{ t('orders.show.editOrder') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('orders.index')">
                    <ArrowLeft :size="14" />
                    {{ t('orders.show.backToOrders') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Left Column: Order Items & Details -->
            <div class="space-y-4 lg:col-span-2">
                <!-- Order Items -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.orderItems') }}</h3></div>
                    <div class="p-5">
                        <div v-if="order.items && order.items.length > 0" class="space-y-3">
                            <div
                                v-for="(item, index) in order.items"
                                :key="index"
                                class="flex items-center gap-4 rounded-lg border border-border-subtle bg-surface-canvas p-4"
                            >
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-text-primary">{{ item.product_name }}</p>
                                    <p class="text-xs text-text-tertiary">SKU: {{ item.sku }}</p>
                                    <Link
                                        v-if="item.product"
                                        :href="route('products.show', item.product_id)"
                                        class="mt-1 inline-block text-xs text-brand hover:underline"
                                    >
                                        {{ t('orders.show.viewProduct') }}
                                    </Link>
                                </div>

                                <div class="text-right">
                                    <p class="text-xs text-text-tertiary">{{ t('common.quantity') }}</p>
                                    <p class="font-medium tabular-nums text-text-primary">{{ item.quantity }}</p>
                                </div>

                                <div class="text-right">
                                    <p class="text-xs text-text-tertiary">{{ t('orders.show.unitPrice') }}</p>
                                    <p class="font-medium tabular-nums text-text-primary">${{ parseFloat(item.unit_price).toFixed(2) }}</p>
                                </div>

                                <div class="min-w-[100px] text-right">
                                    <p class="text-xs text-text-tertiary">{{ t('common.total') }}</p>
                                    <p class="font-semibold tabular-nums text-text-primary">
                                        ${{ parseFloat(item.total || item.subtotal || (item.quantity * item.unit_price)).toFixed(2) }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
                            <PackageOpen :size="22" class="text-text-tertiary" />
                            <p class="text-sm text-text-tertiary">{{ t('orders.show.noItems') }}</p>
                        </div>
                    </div>
                </Card>

                <!-- Customer Information -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.customerInfo') }}</h3></div>
                    <div class="p-5">
                        <dl class="space-y-3">
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('orders.show.customerName') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ order.customer_name }}</dd>
                            </div>

                            <div v-if="order.customer_email">
                                <dt class="text-xs text-text-tertiary">{{ t('common.email') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">
                                    <a :href="`mailto:${order.customer_email}`" class="text-brand hover:underline">
                                        {{ order.customer_email }}
                                    </a>
                                </dd>
                            </div>

                            <div v-if="order.customer_address">
                                <dt class="text-xs text-text-tertiary">{{ t('orders.show.shippingAddress') }}</dt>
                                <dd class="mt-1 whitespace-pre-line text-sm text-text-primary">{{ order.customer_address }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Order Timeline -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.orderTimeline') }}</h3></div>
                    <div class="p-5">
                        <div class="space-y-4">
                            <div class="flex items-start gap-3">
                                <div class="mt-2 h-2 w-2 flex-shrink-0 rounded-full bg-status-success"></div>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-text-primary">{{ t('orders.show.orderCreated') }}</p>
                                    <p class="text-xs text-text-tertiary">{{ formatDate(order.order_date) }}</p>
                                </div>
                            </div>

                            <div v-if="order.shipped_at" class="flex items-start gap-3">
                                <div class="mt-2 h-2 w-2 flex-shrink-0 rounded-full bg-brand"></div>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-text-primary">{{ t('orders.show.orderShipped') }}</p>
                                    <p class="text-xs text-text-tertiary">{{ formatDate(order.shipped_at) }}</p>
                                </div>
                            </div>

                            <div v-if="order.delivered_at" class="flex items-start gap-3">
                                <div class="mt-2 h-2 w-2 flex-shrink-0 rounded-full bg-status-success"></div>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-text-primary">{{ t('orders.show.orderDelivered') }}</p>
                                    <p class="text-xs text-text-tertiary">{{ formatDate(order.delivered_at) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- Notes -->
                <Card v-if="order.notes" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.internalNotes') }}</h3></div>
                    <div class="p-5">
                        <p class="whitespace-pre-line text-sm text-text-secondary">{{ order.notes }}</p>
                    </div>
                </Card>
            </div>

            <!-- Right Column: Summary & Actions -->
            <div class="space-y-4">
                <!-- Plugin Slot: Sidebar -->
                <PluginSlot slot="sidebar" :components="pluginComponents?.sidebar" />

                <!-- Order Summary -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.orderSummary') }}</h3></div>
                    <div class="p-5">
                        <dl class="space-y-3">
                            <div class="flex justify-between text-sm">
                                <dt class="text-text-secondary">{{ t('common.subtotal') }}</dt>
                                <dd class="font-medium tabular-nums text-text-primary">${{ parseFloat(order.subtotal).toFixed(2) }}</dd>
                            </div>

                            <div class="flex justify-between text-sm">
                                <dt class="text-text-secondary">{{ t('common.tax') }}</dt>
                                <dd class="font-medium tabular-nums text-text-primary">${{ parseFloat(order.tax || 0).toFixed(2) }}</dd>
                            </div>

                            <div class="flex justify-between text-sm">
                                <dt class="text-text-secondary">{{ t('common.shipping') }}</dt>
                                <dd class="font-medium tabular-nums text-text-primary">${{ parseFloat(order.shipping || 0).toFixed(2) }}</dd>
                            </div>

                            <div class="border-t border-border-subtle pt-3">
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm font-semibold text-text-primary">{{ t('common.total') }}</dt>
                                    <dd class="text-xl font-bold tabular-nums text-brand">${{ parseFloat(order.total).toFixed(2) }}</dd>
                                </div>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Order Details -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.orderDetails') }}</h3></div>
                    <div class="p-5">
                        <dl class="space-y-3">
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('orders.show.orderNumber2') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ order.order_number }}</dd>
                            </div>

                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('orders.source') }}</dt>
                                <dd class="mt-1">
                                    <Badge variant="brand" size="sm" class="capitalize">{{ order.source }}</Badge>
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('common.status') }}</dt>
                                <dd class="mt-1">
                                    <Badge :variant="statusVariant(order.status)" size="sm" dot class="capitalize">{{ order.status }}</Badge>
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('purchaseOrders.orderDate') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ formatDateShort(order.order_date) }}</dd>
                            </div>

                            <div v-if="order.currency">
                                <dt class="text-xs text-text-tertiary">{{ t('common.currency') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ order.currency }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Approval Status -->
                <Card v-if="order.approval_status" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.approvalStatus') }}</h3></div>
                    <div class="p-5">
                        <dl class="space-y-3">
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('common.status') }}</dt>
                                <dd class="mt-1">
                                    <Badge :variant="approvalStatusVariant(order.approval_status)" size="sm" dot class="capitalize">{{ order.approval_status }}</Badge>
                                </dd>
                            </div>

                            <div v-if="order.creator">
                                <dt class="text-xs text-text-tertiary">{{ t('orders.show.createdBy') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ order.creator.name }}</dd>
                            </div>

                            <div v-if="order.approver">
                                <dt class="text-xs text-text-tertiary">{{ order.approval_status === 'approved' ? t('orders.show.approved') : t('orders.show.rejected') }} {{ t('orders.show.by') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ order.approver.name }}</dd>
                            </div>

                            <div v-if="order.approved_at">
                                <dt class="text-xs text-text-tertiary">{{ t('orders.show.decisionDate') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ formatDate(order.approved_at) }}</dd>
                            </div>

                            <div v-if="order.approval_notes">
                                <dt class="text-xs text-text-tertiary">{{ t('common.notes') }}</dt>
                                <dd class="mt-1 text-sm text-text-secondary">{{ order.approval_notes }}</dd>
                            </div>
                        </dl>

                        <!-- Approval Actions -->
                        <div v-if="canApprove && order.approval_status === 'pending'" class="mt-4 space-y-2 border-t border-border-subtle pt-4">
                            <Button variant="default" class="w-full" @click="openApprovalModal('approve')">
                                {{ t('orders.show.approveOrder') }}
                            </Button>
                            <Button variant="danger" class="w-full" @click="openApprovalModal('reject')">
                                {{ t('orders.show.rejectOrder') }}
                            </Button>
                        </div>
                    </div>
                </Card>

                <!-- Actions -->
                <Card v-if="hasPermission('delete_orders')" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.dangerZone') }}</h3></div>
                    <div class="p-5">
                        <Button variant="danger" class="w-full" @click="showDeleteModal = true">
                            {{ t('orders.show.deleteOrder') }}
                        </Button>
                        <p class="mt-2 text-xs text-text-tertiary">
                            {{ t('orders.show.deleteWarning') }}
                        </p>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />

        <!-- Delete Confirmation Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center" @click="showDeleteModal = false">
                <div class="fixed inset-0 bg-black/50"></div>

                <div class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg" @click.stop>
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">
                            {{ t('orders.show.deleteOrder') }}
                        </h3>
                        <button
                            @click="showDeleteModal = false"
                            class="text-text-tertiary transition-colors hover:text-text-primary"
                        >
                            <X :size="18" />
                        </button>
                    </div>

                    <div class="mb-6">
                        <p class="mb-4 text-sm text-text-secondary">
                            Are you sure you want to delete order <strong class="text-text-primary">#{{ order.order_number }}</strong>?
                        </p>
                        <div class="rounded-lg border border-status-warning/20 bg-status-warning-soft p-4">
                            <div class="flex items-start gap-3">
                                <AlertTriangle :size="20" class="mt-0.5 flex-shrink-0 text-status-warning" />
                                <div class="text-sm text-status-warning">
                                    <p class="mb-1 font-semibold">{{ t('orders.show.cannotUndo') }}</p>
                                    <p>{{ t('orders.show.stockRestored') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3">
                        <Button variant="secondary" @click="showDeleteModal = false" :disabled="deleting">
                            {{ t('common.cancel') }}
                        </Button>
                        <Button variant="danger" :loading="deleting" :disabled="deleting" @click="deleteOrder">
                            <span v-if="deleting">{{ t('common.deleting') }}</span>
                            <span v-else>{{ t('orders.show.deleteOrder') }}</span>
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Approval Modal -->
        <Teleport to="body">
            <div v-if="showApprovalModal" class="fixed inset-0 z-50 flex items-center justify-center" @click="showApprovalModal = false">
                <div class="fixed inset-0 bg-black/50"></div>

                <div class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg" @click.stop>
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">
                            {{ approvalAction === 'approve' ? t('orders.show.approveOrder') : t('orders.show.rejectOrder') }}
                        </h3>
                        <button
                            @click="showApprovalModal = false"
                            class="text-text-tertiary transition-colors hover:text-text-primary"
                        >
                            <X :size="18" />
                        </button>
                    </div>

                    <div class="mb-6">
                        <p class="mb-4 text-sm text-text-secondary">
                            {{ approvalAction === 'approve'
                                ? t('orders.show.confirmApprove', { number: order.order_number })
                                : t('orders.show.confirmReject', { number: order.order_number })
                            }}
                        </p>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-text-secondary">
                                {{ t('common.notes') }} {{ approvalAction === 'reject' ? t('orders.show.notesRequired') : t('orders.show.notesOptional') }}
                            </label>
                            <textarea
                                v-model="approvalNotes"
                                rows="3"
                                class="w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                                :placeholder="approvalAction === 'approve' ? t('orders.show.approvalNotesPlaceholder') : t('orders.show.rejectionNotesPlaceholder')"
                                :required="approvalAction === 'reject'"
                            ></textarea>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3">
                        <Button variant="secondary" @click="showApprovalModal = false" :disabled="processing">
                            {{ t('common.cancel') }}
                        </Button>
                        <Button
                            :variant="approvalAction === 'approve' ? 'default' : 'danger'"
                            :loading="processing"
                            :disabled="processing || (approvalAction === 'reject' && !approvalNotes)"
                            @click="submitApproval"
                        >
                            <span v-if="processing">{{ t('common.loading') }}</span>
                            <span v-else>{{ approvalAction === 'approve' ? t('orders.show.approve') : t('orders.show.reject') }}</span>
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
