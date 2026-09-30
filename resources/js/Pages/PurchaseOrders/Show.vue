<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import SendDocumentModal from '@/Components/SendDocumentModal.vue';
import ApprovalPanel from '@/Components/ApprovalPanel.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { displayCalendarDate, displayDate } from '@/lib/dates';
import { formatMoney } from '@/lib/money';
import { ArrowLeft, Pencil, Download, Eye, Send, PackageCheck, Ban, Trash2 } from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';

const { canVisit } = usePermissions();

const { t } = useI18n();

const props = defineProps({
    purchaseOrder: Object,
    pluginComponents: Object,
    approval: { type: Object, default: () => ({}) },
});

// A draft the organization's approval rules cover cannot be sent until it
// is approved, and is frozen while an approver is looking at it.
const awaitingApproval = computed(() =>
    props.purchaseOrder.status === 'draft'
    && props.approval.needs_approval
    && props.purchaseOrder.approval_status !== 'approved'
);
const lockedForApproval = computed(() => props.purchaseOrder.approval_status === 'pending');

const formatCurrency = (value) => {
    return formatMoney(value, props.purchaseOrder.currency);
};

// sent_at is an instant, shown in the viewer's timezone.
const formatDate = (dateString) => {
    if (!dateString) return '-';
    return displayDate(dateString);
};

// order_date, expected_date and received_date are calendar days (date columns).
const formatDay = (dateString) =>
    displayCalendarDate(dateString);

const statusVariant = (status) =>
    ({
        draft: 'neutral',
        sent: 'info',
        partial: 'warning',
        received: 'success',
        cancelled: 'danger',
    }[status] || 'neutral');

const statusLabels = {
    draft: t('purchaseOrders.status.draft'),
    sent: t('purchaseOrders.status.sent'),
    partial: t('purchaseOrders.status.partial'),
    received: t('purchaseOrders.status.received'),
    cancelled: t('purchaseOrders.status.cancelled'),
};

const showSendModal = ref(false);

// Drafts are sent for the first time; orders already with the supplier can be re-sent.
const canSend = computed(() => ['draft', 'sent', 'partial'].includes(props.purchaseOrder.status) && !awaitingApproval.value);
const isResend = computed(() => props.purchaseOrder.status !== 'draft');

// queued_at is stamped when the email is queued, sent_at when it is delivered;
// a queued_at newer than sent_at means the latest send has not gone out yet.
const emailQueued = computed(() => {
    const { queued_at: queuedAt, sent_at: sentAt } = props.purchaseOrder;
    return Boolean(queuedAt) && (!sentAt || new Date(sentAt) < new Date(queuedAt));
});

const sentSummary = computed(() => {
    if (emailQueued.value) {
        return t('documentEmail.queuedOn', { to: props.purchaseOrder.sent_to, date: formatDate(props.purchaseOrder.queued_at) });
    }
    return props.purchaseOrder.sent_at
        ? t('documentEmail.sentOn', { to: props.purchaseOrder.sent_to, date: formatDate(props.purchaseOrder.sent_at) })
        : null;
});

const cancelPO = () => {
    if (confirm(t('purchaseOrders.show.confirmCancel'))) {
        router.post(route('purchase-orders.cancel', props.purchaseOrder.id));
    }
};

const deletePO = () => {
    if (confirm(t('purchaseOrders.confirmDelete', { number: props.purchaseOrder.po_number }))) {
        router.delete(route('purchase-orders.destroy', props.purchaseOrder.id));
    }
};

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="purchaseOrder.po_number" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('purchase-orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('purchase-orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('purchaseOrders.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ purchaseOrder.po_number }}</span>
            </div>
        </template>

        <PageHeader
            :title="purchaseOrder.po_number"
            :description="t('purchaseOrders.show.orderDateOn', { date: formatDay(purchaseOrder.order_date) })"
        >
            <template #actions>
                <Badge :variant="statusVariant(purchaseOrder.status)" size="sm" dot>
                    {{ statusLabels[purchaseOrder.status] || purchaseOrder.status }}
                </Badge>
                <Button
                    variant="secondary"
                    size="sm"
                    as="a"
                    :href="route('purchase-orders.invoice.download', purchaseOrder.id)"
                >
                    <Download :size="14" />
                    {{ t('purchaseOrders.show.downloadPdf') }}
                </Button>
                <Button
                    variant="secondary"
                    size="sm"
                    as="a"
                    :href="route('purchase-orders.invoice.preview', purchaseOrder.id)"
                    target="_blank"
                >
                    <Eye :size="14" />
                    {{ t('purchaseOrders.show.previewPdf') }}
                </Button>
                <Button
                    v-if="(purchaseOrder.status === 'draft' && !lockedForApproval) && canVisit('purchase-orders.edit')"
                    variant="default"
                    size="sm"
                    as="Link"
                    :href="route('purchase-orders.edit', purchaseOrder.id)"
                >
                    <Pencil :size="14" />
                    {{ t('common.edit') }}
                </Button>
                <Button
                    v-if="(purchaseOrder.status === 'sent' || purchaseOrder.status === 'partial') && canVisit('purchase-orders.receive')"
                    variant="default"
                    size="sm"
                    as="Link"
                    :href="route('purchase-orders.receive', purchaseOrder.id)"
                >
                    <PackageCheck :size="14" />
                    {{ t('purchaseOrders.show.receiveItems') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('purchase-orders.index')">
                    <ArrowLeft :size="14" />
                    {{ t('common.back') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <ApprovalPanel
            class="mt-6"
            type="purchase_order"
            :id="purchaseOrder.id"
            :status="purchaseOrder.approval_status"
            :needs-approval="purchaseOrder.status === 'draft' && !!approval.needs_approval"
            :requester="purchaseOrder.approval_requester?.name || null"
            :approver="purchaseOrder.approver?.name || null"
            :approved-at="purchaseOrder.approved_at"
            :notes="purchaseOrder.approval_notes"
            :can-decide="!!approval.can_decide"
            :can-submit="!!approval.can_submit"
            :submit-url="route('purchase-orders.submit-approval', purchaseOrder.id)"
        />

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Left Column: Order Details & Items -->
            <div class="space-y-4 lg:col-span-2">
                <!-- Order Details -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('purchaseOrders.show.orderDetails') }}</h3></div>
                    <div class="p-5">
                        <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('purchaseOrders.supplier') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">
                                    <span v-if="purchaseOrder.supplier && !canVisit('suppliers.show')">{{ purchaseOrder.supplier.name }}</span>
                                    <Link v-else-if="purchaseOrder.supplier" :href="route('suppliers.show', purchaseOrder.supplier.id)" class="text-brand hover:underline">
                                        {{ purchaseOrder.supplier.name }}
                                    </Link>
                                    <span v-else>-</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('purchaseOrders.show.createdBy') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ purchaseOrder.creator?.name || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('purchaseOrders.orderDate') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ formatDay(purchaseOrder.order_date) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('purchaseOrders.show.expectedDelivery') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ formatDay(purchaseOrder.expected_date) }}</dd>
                            </div>
                            <div v-if="purchaseOrder.received_date">
                                <dt class="text-xs text-text-tertiary">{{ t('purchaseOrders.show.receivedDate') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ formatDay(purchaseOrder.received_date) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('common.currency') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ purchaseOrder.currency }}</dd>
                            </div>
                        </dl>
                        <div v-if="purchaseOrder.notes" class="mt-6">
                            <dt class="text-xs text-text-tertiary">{{ t('common.notes') }}</dt>
                            <dd class="mt-1 whitespace-pre-wrap text-sm text-text-secondary">{{ purchaseOrder.notes }}</dd>
                        </div>
                    </div>
                </Card>

                <!-- Items -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('purchaseOrders.show.itemsCount', { count: purchaseOrder.items?.length || 0 }) }}</h3></div>
                    <div class="p-5">
                        <!-- Phones: one card per line, so cost and total stay in view. -->
                        <ul class="space-y-3 sm:hidden">
                            <li
                                v-for="item in purchaseOrder.items"
                                :key="item.id"
                                class="rounded-lg border border-border-subtle bg-surface-canvas p-4"
                            >
                                <p class="text-sm font-medium text-text-primary">
                                    <span v-if="item.product && !canVisit('products.show')">{{ item.product_name }}</span>
                                    <Link v-else-if="item.product" :href="route('products.show', item.product.id)" class="text-brand hover:underline">{{ item.product_name }}</Link>
                                    <span v-else>{{ item.product_name }}</span>
                                </p>
                                <p v-if="item.variant" class="text-xs text-text-secondary">{{ t('orders.create.variant') }}: {{ item.variant.title }}</p>
                                <p class="text-xs text-text-tertiary">SKU: {{ item.sku || '-' }}</p>
                                <p v-if="item.supplier_sku" class="text-xs text-text-tertiary">{{ t('purchaseOrders.show.supplierSkuLine', { sku: item.supplier_sku }) }}</p>
                                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                    <div>
                                        <dt class="text-xs text-text-tertiary">{{ t('purchaseOrders.show.ordered') }}</dt>
                                        <dd class="tabular-nums text-text-primary">{{ item.quantity_ordered }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-text-tertiary">{{ t('purchaseOrders.show.received') }}</dt>
                                        <dd
                                            class="tabular-nums"
                                            :class="item.quantity_received >= item.quantity_ordered ? 'text-status-success' : item.quantity_received > 0 ? 'text-status-warning' : 'text-text-tertiary'"
                                        >{{ item.quantity_received }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-text-tertiary">{{ t('purchaseOrders.create.unitCost') }}</dt>
                                        <dd class="tabular-nums text-text-secondary">{{ formatCurrency(item.unit_cost) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-text-tertiary">{{ t('common.total') }}</dt>
                                        <dd class="font-medium tabular-nums text-text-primary">{{ formatCurrency(item.total) }}</dd>
                                    </div>
                                </dl>
                            </li>
                        </ul>
                        <dl class="mt-4 space-y-2 border-t border-border-subtle pt-3 text-sm sm:hidden">
                            <div class="flex justify-between">
                                <dt class="text-text-secondary">{{ t('common.subtotal') }}</dt>
                                <dd class="font-medium tabular-nums text-text-primary">{{ formatCurrency(purchaseOrder.subtotal) }}</dd>
                            </div>
                            <div v-if="purchaseOrder.tax > 0" class="flex justify-between">
                                <dt class="text-text-secondary">{{ t('common.tax') }}</dt>
                                <dd class="tabular-nums text-text-primary">{{ formatCurrency(purchaseOrder.tax) }}</dd>
                            </div>
                            <div v-if="purchaseOrder.shipping > 0" class="flex justify-between">
                                <dt class="text-text-secondary">{{ t('common.shipping') }}</dt>
                                <dd class="tabular-nums text-text-primary">{{ formatCurrency(purchaseOrder.shipping) }}</dd>
                            </div>
                            <div class="flex justify-between border-t border-border-subtle pt-2">
                                <dt class="font-bold text-text-primary">{{ t('common.total') }}</dt>
                                <dd class="font-bold tabular-nums text-brand">{{ formatCurrency(purchaseOrder.total) }}</dd>
                            </div>
                        </dl>

                        <div class="hidden w-full overflow-x-auto rounded-lg border border-border-subtle sm:block">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-border-subtle">
                                        <th :class="thClass">{{ t('common.product') }}</th>
                                        <th :class="thClass">SKU</th>
                                        <th :class="thClass">{{ t('purchaseOrders.show.ordered') }}</th>
                                        <th :class="thClass">{{ t('purchaseOrders.show.received') }}</th>
                                        <th :class="thClass">{{ t('purchaseOrders.create.unitCost') }}</th>
                                        <th :class="thClass">{{ t('common.total') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in purchaseOrder.items" :key="item.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                                        <td class="min-w-[12rem] px-4 py-3 text-sm text-text-primary">
                                            <span v-if="item.product && !canVisit('products.show')">{{ item.product_name }}</span>
                                            <Link v-else-if="item.product" :href="route('products.show', item.product.id)" class="text-brand hover:underline">
                                                {{ item.product_name }}
                                            </Link>
                                            <span v-else>{{ item.product_name }}</span>
                                            <span v-if="item.variant" class="block text-xs text-text-secondary">{{ t('orders.create.variant') }}: {{ item.variant.title }}</span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-text-tertiary">
                                            {{ item.sku || '-' }}
                                            <span v-if="item.supplier_sku" class="block text-xs text-text-tertiary">
                                                {{ t('purchaseOrders.show.supplierSkuLine', { sku: item.supplier_sku }) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm tabular-nums text-text-primary">
                                            {{ item.quantity_ordered }}
                                        </td>
                                        <td class="px-4 py-3 text-sm tabular-nums">
                                            <span :class="[
                                                item.quantity_received >= item.quantity_ordered
                                                    ? 'text-status-success'
                                                    : item.quantity_received > 0
                                                        ? 'text-status-warning'
                                                        : 'text-text-tertiary'
                                            ]">
                                                {{ item.quantity_received }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm tabular-nums text-text-tertiary">
                                            {{ formatCurrency(item.unit_cost) }}
                                        </td>
                                        <td class="px-4 py-3 text-sm font-medium tabular-nums text-text-primary">
                                            {{ formatCurrency(item.total) }}
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t border-border-subtle">
                                        <td colspan="5" class="px-4 py-3 text-right text-sm font-medium text-text-secondary">{{ t('common.subtotal') }}:</td>
                                        <td class="px-4 py-3 text-sm font-medium tabular-nums text-text-primary">{{ formatCurrency(purchaseOrder.subtotal) }}</td>
                                    </tr>
                                    <tr v-if="purchaseOrder.tax > 0">
                                        <td colspan="5" class="px-4 py-3 text-right text-sm font-medium text-text-secondary">{{ t('common.tax') }}:</td>
                                        <td class="px-4 py-3 text-sm tabular-nums text-text-primary">{{ formatCurrency(purchaseOrder.tax) }}</td>
                                    </tr>
                                    <tr v-if="purchaseOrder.shipping > 0">
                                        <td colspan="5" class="px-4 py-3 text-right text-sm font-medium text-text-secondary">{{ t('common.shipping') }}:</td>
                                        <td class="px-4 py-3 text-sm tabular-nums text-text-primary">{{ formatCurrency(purchaseOrder.shipping) }}</td>
                                    </tr>
                                    <tr class="border-t border-border-subtle">
                                        <td colspan="5" class="px-4 py-3 text-right text-sm font-bold text-text-primary">{{ t('common.total') }}:</td>
                                        <td class="px-4 py-3 text-sm font-bold tabular-nums text-brand">{{ formatCurrency(purchaseOrder.total) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Right Column: Status & Actions -->
            <div class="space-y-4">
                <!-- Plugin Slot: Sidebar -->
                <PluginSlot slot="sidebar" :components="pluginComponents?.sidebar" />

                <!-- Status -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('common.status') }}</h3></div>
                    <div class="p-5">
                        <Badge :variant="statusVariant(purchaseOrder.status)" size="md" dot>
                            {{ statusLabels[purchaseOrder.status] || purchaseOrder.status }}
                        </Badge>
                        <p v-if="sentSummary" class="mt-3 text-xs text-text-secondary">{{ sentSummary }}</p>
                    </div>
                </Card>

                <!-- Order Summary -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.orderSummary') }}</h3></div>
                    <div class="p-5">
                        <dl class="space-y-3">
                            <div class="flex justify-between text-sm">
                                <dt class="text-text-secondary">{{ t('common.subtotal') }}</dt>
                                <dd class="font-medium tabular-nums text-text-primary">{{ formatCurrency(purchaseOrder.subtotal) }}</dd>
                            </div>
                            <div v-if="purchaseOrder.tax > 0" class="flex justify-between text-sm">
                                <dt class="text-text-secondary">{{ t('common.tax') }}</dt>
                                <dd class="font-medium tabular-nums text-text-primary">{{ formatCurrency(purchaseOrder.tax) }}</dd>
                            </div>
                            <div v-if="purchaseOrder.shipping > 0" class="flex justify-between text-sm">
                                <dt class="text-text-secondary">{{ t('common.shipping') }}</dt>
                                <dd class="font-medium tabular-nums text-text-primary">{{ formatCurrency(purchaseOrder.shipping) }}</dd>
                            </div>
                            <div class="border-t border-border-subtle pt-3">
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm font-semibold text-text-primary">{{ t('common.total') }}</dt>
                                    <dd class="text-xl font-bold tabular-nums text-brand">{{ formatCurrency(purchaseOrder.total) }}</dd>
                                </div>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Actions -->
                <Card
                    v-if="purchaseOrder.status === 'draft' || purchaseOrder.status === 'sent' || purchaseOrder.status === 'partial'"
                    :padded="false"
                >
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('common.actions') }}</h3></div>
                    <div class="space-y-3 p-5">
                        <Button
                            v-if="canSend"
                            :variant="isResend ? 'secondary' : 'default'"
                            class="w-full"
                            @click="showSendModal = true"
                        >
                            <Send :size="16" />
                            {{ isResend ? t('documentEmail.resendPo') : t('documentEmail.sendPo') }}
                        </Button>
                        <p v-if="awaitingApproval" class="text-xs text-text-tertiary">
                            {{ t('approvals.poSendBlocked') }}
                        </p>
                        <Button
                            v-if="(purchaseOrder.status === 'sent' || purchaseOrder.status === 'partial') && canVisit('purchase-orders.receive')"
                            variant="default"
                            class="w-full"
                            as="Link"
                            :href="route('purchase-orders.receive', purchaseOrder.id)"
                        >
                            <PackageCheck :size="16" />
                            {{ t('purchaseOrders.show.receiveItems') }}
                        </Button>
                        <Button
                            v-if="(purchaseOrder.status === 'draft' && !lockedForApproval) && canVisit('purchase-orders.edit')"
                            variant="secondary"
                            class="w-full"
                            as="Link"
                            :href="route('purchase-orders.edit', purchaseOrder.id)"
                        >
                            <Pencil :size="16" />
                            {{ t('purchaseOrders.show.editPo') }}
                        </Button>
                        <Button
                            v-if="purchaseOrder.status === 'draft' || purchaseOrder.status === 'sent'"
                            variant="secondary"
                            class="w-full"
                            @click="cancelPO"
                        >
                            <Ban :size="16" />
                            {{ t('purchaseOrders.show.cancelOrder') }}
                        </Button>
                    </div>
                </Card>

                <!-- Danger Zone -->
                <Card v-if="purchaseOrder.status === 'draft'" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.dangerZone') }}</h3></div>
                    <div class="p-5">
                        <Button variant="danger" class="w-full" @click="deletePO">
                            <Trash2 :size="16" />
                            {{ t('purchaseOrders.show.deleteOrder') }}
                        </Button>
                        <p class="mt-2 text-xs text-text-tertiary">
                            {{ t('purchaseOrders.show.cannotUndo') }}
                        </p>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />

        <SendDocumentModal
            :show="showSendModal"
            :title="t('documentEmail.sendPoTitle')"
            :description="t('documentEmail.sendPoDescription', { number: purchaseOrder.po_number })"
            :action="route('purchase-orders.send', purchaseOrder.id)"
            :default-to="purchaseOrder.supplier?.email || ''"
            :attachment-name="`${purchaseOrder.po_number}.pdf`"
            :submit-label="isResend ? t('documentEmail.resendPo') : t('documentEmail.sendPo')"
            @close="showSendModal = false"
        />
    </AppLayout>
</template>
