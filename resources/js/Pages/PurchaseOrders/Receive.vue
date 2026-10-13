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
import { formatMoney } from '@/lib/money';
import { ArrowLeft, ScanLine, PackageCheck } from 'lucide-vue-next';

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
        product_variant_id: item.product_variant_id ?? null,
        variant_title: item.variant?.title ?? null,
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

const onProductFound = (product, variant = null) => {
    // Find the item in the list and increment its receive quantity. A scanned
    // variant barcode matches that variant's line; a product code matches the
    // first line for the product that still has units to receive.
    const itemIndex = variant
        ? form.items.findIndex(item => item.product_variant_id === variant.id)
        : form.items.findIndex(item => item.product_id === product.id && item.quantity_to_receive < item.remaining);
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
    return formatMoney(value, props.purchaseOrder.currency);
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
    draft: t('purchaseOrders.status.draft'),
    sent: t('purchaseOrders.status.sent'),
    partial: t('purchaseOrders.status.partial'),
    received: t('purchaseOrders.status.received'),
    cancelled: t('purchaseOrders.status.cancelled'),
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
const thClassCenter = 'px-4 py-2.5 text-center text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('purchaseOrders.receive.headTitle', { poNumber: purchaseOrder.po_number })" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('purchase-orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('purchase-orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('purchaseOrders.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('purchase-orders.show', purchaseOrder.id)" class="text-text-tertiary hover:text-text-primary">{{ purchaseOrder.po_number }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('nav.receive') }}</span>
            </div>
        </template>

        <PageHeader
            :title="t('purchaseOrders.receive.title', { poNumber: purchaseOrder.po_number })"
            :description="t('purchaseOrders.receive.description')"
        >
            <template #actions>
                <Button variant="secondary" size="sm" @click="showScanner = true">
                    <ScanLine :size="14" />
                    {{ t('components.barcodeScanner.title') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('purchase-orders.show', purchaseOrder.id)">
                    <ArrowLeft :size="14" />
                    {{ t('common.back') }}
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
                        {{ t('purchaseOrders.receive.orderTotal', { total: formatCurrency(purchaseOrder.total) }) }}
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
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('purchaseOrders.receive.itemsToReceive') }}</h3>
                    <div class="flex items-center gap-2">
                        <Button type="button" variant="default" size="sm" @click="receiveAllItems">
                            {{ t('purchaseOrders.receive.receiveAll') }}
                        </Button>
                        <Button type="button" variant="secondary" size="sm" @click="clearAll">
                            {{ t('common.clear') }}
                        </Button>
                    </div>
                </div>
                <div class="p-5">
                    <div class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border-subtle">
                                    <th :class="thClass">{{ t('common.product') }}</th>
                                    <th :class="thClass">SKU</th>
                                    <th :class="thClassCenter">{{ t('purchaseOrders.show.ordered') }}</th>
                                    <th :class="thClassCenter">{{ t('purchaseOrders.receive.alreadyReceived') }}</th>
                                    <th :class="thClassCenter">{{ t('purchaseOrders.receive.remaining') }}</th>
                                    <th :class="thClassCenter">{{ t('purchaseOrders.receive.receiveNow') }}</th>
                                    <th :class="thClassCenter">{{ t('common.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(item, index) in form.items"
                                    :key="item.id"
                                    class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                                    :class="{ 'bg-status-success-soft': item.remaining === 0 }"
                                >
                                    <td class="min-w-[12rem] px-4 py-3 text-sm text-text-primary">
                                        {{ item.product_name }}
                                        <span v-if="item.variant_title" class="block text-xs text-text-secondary">{{ t('orders.create.variant') }}: {{ item.variant_title }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-text-tertiary">
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
                                            {{ t('purchaseOrders.receive.complete') }}
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
                                            {{ t('purchaseOrders.receive.receiveAll') }}
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
                            {{ t('purchaseOrders.receive.readyToReceiveCount', { count: totalItemsToReceive }, totalItemsToReceive) }}
                        </span>
                        <span v-else>
                            {{ t('purchaseOrders.receive.selectQuantities') }}
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                        <Button variant="secondary" as="Link" :href="route('purchase-orders.show', purchaseOrder.id)">
                            {{ t('common.cancel') }}
                        </Button>
                        <Button
                            type="submit"
                            variant="default"
                            :loading="form.processing"
                            :disabled="form.processing || !hasItemsToReceive"
                        >
                            <PackageCheck :size="16" />
                            {{ t('purchaseOrders.receive.receive') }}
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
