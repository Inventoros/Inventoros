<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { defineAsyncComponent, ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, ScanLine, PackageCheck } from '@lucide/vue';

const BarcodeScannerModal = defineAsyncComponent(() => import('@/Components/BarcodeScannerModal.vue'));

const { t } = useI18n();

const props = defineProps({
    purchaseOrder: Object,
    pluginComponents: Object,
});

const showScanner = ref(false);

const form = useForm({
    items: props.purchaseOrder.items?.map(item => ({
        id: item.id,
        product_id: item.product_id,
        product_name: item.product_name,
        sku: item.sku,
        quantity_ordered: item.quantity_ordered,
        quantity_received: item.quantity_received,
        remaining: item.quantity_ordered - item.quantity_received,
        quantity_to_receive: 0,
    })) || [],
});

const hasItemsToReceive = computed(() => {
    return form.items.some(item => item.quantity_to_receive > 0);
});

const totalItemsToReceive = computed(() => {
    return form.items.reduce((sum, item) => sum + item.quantity_to_receive, 0);
});

const receiveAll = (index) => {
    form.items[index].quantity_to_receive = form.items[index].remaining;
};

const receiveAllItems = () => {
    form.items.forEach(item => {
        item.quantity_to_receive = item.remaining;
    });
};

const clearAll = () => {
    form.items.forEach(item => {
        item.quantity_to_receive = 0;
    });
};

const onProductFound = (product) => {
    // Find the item in the list and increment its receive quantity
    const itemIndex = form.items.findIndex(item => item.product_id === product.id);
    if (itemIndex >= 0) {
        const item = form.items[itemIndex];
        if (item.quantity_to_receive < item.remaining) {
            item.quantity_to_receive++;
        }
    }
    showScanner.value = false;
};

const submit = () => {
    form.post(route('purchase-orders.process-receiving', props.purchaseOrder.id));
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: props.purchaseOrder.currency || 'USD',
    }).format(value || 0);
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

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
const thClassCenter = 'px-4 py-2.5 text-center text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="`Receive - ${purchaseOrder.po_number}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('purchase-orders.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('purchase-orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('purchaseOrders.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('purchase-orders.show', purchaseOrder.id)" class="text-text-tertiary hover:text-text-primary">{{ purchaseOrder.po_number }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">Receive</span>
            </div>
        </template>

        <PageHeader
            :title="`Receive Items - ${purchaseOrder.po_number}`"
            description="Record received quantities against this purchase order."
        >
            <template #actions>
                <Button variant="secondary" size="sm" @click="showScanner = true">
                    <ScanLine :size="14" />
                    Scan Barcode
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('purchase-orders.show', purchaseOrder.id)">
                    <ArrowLeft :size="14" />
                    Back
                </Button>
            </template>
        </PageHeader>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <!-- Order Summary -->
        <Card :padded="false" class="mt-6">
            <div class="flex flex-wrap items-center justify-between gap-4 p-5">
                <div>
                    <h3 class="text-sm font-semibold text-text-primary">
                        {{ purchaseOrder.supplier?.name }}
                    </h3>
                    <p class="mt-1 text-sm text-text-secondary">
                        Order Total: {{ formatCurrency(purchaseOrder.total) }}
                    </p>
                </div>
                <Badge :variant="statusVariant(purchaseOrder.status)" size="md" dot>
                    {{ statusLabels[purchaseOrder.status] || purchaseOrder.status }}
                </Badge>
            </div>
        </Card>

        <!-- Receiving Form -->
        <form @submit.prevent="submit" class="mt-4">
            <Card :padded="false">
                <div class="flex items-center justify-between px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">Items to Receive</h3>
                    <div class="flex items-center gap-2">
                        <Button type="button" variant="default" size="sm" @click="receiveAllItems">
                            Receive All
                        </Button>
                        <Button type="button" variant="secondary" size="sm" @click="clearAll">
                            Clear
                        </Button>
                    </div>
                </div>
                <div class="p-5">
                    <div class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border-subtle">
                                    <th :class="thClass">Product</th>
                                    <th :class="thClass">SKU</th>
                                    <th :class="thClassCenter">Ordered</th>
                                    <th :class="thClassCenter">Already Received</th>
                                    <th :class="thClassCenter">Remaining</th>
                                    <th :class="thClassCenter">Receive Now</th>
                                    <th :class="thClassCenter">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(item, index) in form.items"
                                    :key="item.id"
                                    class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                                    :class="{ 'bg-status-success-soft': item.remaining === 0 }"
                                >
                                    <td class="px-4 py-3 text-sm text-text-primary">
                                        {{ item.product_name }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-text-tertiary">
                                        {{ item.sku || '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-center text-sm tabular-nums text-text-primary">
                                        {{ item.quantity_ordered }}
                                    </td>
                                    <td class="px-4 py-3 text-center text-sm tabular-nums">
                                        <span :class="[
                                            item.quantity_received >= item.quantity_ordered
                                                ? 'font-medium text-status-success'
                                                : item.quantity_received > 0
                                                    ? 'text-status-warning'
                                                    : 'text-text-tertiary'
                                        ]">
                                            {{ item.quantity_received }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-center text-sm tabular-nums">
                                        <span :class="[
                                            item.remaining === 0
                                                ? 'font-medium text-status-success'
                                                : 'font-medium text-status-warning'
                                        ]">
                                            {{ item.remaining }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <input
                                            v-if="item.remaining > 0"
                                            type="number"
                                            v-model.number="item.quantity_to_receive"
                                            :max="item.remaining"
                                            min="0"
                                            :class="[fieldInput, 'w-20 text-center']"
                                        />
                                        <Badge v-else variant="success" size="sm">
                                            Complete
                                        </Badge>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <Button
                                            v-if="item.remaining > 0"
                                            type="button"
                                            variant="link"
                                            size="sm"
                                            @click="receiveAll(index)"
                                        >
                                            Receive All
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="form.errors.items" :class="fieldError">{{ form.errors.items }}</p>
                </div>

                <!-- Summary & Submit -->
                <div class="flex items-center justify-between border-t border-border-subtle px-5 py-4">
                    <div class="text-sm text-text-secondary">
                        <span v-if="hasItemsToReceive">
                            Ready to receive <strong class="text-text-primary">{{ totalItemsToReceive }}</strong> items
                        </span>
                        <span v-else>
                            Select quantities to receive
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                        <Button variant="secondary" as="Link" :href="route('purchase-orders.show', purchaseOrder.id)">
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="default"
                            :loading="form.processing"
                            :disabled="form.processing || !hasItemsToReceive"
                        >
                            <PackageCheck :size="16" />
                            Receive
                        </Button>
                    </div>
                </div>
            </Card>
        </form>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />

        <!-- Barcode Scanner Modal -->
        <BarcodeScannerModal
            :show="showScanner"
            @close="showScanner = false"
            @product-found="onProductFound"
        />
    </AppLayout>
</template>

