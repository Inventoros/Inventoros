<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Pencil, Download, Eye, Send, PackageCheck, Ban, Trash2 } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    purchaseOrder: Object,
    pluginComponents: Object,
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: props.purchaseOrder.currency || 'USD',
    }).format(value || 0);
};

const formatDate = (dateString) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};

const statusVariant = (status) =>
    ({
        draft: 'neutral',
        sent: 'info',
        partial: 'warning',
        received: 'success',
        cancelled: 'danger',
    }[status] || 'neutral');

const statusLabels = {
    draft: 'Draft',
    sent: 'Sent',
    partial: 'Partial',
    received: 'Received',
    cancelled: 'Cancelled',
};

const sendToSupplier = () => {
    if (confirm('Mark this purchase order as sent to supplier?')) {
        router.post(route('purchase-orders.send', props.purchaseOrder.id));
    }
};

const cancelPO = () => {
    if (confirm('Are you sure you want to cancel this purchase order?')) {
        router.post(route('purchase-orders.cancel', props.purchaseOrder.id));
    }
};

const deletePO = () => {
    if (confirm(`Are you sure you want to delete "${props.purchaseOrder.po_number}"?`)) {
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
                <Link :href="route('purchase-orders.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('purchase-orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('purchaseOrders.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ purchaseOrder.po_number }}</span>
            </div>
        </template>

        <PageHeader
            :title="purchaseOrder.po_number"
            :description="`Order date ${formatDate(purchaseOrder.order_date)}`"
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
                    Download PDF
                </Button>
                <Button
                    variant="secondary"
                    size="sm"
                    as="a"
                    :href="route('purchase-orders.invoice.preview', purchaseOrder.id)"
                    target="_blank"
                >
                    <Eye :size="14" />
                    Preview PDF
                </Button>
                <Button
                    v-if="purchaseOrder.status === 'draft'"
                    variant="default"
                    size="sm"
                    as="Link"
                    :href="route('purchase-orders.edit', purchaseOrder.id)"
                >
                    <Pencil :size="14" />
                    Edit
                </Button>
                <Button
                    v-if="purchaseOrder.status === 'sent' || purchaseOrder.status === 'partial'"
                    variant="default"
                    size="sm"
                    as="Link"
                    :href="route('purchase-orders.receive', purchaseOrder.id)"
                >
                    <PackageCheck :size="14" />
                    Receive Items
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('purchase-orders.index')">
                    <ArrowLeft :size="14" />
                    Back
                </Button>
            </template>
        </PageHeader>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Left Column: Order Details & Items -->
            <div class="space-y-4 lg:col-span-2">
                <!-- Order Details -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Order Details</h3></div>
                    <div class="p-5">
                        <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-text-tertiary">Supplier</dt>
                                <dd class="mt-1 text-sm text-text-primary">
                                    <Link v-if="purchaseOrder.supplier" :href="route('suppliers.show', purchaseOrder.supplier.id)" class="text-brand hover:underline">
                                        {{ purchaseOrder.supplier.name }}
                                    </Link>
                                    <span v-else>-</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">Created By</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ purchaseOrder.creator?.name || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">Order Date</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ formatDate(purchaseOrder.order_date) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">Expected Delivery</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ formatDate(purchaseOrder.expected_date) }}</dd>
                            </div>
                            <div v-if="purchaseOrder.received_date">
                                <dt class="text-xs text-text-tertiary">Received Date</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ formatDate(purchaseOrder.received_date) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">Currency</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ purchaseOrder.currency }}</dd>
                            </div>
                        </dl>
                        <div v-if="purchaseOrder.notes" class="mt-6">
                            <dt class="text-xs text-text-tertiary">Notes</dt>
                            <dd class="mt-1 whitespace-pre-wrap text-sm text-text-secondary">{{ purchaseOrder.notes }}</dd>
                        </div>
                    </div>
                </Card>

                <!-- Items -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Items ({{ purchaseOrder.items?.length || 0 }})</h3></div>
                    <div class="p-5">
                        <div class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-border-subtle">
                                        <th :class="thClass">Product</th>
                                        <th :class="thClass">SKU</th>
                                        <th :class="thClass">Ordered</th>
                                        <th :class="thClass">Received</th>
                                        <th :class="thClass">Unit Cost</th>
                                        <th :class="thClass">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in purchaseOrder.items" :key="item.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                                        <td class="px-4 py-3 text-sm text-text-primary">
                                            <Link v-if="item.product" :href="route('products.show', item.product.id)" class="text-brand hover:underline">
                                                {{ item.product_name }}
                                            </Link>
                                            <span v-else>{{ item.product_name }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-text-tertiary">
                                            {{ item.sku || '-' }}
                                            <span v-if="item.supplier_sku" class="block text-xs text-text-tertiary">
                                                Supplier: {{ item.supplier_sku }}
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
                                        <td colspan="5" class="px-4 py-3 text-right text-sm font-medium text-text-secondary">Subtotal:</td>
                                        <td class="px-4 py-3 text-sm font-medium tabular-nums text-text-primary">{{ formatCurrency(purchaseOrder.subtotal) }}</td>
                                    </tr>
                                    <tr v-if="purchaseOrder.tax > 0">
                                        <td colspan="5" class="px-4 py-3 text-right text-sm font-medium text-text-secondary">Tax:</td>
                                        <td class="px-4 py-3 text-sm tabular-nums text-text-primary">{{ formatCurrency(purchaseOrder.tax) }}</td>
                                    </tr>
                                    <tr v-if="purchaseOrder.shipping > 0">
                                        <td colspan="5" class="px-4 py-3 text-right text-sm font-medium text-text-secondary">Shipping:</td>
                                        <td class="px-4 py-3 text-sm tabular-nums text-text-primary">{{ formatCurrency(purchaseOrder.shipping) }}</td>
                                    </tr>
                                    <tr class="border-t border-border-subtle">
                                        <td colspan="5" class="px-4 py-3 text-right text-sm font-bold text-text-primary">Total:</td>
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
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Status</h3></div>
                    <div class="p-5">
                        <Badge :variant="statusVariant(purchaseOrder.status)" size="md" dot>
                            {{ statusLabels[purchaseOrder.status] || purchaseOrder.status }}
                        </Badge>
                    </div>
                </Card>

                <!-- Order Summary -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Order Summary</h3></div>
                    <div class="p-5">
                        <dl class="space-y-3">
                            <div class="flex justify-between text-sm">
                                <dt class="text-text-secondary">Subtotal</dt>
                                <dd class="font-medium tabular-nums text-text-primary">{{ formatCurrency(purchaseOrder.subtotal) }}</dd>
                            </div>
                            <div v-if="purchaseOrder.tax > 0" class="flex justify-between text-sm">
                                <dt class="text-text-secondary">Tax</dt>
                                <dd class="font-medium tabular-nums text-text-primary">{{ formatCurrency(purchaseOrder.tax) }}</dd>
                            </div>
                            <div v-if="purchaseOrder.shipping > 0" class="flex justify-between text-sm">
                                <dt class="text-text-secondary">Shipping</dt>
                                <dd class="font-medium tabular-nums text-text-primary">{{ formatCurrency(purchaseOrder.shipping) }}</dd>
                            </div>
                            <div class="border-t border-border-subtle pt-3">
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm font-semibold text-text-primary">Total</dt>
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
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Actions</h3></div>
                    <div class="space-y-3 p-5">
                        <Button
                            v-if="purchaseOrder.status === 'draft'"
                            variant="default"
                            class="w-full"
                            @click="sendToSupplier"
                        >
                            <Send :size="16" />
                            Send to Supplier
                        </Button>
                        <Button
                            v-if="purchaseOrder.status === 'sent' || purchaseOrder.status === 'partial'"
                            variant="default"
                            class="w-full"
                            as="Link"
                            :href="route('purchase-orders.receive', purchaseOrder.id)"
                        >
                            <PackageCheck :size="16" />
                            Receive Items
                        </Button>
                        <Button
                            v-if="purchaseOrder.status === 'draft'"
                            variant="secondary"
                            class="w-full"
                            as="Link"
                            :href="route('purchase-orders.edit', purchaseOrder.id)"
                        >
                            <Pencil :size="16" />
                            Edit Purchase Order
                        </Button>
                        <Button
                            v-if="purchaseOrder.status === 'draft' || purchaseOrder.status === 'sent'"
                            variant="secondary"
                            class="w-full"
                            @click="cancelPO"
                        >
                            <Ban :size="16" />
                            Cancel Order
                        </Button>
                    </div>
                </Card>

                <!-- Danger Zone -->
                <Card v-if="purchaseOrder.status === 'draft'" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Danger Zone</h3></div>
                    <div class="p-5">
                        <Button variant="danger" class="w-full" @click="deletePO">
                            <Trash2 :size="16" />
                            Delete Order
                        </Button>
                        <p class="mt-2 text-xs text-text-tertiary">
                            This action cannot be undone.
                        </p>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
    </AppLayout>
</template>
