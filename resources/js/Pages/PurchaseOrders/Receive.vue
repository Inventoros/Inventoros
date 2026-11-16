<script setup>
import TableViewport from '@/Components/ui/TableViewport.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { defineAsyncComponent, ref, computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatMoney } from '@/lib/money';
import { ArrowLeft, ScanLine, PackageCheck } from '@lucide/vue';

const BarcodeScannerModal = defineAsyncComponent(() => import('@/Components/BarcodeScannerModal.vue'));

const { t } = useI18n();

const props = defineProps({
    purchaseOrder: Object,
    pluginComponents: Object,
    receivingLocations: { type: Array, default: () => [] },
});

const showScanner = ref(false);
const tableId = `receiving-${useId()}`;
const fieldId = (part, item) => `${tableId}-${part}-${item.id}`;

const form = useForm({
    items: props.purchaseOrder.items?.map(item => ({
        id: item.id,
        product_id: item.product_id,
        product_variant_id: item.product_variant_id ?? null,
        variant_title: item.variant?.title ?? null,
        product_name: item.product_name,
        sku: item.sku,
        receiving_location: item.receiving_location,
        receiving_location_id: null,
        quantity_ordered: item.quantity_ordered,
        quantity_received: item.quantity_received,
        remaining: item.quantity_ordered - item.quantity_received,
        quantity_to_receive: 0,
    })) || [],
});

const hasItemsToReceive = computed(() => {
    return form.items.some(item => item.quantity_to_receive > 0);
});

const receivingLocationsFor = (item) => {
    const warehouseId = item.receiving_location?.warehouse_id;
    return warehouseId == null
        ? props.receivingLocations
        : props.receivingLocations.filter(location => Number(location.warehouse_id) === Number(warehouseId));
};

const receivingLocationLabel = (location) => [
    location.warehouse_name,
    [location.code, location.name].filter(Boolean).join(' / '),
].filter(Boolean).join(' / ');

const totalItemsToReceive = computed(() => {
    return form.items.reduce((sum, item) => sum + Math.max(0, Number(item.quantity_to_receive) || 0), 0);
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
    if (form.processing) return;
    form.transform(data => ({
        ...data,
        items: data.items.map(item => ({
            ...item,
            // A cleared field means this line is skipped in this receipt.
            quantity_to_receive: item.quantity_to_receive === '' ? 0 : item.quantity_to_receive,
        })),
    })).post(route('purchase-orders.process-receiving', props.purchaseOrder.id));
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

const statusLabels = computed(() => ({
    draft: t('purchaseOrders.status.draft'),
    sent: t('purchaseOrders.status.sent'),
    partial: t('purchaseOrders.status.partial'),
    received: t('purchaseOrders.status.received'),
    cancelled: t('purchaseOrders.status.cancelled'),
}));

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';

const thClass = 'px-4 py-2.5 text-start text-xs font-medium tracking-tight text-text-secondary';
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
                <Button variant="secondary" size="sm" :disabled="form.processing" @click="showScanner = true">
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
                <div class="receiving-toolbar flex flex-wrap items-center justify-between gap-3 px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('purchaseOrders.receive.itemsToReceive') }}</h3>
                    <div class="flex items-center gap-2">
                        <Button type="button" variant="secondary" size="sm" class="min-h-11" :disabled="form.processing" @click="receiveAllItems">
                            {{ t('purchaseOrders.receive.fillAll') }}
                        </Button>
                        <Button type="button" variant="secondary" size="sm" class="min-h-11" :disabled="form.processing" @click="clearAll">
                            {{ t('common.clear') }}
                        </Button>
                    </div>
                </div>
                <div class="receiving-items p-5">
                    <p class="mb-3 text-xs text-text-secondary">{{ t('receivingDestination.hint') }}</p>
                    <TableViewport :label="t('purchaseOrders.receive.itemsToReceive')" class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                        <table role="table" :aria-label="t('purchaseOrders.receive.itemsToReceive')" class="receiving-table w-full text-sm">
                            <thead role="rowgroup">
                                <tr role="row" class="border-b border-border-subtle">
                                    <th role="columnheader" scope="col" :class="thClass">{{ t('common.product') }}</th>
                                    <th role="columnheader" scope="col" :class="thClass">SKU</th>
                                    <th role="columnheader" scope="col" :class="thClassCenter">{{ t('purchaseOrders.show.ordered') }}</th>
                                    <th role="columnheader" scope="col" :class="thClassCenter">{{ t('purchaseOrders.receive.alreadyReceived') }}</th>
                                    <th role="columnheader" scope="col" :class="thClassCenter">{{ t('purchaseOrders.receive.remaining') }}</th>
                                    <th role="columnheader" scope="col" :class="thClassCenter">{{ t('purchaseOrders.receive.receiveNow') }}</th>
                                    <th role="columnheader" scope="col" :class="thClass">{{ t('receivingDestination.label') }}</th>
                                    <th role="columnheader" scope="col" :class="thClassCenter">{{ t('common.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup">
                                <tr role="row"
                                    v-for="(item, index) in form.items"
                                    :key="item.id"
                                    class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                                    :class="{ 'bg-status-success-soft': item.remaining === 0 }"
                                >
                                    <td role="cell" :id="fieldId('product', item)" class="receiving-product min-w-[12rem] px-4 py-3 text-sm text-text-primary">
                                        {{ item.product_name }}
                                        <span v-if="item.receiving_location" class="block text-xs text-text-secondary">{{ t('variantReplenishment.receiveAt', { location: item.receiving_location.name, warehouse: item.receiving_location.warehouse?.name }) }}</span>
                                        <span v-if="item.variant_title" class="block text-xs text-text-secondary">{{ t('orders.create.variant') }}: {{ item.variant_title }}</span>
                                    </td>
                                    <td role="cell" class="receiving-sku whitespace-nowrap px-4 py-3 text-sm text-text-tertiary">
                                        {{ item.sku || '-' }}
                                    </td>
                                    <td role="cell" class="receiving-count px-4 py-3 text-center text-sm tabular-nums text-text-primary">
                                        <span class="receiving-label" aria-hidden="true">{{ t('purchaseOrders.show.ordered') }}</span>
                                        <span class="receiving-count-value">{{ item.quantity_ordered }}</span>
                                    </td>
                                    <td role="cell" class="receiving-count px-4 py-3 text-center text-sm tabular-nums">
                                        <span class="receiving-label" aria-hidden="true">{{ t('purchaseOrders.receive.alreadyReceived') }}</span>
                                        <span class="receiving-count-value" :class="[
                                            item.quantity_received >= item.quantity_ordered
                                                ? 'font-medium text-status-success'
                                                : item.quantity_received > 0
                                                    ? 'text-status-warning'
                                                    : 'text-text-tertiary'
                                        ]">
                                            {{ item.quantity_received }}
                                        </span>
                                    </td>
                                    <td role="cell" class="receiving-count px-4 py-3 text-center text-sm tabular-nums">
                                        <span class="receiving-label" aria-hidden="true">{{ t('purchaseOrders.receive.remaining') }}</span>
                                        <span class="receiving-count-value" :class="[
                                            item.remaining === 0
                                                ? 'font-medium text-status-success'
                                                : 'font-medium text-status-warning'
                                        ]">
                                            {{ item.remaining }}
                                        </span>
                                    </td>
                                    <td role="cell" class="receiving-quantity px-4 py-3 text-center">
                                        <label v-if="item.remaining > 0" :for="fieldId('quantity', item)" class="receiving-label">{{ t('purchaseOrders.receive.receiveNow') }}</label>
                                        <input :aria-label="t('purchaseOrders.receive.receiveNow')"
                                            v-if="item.remaining > 0"
                                            :id="fieldId('quantity', item)"
                                            type="number"
                                            v-model.number="item.quantity_to_receive"
                                            :max="item.remaining"
                                            min="0"
                                            step="1"
                                            :disabled="form.processing"
                                            :aria-invalid="!!form.errors[`items.${index}.quantity_to_receive`]"
                                            :aria-describedby="[fieldId('product', item), form.errors[`items.${index}.quantity_to_receive`] && fieldId('quantity-error', item)].filter(Boolean).join(' ')"
                                            :class="[fieldInput, 'receiving-input w-20 text-center']"
                                        />
                                        <p v-if="form.errors[`items.${index}.quantity_to_receive`]" :id="fieldId('quantity-error', item)" :class="fieldError">{{ form.errors[`items.${index}.quantity_to_receive`] }}</p>
                                        <Badge v-if="item.remaining <= 0" variant="success" size="sm">
                                            {{ t('purchaseOrders.receive.complete') }}
                                        </Badge>
                                    </td>
                                    <td role="cell" class="receiving-destination min-w-[15rem] px-4 py-3">
                                        <template v-if="item.remaining > 0">
                                            <label :for="fieldId('location', item)" class="receiving-label">{{ t('receivingDestination.label') }}</label>
                                            <select
                                                :id="fieldId('location', item)"
                                                v-model="item.receiving_location_id"
                                                :aria-label="t('receivingDestination.label')"
                                                :aria-invalid="!!form.errors[`items.${index}.receiving_location_id`]"
                                                :aria-describedby="[fieldId('product', item), form.errors[`items.${index}.receiving_location_id`] && fieldId('location-error', item)].filter(Boolean).join(' ')"
                                                :class="[fieldInput, 'receiving-input']"
                                                :disabled="form.processing"
                                            >
                                                <option :value="null">{{ t('receivingDestination.existing') }}</option>
                                                <option v-for="location in receivingLocationsFor(item)" :key="location.id" :value="location.id">
                                                    {{ receivingLocationLabel(location) }}
                                                </option>
                                            </select>
                                            <p v-if="receivingLocationsFor(item).length === 0" class="mt-1 text-xs text-text-tertiary">{{ t('receivingDestination.empty') }}</p>
                                            <p v-if="form.errors[`items.${index}.receiving_location_id`]" :id="fieldId('location-error', item)" :class="fieldError">{{ form.errors[`items.${index}.receiving_location_id`] }}</p>
                                        </template>
                                        <span v-else class="text-text-tertiary">-</span>
                                    </td>
                                    <td role="cell" class="receiving-line-actions px-4 py-3 text-center">
                                        <Button
                                            v-if="item.remaining > 0"
                                            type="button"
                                            variant="link"
                                            size="sm"
                                            :disabled="form.processing"
                                            @click="receiveAll(index)"
                                        >
                                            {{ t('purchaseOrders.receive.fillRemaining') }}
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </TableViewport>
                    <p v-if="form.errors.items" :class="fieldError">{{ form.errors.items }}</p>
                </div>

                <!-- Summary & Submit -->
                <div class="receiving-summary flex flex-col items-stretch justify-between gap-4 border-t border-border-subtle px-5 py-4 sm:flex-row sm:flex-wrap sm:items-center">
                    <div class="min-w-0 text-sm text-text-secondary" role="status">
                        <span v-if="hasItemsToReceive">
                            {{ t('purchaseOrders.receive.readyToReceiveCount', { count: totalItemsToReceive }, totalItemsToReceive) }}
                        </span>
                        <span v-else>
                            {{ t('purchaseOrders.receive.selectQuantities') }}
                        </span>
                    </div>
                    <div class="flex min-w-0 flex-col gap-3 sm:flex-row">
                        <Button variant="secondary" class="h-auto min-h-11 whitespace-normal" as="Link" :href="route('purchase-orders.show', purchaseOrder.id)">
                            {{ t('common.cancel') }}
                        </Button>
                        <Button
                            type="submit"
                            variant="default"
                            class="h-auto min-h-11 whitespace-normal"
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

<style scoped>
.receiving-items { container: receiving-items / inline-size; }
.receiving-label { display: none; }
.receiving-input[type="number"] { width: 6rem; min-width: 6rem; }

/* One table and one set of inputs. On narrow panels, each row becomes a
   receiving record while its column headers remain available to readers. */
@container receiving-items (max-width: 52rem) {
    .receiving-items :deep(.table-panel) { border: 0; border-radius: 0; }
    .receiving-items :deep(.table-viewport) { max-height: none; overflow: visible; scrollbar-gutter: auto; }
    .receiving-items :deep(.table-expand-button) { display: none; }
    .receiving-table, .receiving-table tbody { display: block; width: 100%; }
    .receiving-table thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip-path: inset(50%);
        white-space: nowrap;
    }
    .receiving-table tbody { display: grid; gap: 16px; }
    .receiving-table tbody tr {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        overflow: hidden;
        border: 1px solid hsl(var(--border-subtle));
        border-radius: 8px;
    }
    .receiving-table tbody td {
        min-width: 0;
        padding: 12px;
        text-align: start;
        overflow-wrap: anywhere;
        white-space: normal;
    }
    .receiving-product, .receiving-sku, .receiving-quantity, .receiving-destination, .receiving-line-actions { grid-column: 1 / -1; }
    .receiving-table .receiving-product { padding-bottom: 4px; font-weight: 600; }
    .receiving-table .receiving-product span { font-weight: 400; }
    .receiving-table .receiving-sku { padding-top: 0; font-family: ui-monospace, monospace; font-size: 12px; border-bottom: 1px solid hsl(var(--border-subtle)); }
    .receiving-label { display: block; margin-bottom: 6px; color: hsl(var(--text-secondary)); font-size: 12px; line-height: 16px; }
    .receiving-count .receiving-label { min-height: 32px; }
    .receiving-count-value { font-size: 20px; font-weight: 600; line-height: 28px; }
    .receiving-table .receiving-input { width: 100%; min-height: 44px; }
    .receiving-line-actions :deep(button) { width: 100%; min-height: 44px; white-space: normal; }
    .receiving-table .receiving-destination:has(> span:only-child),
    .receiving-table .receiving-line-actions:empty { display: none; }
}
</style>
