<script setup>
import PluginSlot from '@/Components/PluginSlot.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { displayCalendarDate, displayDateTime } from '@/lib/dates';
import { ArrowLeft, Play, CheckCircle2, X, Boxes, PackageCheck, Check, AlertTriangle, Trash2 } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    pluginComponents: Object,
    workOrder: Object,
    items: { type: Array, default: () => [] },
    outputTrackingType: { type: String, default: 'none' },
    allComponentsSufficient: Boolean,
});

const processing = ref(false);
const quantityProduced = ref(props.workOrder.quantity);

const statusSteps = [
    { key: 'draft', label: t('workOrders.statuses.draft') },
    { key: 'pending', label: t('workOrders.statuses.pending') },
    { key: 'in_progress', label: t('workOrders.statuses.in_progress') },
    { key: 'completed', label: t('workOrders.statuses.completed') },
];

const statusOrder = { draft: 0, pending: 1, in_progress: 2, completed: 3, cancelled: -1 };

const isStepCompleted = (stepKey) => {
    if (props.workOrder.status === 'cancelled') return false;
    return statusOrder[stepKey] <= statusOrder[props.workOrder.status];
};

const isStepCurrent = (stepKey) => {
    return stepKey === props.workOrder.status;
};

const statusVariant = (status) =>
    ({
        draft: 'neutral',
        pending: 'warning',
        in_progress: 'info',
        completed: 'success',
        cancelled: 'danger',
    }[status] || 'neutral');

const getStatusLabel = (status) => {
    const labels = {
        'draft': t('workOrders.statuses.draft'),
        'pending': t('workOrders.statuses.pending'),
        'in_progress': t('workOrders.statuses.in_progress'),
        'completed': t('workOrders.statuses.completed'),
        'cancelled': t('workOrders.statuses.cancelled'),
    };
    return labels[status] || status;
};

const required = (item) => Number(item.quantity_required);
const consumed = (item) => Number(item.quantity_consumed || 0);

const componentStatusVariant = (item) => {
    if (consumed(item) >= required(item)) return 'success';
    if (item.is_sufficient) return 'warning';
    return 'danger';
};

const componentStatusLabel = (item) => {
    if (consumed(item) >= required(item)) return t('workOrders.components.consumed');
    if (item.is_sufficient) return t('workOrders.components.ready');
    return t('workOrders.components.insufficient');
};

const trackingLabel = (type) => ({
    serial: t('workOrders.tracking.serial'),
    batch: t('workOrders.tracking.batch'),
}[type] ?? null);

const variantLabel = (variant) => variant ? (variant.title || variant.sku) : null;

const formatDate = (dateStr) => displayDateTime(dateStr);

// Computed, not constants: after an action Inertia reuses this page instance
// with fresh props, so a value read once at setup would keep the old status.
const canStart = computed(() => ['draft', 'pending'].includes(props.workOrder.status));
const canComplete = computed(() => props.workOrder.status === 'in_progress');
const canCancel = computed(() => ['draft', 'pending', 'in_progress'].includes(props.workOrder.status));
const isReadOnly = computed(() => ['completed', 'cancelled'].includes(props.workOrder.status));
// Only orders that never moved stock can be deleted (mirrors WorkOrderController::destroy).
const canDelete = computed(() => ['draft', 'cancelled'].includes(props.workOrder.status));

// ---- Allocation of tracked components and output (completion only) ----

const trackedItems = computed(() => props.items.filter(item => ['serial', 'batch'].includes(item.tracking_type)));

// Units a line will consume for the quantity being produced, mirroring the
// server: ceil(required * produced / planned) minus what is already consumed.
const unitsNeeded = (item) => {
    const produced = Number(quantityProduced.value) || 0;
    return Math.max(0, Math.ceil(required(item) * produced / props.workOrder.quantity) - consumed(item));
};

// item id -> serial ids ticked
const chosenSerials = reactive({});
// item id -> { batch id: quantity }
const chosenBatches = reactive({});

// Start each tracked line with an empty choice, and drop choices that name a
// serial or batch the server no longer offers (taken by another order, or a
// different work order opened in this same page instance).
watch(trackedItems, (items) => {
    const previousSerials = { ...chosenSerials };
    const previousBatches = { ...chosenBatches };
    for (const key of Object.keys(chosenSerials)) delete chosenSerials[key];
    for (const key of Object.keys(chosenBatches)) delete chosenBatches[key];
    for (const item of items) {
        const offeredSerials = new Set((item.available_serials || []).map(serial => serial.id));
        const offeredBatches = new Set((item.available_batches || []).map(batch => String(batch.id)));
        chosenSerials[item.id] = (previousSerials[item.id] || []).filter(id => offeredSerials.has(id));
        chosenBatches[item.id] = Object.fromEntries(
            Object.entries(previousBatches[item.id] || {}).filter(([batchId]) => offeredBatches.has(batchId)),
        );
    }
}, { immediate: true });

watch(() => [props.workOrder.id, props.workOrder.quantity], ([, quantity]) => {
    quantityProduced.value = quantity;
});

const batchTotal = (item) => Object.values(chosenBatches[item.id] || {}).reduce((sum, qty) => sum + (Number(qty) || 0), 0);

const output = reactive({ serialNumbers: '', batchNumber: '', expiryDate: '' });

const outputSerials = computed(() => output.serialNumbers.split('\n').map(s => s.trim()).filter(Boolean));

const allocationPayload = () => trackedItems.value
    .map(item => {
        if (item.tracking_type === 'serial' && chosenSerials[item.id].length > 0) {
            return { work_order_item_id: item.id, serial_ids: chosenSerials[item.id] };
        }
        if (item.tracking_type === 'batch' && batchTotal(item) > 0) {
            const batches = Object.entries(chosenBatches[item.id])
                .filter(([, qty]) => Number(qty) > 0)
                .map(([batchId, qty]) => ({ product_batch_id: Number(batchId), quantity: Number(qty) }));
            return { work_order_item_id: item.id, batches };
        }
        return null;
    })
    .filter(Boolean);

const outputPayload = () => {
    if (props.outputTrackingType === 'serial') {
        return { serial_numbers: outputSerials.value };
    }
    if (props.outputTrackingType === 'batch') {
        return {
            batch_number: output.batchNumber || null,
            expiry_date: output.expiryDate || null,
        };
    }
    return null;
};

const startProduction = () => {
    if (!confirm(t('workOrders.actions.startConfirm'))) return;
    processing.value = true;
    router.post(route('work-orders.start', props.workOrder.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};

const completeProduction = () => {
    if (!confirm(t('workOrders.actions.completeConfirm', { count: quantityProduced.value }, quantityProduced.value))) return;
    processing.value = true;
    const payload = { quantity_produced: quantityProduced.value, allocations: allocationPayload() };
    const out = outputPayload();
    if (out) payload.output = out;
    router.post(route('work-orders.complete', props.workOrder.id), payload, {
        preserveScroll: true,
        onFinish: () => { processing.value = false; },
    });
};

const cancelWorkOrder = () => {
    if (!confirm(t('workOrders.actions.cancelConfirm'))) return;
    processing.value = true;
    router.post(route('work-orders.cancel', props.workOrder.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};

const deleteWorkOrder = () => {
    if (!confirm(t('workOrders.show.deleteConfirm'))) return;
    processing.value = true;
    router.delete(route('work-orders.destroy', props.workOrder.id), {
        onFinish: () => { processing.value = false; },
    });
};

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
const fieldLabel = 'block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
</script>

<template>
    <Head :title="t('workOrders.show.headTitle', { number: workOrder.work_order_number })" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('work-orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('work-orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.workOrders') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ workOrder.work_order_number }}</span>
            </div>
        </template>

        <PageHeader :title="workOrder.work_order_number" :description="t('workOrders.detailsSubtitle')">
            <template #actions>
                <PluginSlot slot="actions" :components="pluginComponents?.actions" />
                <Badge :variant="statusVariant(workOrder.status)" size="sm" dot>{{ getStatusLabel(workOrder.status) }}</Badge>
                <Button
                    v-if="canStart"
                    variant="default"
                    size="sm"
                    :disabled="processing"
                    @click="startProduction"
                >
                    <Play :size="14" />
                    {{ t('workOrders.actions.startProduction') }}
                </Button>
                <Button
                    v-if="canCancel"
                    variant="danger"
                    size="sm"
                    :disabled="processing"
                    @click="cancelWorkOrder"
                >
                    <X :size="14" />
                    {{ t('workOrders.actions.cancelWorkOrder') }}
                </Button>
                <Button
                    v-if="canDelete"
                    variant="danger"
                    size="sm"
                    :disabled="processing"
                    @click="deleteWorkOrder"
                >
                    <Trash2 :size="14" />
                    {{ t('common.delete') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('work-orders.index')">
                    <ArrowLeft :size="14" />
                    {{ t('workOrders.backToWorkOrders') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <!-- Status Timeline -->
        <Card v-if="workOrder.status !== 'cancelled'" :padded="false" class="mt-6">
            <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('workOrders.progress.title') }}</h3></div>
            <div class="p-5">
                <div class="flex items-center justify-between">
                    <template v-for="(step, index) in statusSteps" :key="step.key">
                        <div class="flex items-center">
                            <div
                                class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold transition-colors"
                                :class="isStepCompleted(step.key)
                                    ? 'bg-brand text-brand-foreground'
                                    : 'bg-surface-overlay text-text-tertiary'"
                            >
                                <Check v-if="isStepCompleted(step.key) && !isStepCurrent(step.key)" :size="16" />
                                <span v-else>{{ index + 1 }}</span>
                            </div>
                            <span
                                class="ml-2 hidden text-sm font-medium sm:inline"
                                :class="isStepCurrent(step.key) ? 'text-brand' : isStepCompleted(step.key) ? 'text-text-primary' : 'text-text-tertiary'"
                            >
                                {{ step.label }}
                            </span>
                        </div>
                        <div
                            v-if="index < statusSteps.length - 1"
                            class="mx-4 h-0.5 flex-1"
                            :class="isStepCompleted(statusSteps[index + 1].key) ? 'bg-brand' : 'bg-border-subtle'"
                        ></div>
                    </template>
                </div>
            </div>
        </Card>

        <!-- Cancelled Banner -->
        <Card v-if="workOrder.status === 'cancelled'" class="mt-6 border-status-danger/20 bg-status-danger-soft">
            <div class="flex items-center gap-3">
                <AlertTriangle :size="20" class="shrink-0 text-status-danger" />
                <span class="text-sm font-medium text-status-danger">{{ t('workOrders.cancelledBanner') }}</span>
            </div>
        </Card>

        <!-- Key metrics -->
        <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <StatTile
                :label="t('workOrders.fields.quantityToProduce')"
                :value="workOrder.quantity"
                icon-tone="brand"
            >
                <template #icon><Boxes :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('workOrders.fields.quantityProduced')"
                :value="workOrder.quantity_produced || 0"
                :icon-tone="(workOrder.quantity_produced || 0) >= workOrder.quantity ? 'success' : 'info'"
            >
                <template #icon><PackageCheck :size="18" /></template>
            </StatTile>
        </section>

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Main Content -->
            <div class="space-y-4 lg:col-span-2">
                <!-- Work Order Details -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('workOrders.details') }}</h3></div>
                    <div class="p-5">
                        <dl class="grid grid-cols-2 gap-4">
                            <div>
                                <dt class="mb-1 text-xs text-text-tertiary">{{ t('workOrders.fields.assemblyProduct') }}</dt>
                                <dd>
                                    <Link
                                        v-if="workOrder.product"
                                        :href="route('products.show', workOrder.product.id)"
                                        class="font-medium text-brand hover:underline"
                                    >
                                        {{ workOrder.product.name }}
                                    </Link>
                                    <p v-if="workOrder.product" class="mt-0.5 text-xs text-text-tertiary">SKU: {{ workOrder.product.sku }}</p>
                                </dd>
                            </div>
                            <div>
                                <dt class="mb-1 text-xs text-text-tertiary">{{ t('common.status') }}</dt>
                                <dd>
                                    <Badge :variant="statusVariant(workOrder.status)" size="sm" dot>{{ getStatusLabel(workOrder.status) }}</Badge>
                                </dd>
                            </div>
                            <div v-if="workOrder.variant">
                                <dt class="mb-1 text-xs text-text-tertiary">{{ t('workOrders.fields.outputVariant') }}</dt>
                                <dd class="text-sm text-text-primary">{{ variantLabel(workOrder.variant) }}</dd>
                            </div>
                            <div v-if="trackingLabel(outputTrackingType)">
                                <dt class="mb-1 text-xs text-text-tertiary">{{ t('workOrders.output.title') }}</dt>
                                <dd><Badge variant="neutral" size="sm">{{ trackingLabel(outputTrackingType) }}</Badge></dd>
                            </div>
                            <div>
                                <dt class="mb-1 text-xs text-text-tertiary">{{ t('workOrders.fields.quantityToProduce') }}</dt>
                                <dd class="text-lg font-bold tabular-nums text-text-primary">{{ workOrder.quantity }}</dd>
                            </div>
                            <div>
                                <dt class="mb-1 text-xs text-text-tertiary">{{ t('workOrders.fields.quantityProduced') }}</dt>
                                <dd class="text-lg font-bold tabular-nums" :class="(workOrder.quantity_produced || 0) >= workOrder.quantity ? 'text-status-success' : 'text-text-primary'">
                                    {{ workOrder.quantity_produced || 0 }}
                                </dd>
                            </div>
                            <div v-if="workOrder.warehouse">
                                <dt class="mb-1 text-xs text-text-tertiary">{{ t('workOrders.fields.warehouse') }}</dt>
                                <dd class="text-sm text-text-primary">{{ workOrder.warehouse.name }}</dd>
                            </div>
                            <div v-if="workOrder.notes">
                                <dt class="mb-1 text-xs text-text-tertiary">{{ t('workOrders.fields.notes') }}</dt>
                                <dd class="text-sm text-text-primary">{{ workOrder.notes }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Components Table -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('workOrders.components.title') }}</h3></div>
                    <div class="p-5">
                        <div class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                            <table class="min-w-full">
                                <thead>
                                    <tr class="border-b border-border-subtle">
                                        <th :class="thClass">{{ t('workOrders.components.component') }}</th>
                                        <th :class="thClass">{{ t('workOrders.components.sku') }}</th>
                                        <th :class="[thClass, 'text-center']">{{ t('workOrders.components.requiredQty') }}</th>
                                        <th :class="[thClass, 'text-center']">{{ t('workOrders.components.consumed') }}</th>
                                        <th :class="[thClass, 'text-center']">{{ t('workOrders.components.available') }}</th>
                                        <th :class="[thClass, 'text-center']">{{ t('workOrders.components.status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in items" :key="item.id" class="border-b border-border-subtle align-top transition-colors last:border-b-0 hover:bg-surface-overlay">
                                        <td class="px-4 py-3">
                                            <Link
                                                v-if="item.product"
                                                :href="route('products.show', item.product.id)"
                                                class="text-sm font-medium text-brand hover:underline"
                                            >
                                                {{ item.product.name }}
                                            </Link>
                                            <span v-else class="text-sm text-text-tertiary">{{ t('workOrders.components.unknown') }}</span>
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                <Badge v-if="item.variant" variant="brand" size="sm">{{ variantLabel(item.variant) }}</Badge>
                                                <Badge v-if="item.kit" variant="info" size="sm">{{ t('workOrders.components.fromKit', { name: item.kit.name }) }}</Badge>
                                                <Badge v-if="trackingLabel(item.tracking_type)" variant="neutral" size="sm">{{ trackingLabel(item.tracking_type) }}</Badge>
                                            </div>
                                            <p v-if="item.allocations && item.allocations.length" class="mt-1 text-xs text-text-tertiary">
                                                {{ t('workOrders.allocation.used') }}:
                                                <template v-for="(allocation, index) in item.allocations" :key="allocation.id">
                                                    <span v-if="index > 0">, </span>
                                                    <span v-if="allocation.serial">{{ allocation.serial.serial_number }}</span>
                                                    <span v-else-if="allocation.batch">{{ allocation.batch.batch_number }} &times; {{ allocation.quantity }}</span>
                                                </template>
                                            </p>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-text-tertiary">
                                            {{ item.variant?.sku || item.product?.sku || '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-center text-sm font-medium tabular-nums text-text-primary">
                                            {{ required(item) }}
                                        </td>
                                        <td class="px-4 py-3 text-center text-sm tabular-nums text-text-primary">
                                            {{ consumed(item) }}
                                        </td>
                                        <td class="px-4 py-3 text-center text-sm tabular-nums" :class="item.is_sufficient ? 'text-status-success' : 'text-status-danger'">
                                            {{ item.available_stock }}
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <Badge :variant="componentStatusVariant(item)" size="sm">
                                                {{ componentStatusLabel(item) }}
                                            </Badge>
                                        </td>
                                    </tr>

                                    <tr v-if="items.length === 0">
                                        <td colspan="6" class="px-4 py-8 text-center text-sm text-text-tertiary">
                                            {{ t('workOrders.components.noComponents') }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </Card>

                <!-- Output (after completion) -->
                <Card v-if="workOrder.outputs && workOrder.outputs.length" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('workOrders.output.produced') }}</h3></div>
                    <div class="p-5">
                        <ul class="flex flex-wrap gap-2">
                            <li v-for="row in workOrder.outputs" :key="row.id">
                                <Badge variant="success" size="md">
                                    <template v-if="row.serial">{{ row.serial.serial_number }}</template>
                                    <template v-else-if="row.batch">{{ row.batch.batch_number }} &times; {{ row.quantity }}</template>
                                    <template v-else-if="row.variant">{{ variantLabel(row.variant) }} &times; {{ row.quantity }}</template>
                                    <template v-else>{{ workOrder.product?.name }} &times; {{ row.quantity }}</template>
                                </Badge>
                            </li>
                        </ul>
                    </div>
                </Card>
            </div>

            <!-- Sidebar -->
            <div class="space-y-4">
                <!-- Complete Production -->
                <Card v-if="!isReadOnly && canComplete" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('workOrders.actions.completeProduction') }}</h3></div>
                    <div class="space-y-5 p-5">
                        <div class="space-y-2">
                            <label for="quantity_produced" :class="fieldLabel">
                                {{ t('workOrders.fields.quantityProduced') }}
                            </label>
                            <input
                                id="quantity_produced"
                                v-model.number="quantityProduced"
                                type="number"
                                min="1"
                                :max="workOrder.quantity"
                                :class="fieldInput"
                            />
                        </div>

                        <!-- Serials / batches to consume -->
                        <div v-for="item in trackedItems" :key="item.id" class="space-y-2 border-t border-border-subtle pt-4">
                            <p class="text-sm font-medium text-text-primary">{{ item.product?.name }}</p>

                            <template v-if="item.tracking_type === 'serial'">
                                <p class="text-xs text-text-tertiary">
                                    {{ t('workOrders.allocation.serialsHint', { count: unitsNeeded(item) }) }}
                                </p>
                                <p class="text-xs font-medium" :class="chosenSerials[item.id].length === 0 || chosenSerials[item.id].length === unitsNeeded(item) ? 'text-text-secondary' : 'text-status-danger'">
                                    {{ t('common.selected', { count: chosenSerials[item.id].length }) }}
                                </p>
                                <div class="max-h-48 space-y-1 overflow-y-auto rounded-md border border-border-subtle p-2">
                                    <label v-for="serial in item.available_serials" :key="serial.id" class="flex items-center gap-2 text-sm text-text-primary">
                                        <input v-model="chosenSerials[item.id]" type="checkbox" :value="serial.id" class="rounded border-border-subtle text-brand ds-focus-ring" />
                                        <span class="font-mono text-xs">{{ serial.serial_number }}</span>
                                    </label>
                                    <p v-if="item.available_serials.length === 0" class="text-xs text-status-danger">{{ t('workOrders.allocation.noneAvailable') }}</p>
                                </div>
                            </template>

                            <template v-else>
                                <p class="text-xs text-text-tertiary">
                                    {{ t('workOrders.allocation.batchesHint', { count: unitsNeeded(item) }) }}
                                </p>
                                <p v-if="batchTotal(item) > 0" class="text-xs font-medium" :class="batchTotal(item) === unitsNeeded(item) ? 'text-text-secondary' : 'text-status-danger'">
                                    {{ t('workOrders.allocation.batchTotal', { chosen: batchTotal(item), count: unitsNeeded(item) }) }}
                                </p>
                                <div class="max-h-48 space-y-2 overflow-y-auto rounded-md border border-border-subtle p-2">
                                    <div v-for="batch in item.available_batches" :key="batch.id" class="flex items-center gap-2">
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate font-mono text-xs text-text-primary">{{ batch.batch_number }}</p>
                                            <p class="text-xs text-text-tertiary">
                                                {{ t('workOrders.allocation.available', { count: batch.quantity }) }}
                                                &middot;
                                                {{ batch.expiry_date ? t('workOrders.allocation.expires', { date: displayCalendarDate(batch.expiry_date) }) : t('workOrders.allocation.noExpiry') }}
                                            </p>
                                        </div>
                                        <input
                                            v-model.number="chosenBatches[item.id][batch.id]"
                                            type="number"
                                            min="0"
                                            :max="batch.quantity"
                                            :aria-label="t('workOrders.allocation.takeFrom', { batch: batch.batch_number })"
                                            class="h-8 w-20 rounded-md border border-border-subtle bg-surface-canvas px-2 text-sm text-text-primary ds-focus-ring"
                                        />
                                    </div>
                                    <p v-if="item.available_batches.length === 0" class="text-xs text-status-danger">{{ t('workOrders.allocation.noneAvailable') }}</p>
                                </div>
                            </template>
                        </div>

                        <!-- Tracked output -->
                        <div v-if="outputTrackingType === 'serial'" class="space-y-2 border-t border-border-subtle pt-4">
                            <label for="output_serials" :class="fieldLabel">{{ t('workOrders.output.serialNumbers') }}</label>
                            <textarea
                                id="output_serials"
                                v-model="output.serialNumbers"
                                rows="4"
                                :class="[fieldArea, 'font-mono']"
                            ></textarea>
                            <p class="text-xs" :class="outputSerials.length === Number(quantityProduced) ? 'text-text-tertiary' : 'text-status-danger'">
                                {{ t('workOrders.output.serialNumbersHint', { count: quantityProduced, entered: outputSerials.length }) }}
                            </p>
                        </div>
                        <div v-if="outputTrackingType === 'batch'" class="space-y-3 border-t border-border-subtle pt-4">
                            <div class="space-y-1">
                                <label for="output_batch" :class="fieldLabel">{{ t('workOrders.output.batchNumber') }}</label>
                                <input id="output_batch" v-model="output.batchNumber" type="text" :class="fieldInput" />
                                <p class="text-xs text-text-tertiary">{{ t('workOrders.output.batchNumberHint') }}</p>
                            </div>
                            <div class="space-y-1">
                                <label for="output_expiry" :class="fieldLabel">{{ t('workOrders.output.expiryDate') }}</label>
                                <input id="output_expiry" v-model="output.expiryDate" type="date" :class="fieldInput" />
                            </div>
                        </div>

                        <Button
                            variant="default"
                            class="w-full"
                            :disabled="processing"
                            @click="completeProduction"
                        >
                            <CheckCircle2 :size="16" />
                            {{ t('workOrders.actions.completeProduction') }}
                        </Button>
                    </div>
                </Card>

                <!-- Dates / Info -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('workOrders.info.title') }}</h3></div>
                    <div class="p-5">
                        <dl class="space-y-3">
                            <div>
                                <dt class="mb-1 text-xs text-text-tertiary">{{ t('workOrders.info.createdBy') }}</dt>
                                <dd class="text-sm text-text-primary">{{ workOrder.creator?.name || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="mb-1 text-xs text-text-tertiary">{{ t('workOrders.info.created') }}</dt>
                                <dd class="text-sm text-text-primary">{{ formatDate(workOrder.created_at) }}</dd>
                            </div>
                            <div v-if="workOrder.started_at">
                                <dt class="mb-1 text-xs text-text-tertiary">{{ t('workOrders.info.productionStarted') }}</dt>
                                <dd class="text-sm text-text-primary">{{ formatDate(workOrder.started_at) }}</dd>
                            </div>
                            <div v-if="workOrder.completed_at">
                                <dt class="mb-1 text-xs text-text-tertiary">{{ t('workOrders.info.completed') }}</dt>
                                <dd class="text-sm text-text-primary">{{ formatDate(workOrder.completed_at) }}</dd>
                            </div>
                            <div>
                                <dt class="mb-1 text-xs text-text-tertiary">{{ t('workOrders.info.lastUpdated') }}</dt>
                                <dd class="text-sm text-text-primary">{{ formatDate(workOrder.updated_at) }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Danger Zone -->
                <Card v-if="canCancel" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('workOrders.show.dangerZone') }}</h3></div>
                    <div class="p-5">
                        <Button variant="danger" class="w-full" :disabled="processing" @click="cancelWorkOrder">
                            <X :size="16" />
                            {{ t('workOrders.actions.cancelWorkOrder') }}
                        </Button>
                        <p class="mt-2 text-xs text-text-tertiary">
                            {{ t('workOrders.show.releaseHint') }}
                        </p>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
    </AppLayout>
</template>
