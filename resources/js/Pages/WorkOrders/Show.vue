<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Play, CheckCircle2, X, Boxes, PackageCheck, Check, AlertTriangle } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    workOrder: Object,
});

const processing = ref(false);
const quantityProduced = ref(props.workOrder.quantity);

const statusSteps = [
    { key: 'draft', label: 'Draft' },
    { key: 'pending', label: 'Pending' },
    { key: 'in_progress', label: 'In Progress' },
    { key: 'completed', label: 'Completed' },
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
        'draft': 'Draft',
        'pending': 'Pending',
        'in_progress': 'In Progress',
        'completed': 'Completed',
        'cancelled': 'Cancelled',
    };
    return labels[status] || status;
};

const componentStatusVariant = (comp) => {
    if ((comp.quantity_consumed || 0) >= comp.quantity_required) return 'success';
    if ((comp.component?.stock || 0) >= comp.quantity_required) return 'warning';
    return 'danger';
};

const componentStatusLabel = (comp) => {
    if ((comp.quantity_consumed || 0) >= comp.quantity_required) return 'Consumed';
    if ((comp.component?.stock || 0) >= comp.quantity_required) return 'Ready';
    return 'Insufficient';
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

const canStart = ['draft', 'pending'].includes(props.workOrder.status);
const canComplete = props.workOrder.status === 'in_progress';
const canCancel = ['draft', 'pending', 'in_progress'].includes(props.workOrder.status);
const isReadOnly = ['completed', 'cancelled'].includes(props.workOrder.status);

const startProduction = () => {
    if (!confirm('Start production for this work order? Components will be reserved.')) return;
    processing.value = true;
    router.post(route('work-orders.start', props.workOrder.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};

const completeProduction = () => {
    if (!confirm(`Complete this work order? ${quantityProduced.value} units will be added to assembly stock.`)) return;
    processing.value = true;
    router.post(route('work-orders.complete', props.workOrder.id), {
        quantity_produced: quantityProduced.value,
    }, {
        onFinish: () => { processing.value = false; },
    });
};

const cancelWorkOrder = () => {
    if (!confirm('Cancel this work order? Any reserved components will be released.')) return;
    processing.value = true;
    router.post(route('work-orders.cancel', props.workOrder.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
</script>

<template>
    <Head :title="`Work Order ${workOrder.wo_number}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('work-orders.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('work-orders.index')" class="text-text-tertiary hover:text-text-primary">Work Orders</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ workOrder.wo_number }}</span>
            </div>
        </template>

        <PageHeader :title="workOrder.wo_number" description="Work order details">
            <template #actions>
                <Badge :variant="statusVariant(workOrder.status)" size="sm" dot>{{ getStatusLabel(workOrder.status) }}</Badge>
                <Button
                    v-if="canStart"
                    variant="default"
                    size="sm"
                    :disabled="processing"
                    @click="startProduction"
                >
                    <Play :size="14" />
                    Start Production
                </Button>
                <Button
                    v-if="canCancel"
                    variant="danger"
                    size="sm"
                    :disabled="processing"
                    @click="cancelWorkOrder"
                >
                    <X :size="14" />
                    Cancel Work Order
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('work-orders.index')">
                    <ArrowLeft :size="14" />
                    Back to Work Orders
                </Button>
            </template>
        </PageHeader>

        <!-- Status Timeline -->
        <Card v-if="workOrder.status !== 'cancelled'" :padded="false" class="mt-6">
            <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Production Progress</h3></div>
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
                                class="ml-2 text-sm font-medium"
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
                <span class="text-sm font-medium text-status-danger">This work order has been cancelled.</span>
            </div>
        </Card>

        <!-- Key metrics -->
        <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <StatTile
                label="Quantity to Produce"
                :value="workOrder.quantity"
                icon-tone="brand"
            >
                <template #icon><Boxes :size="18" /></template>
            </StatTile>
            <StatTile
                label="Quantity Produced"
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
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Work Order Details</h3></div>
                    <div class="p-5">
                        <dl class="grid grid-cols-2 gap-4">
                            <div>
                                <dt class="mb-1 text-xs text-text-tertiary">Assembly Product</dt>
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
                                <dt class="mb-1 text-xs text-text-tertiary">Status</dt>
                                <dd>
                                    <Badge :variant="statusVariant(workOrder.status)" size="sm" dot>{{ getStatusLabel(workOrder.status) }}</Badge>
                                </dd>
                            </div>
                            <div>
                                <dt class="mb-1 text-xs text-text-tertiary">Quantity to Produce</dt>
                                <dd class="text-lg font-bold tabular-nums text-text-primary">{{ workOrder.quantity }}</dd>
                            </div>
                            <div>
                                <dt class="mb-1 text-xs text-text-tertiary">Quantity Produced</dt>
                                <dd class="text-lg font-bold tabular-nums" :class="(workOrder.quantity_produced || 0) >= workOrder.quantity ? 'text-status-success' : 'text-text-primary'">
                                    {{ workOrder.quantity_produced || 0 }}
                                </dd>
                            </div>
                            <div v-if="workOrder.warehouse">
                                <dt class="mb-1 text-xs text-text-tertiary">Warehouse</dt>
                                <dd class="text-sm text-text-primary">{{ workOrder.warehouse.name }}</dd>
                            </div>
                            <div v-if="workOrder.notes">
                                <dt class="mb-1 text-xs text-text-tertiary">Notes</dt>
                                <dd class="text-sm text-text-primary">{{ workOrder.notes }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Components Table -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Required Components</h3></div>
                    <div class="p-5">
                        <div class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                            <table class="min-w-full">
                                <thead>
                                    <tr class="border-b border-border-subtle">
                                        <th :class="thClass">Component</th>
                                        <th :class="thClass">SKU</th>
                                        <th :class="[thClass, 'text-center']">Required Qty</th>
                                        <th :class="[thClass, 'text-center']">Consumed</th>
                                        <th :class="[thClass, 'text-center']">Available</th>
                                        <th :class="[thClass, 'text-center']">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="comp in (workOrder.components || [])" :key="comp.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                                        <td class="px-4 py-3">
                                            <Link
                                                v-if="comp.component"
                                                :href="route('products.show', comp.component.id)"
                                                class="text-sm font-medium text-brand hover:underline"
                                            >
                                                {{ comp.component.name }}
                                            </Link>
                                            <span v-else class="text-sm text-text-tertiary">Unknown</span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-text-tertiary">
                                            {{ comp.component?.sku || '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-center text-sm font-medium tabular-nums text-text-primary">
                                            {{ comp.quantity_required }}
                                        </td>
                                        <td class="px-4 py-3 text-center text-sm tabular-nums text-text-primary">
                                            {{ comp.quantity_consumed || 0 }}
                                        </td>
                                        <td class="px-4 py-3 text-center text-sm tabular-nums" :class="(comp.component?.stock || 0) >= comp.quantity_required ? 'text-status-success' : 'text-status-danger'">
                                            {{ comp.component?.stock || 0 }}
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <Badge :variant="componentStatusVariant(comp)" size="sm">
                                                {{ componentStatusLabel(comp) }}
                                            </Badge>
                                        </td>
                                    </tr>

                                    <tr v-if="!workOrder.components || workOrder.components.length === 0">
                                        <td colspan="6" class="px-4 py-8 text-center text-sm text-text-tertiary">
                                            No components recorded for this work order.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Sidebar -->
            <div class="space-y-4">
                <!-- Complete Production -->
                <Card v-if="!isReadOnly && canComplete" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Complete Production</h3></div>
                    <div class="p-5">
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-text-secondary">
                                Quantity Produced
                            </label>
                            <input
                                v-model.number="quantityProduced"
                                type="number"
                                min="1"
                                :max="workOrder.quantity"
                                :class="fieldInput"
                            />
                            <Button
                                variant="default"
                                class="w-full"
                                :disabled="processing"
                                @click="completeProduction"
                            >
                                <CheckCircle2 :size="16" />
                                Complete Production
                            </Button>
                        </div>
                    </div>
                </Card>

                <!-- Dates / Info -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Information</h3></div>
                    <div class="p-5">
                        <dl class="space-y-3">
                            <div>
                                <dt class="mb-1 text-xs text-text-tertiary">Created By</dt>
                                <dd class="text-sm text-text-primary">{{ workOrder.created_by?.name || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="mb-1 text-xs text-text-tertiary">Created</dt>
                                <dd class="text-sm text-text-primary">{{ formatDate(workOrder.created_at) }}</dd>
                            </div>
                            <div v-if="workOrder.started_at">
                                <dt class="mb-1 text-xs text-text-tertiary">Production Started</dt>
                                <dd class="text-sm text-text-primary">{{ formatDate(workOrder.started_at) }}</dd>
                            </div>
                            <div v-if="workOrder.completed_at">
                                <dt class="mb-1 text-xs text-text-tertiary">Completed</dt>
                                <dd class="text-sm text-text-primary">{{ formatDate(workOrder.completed_at) }}</dd>
                            </div>
                            <div v-if="workOrder.cancelled_at">
                                <dt class="mb-1 text-xs text-text-tertiary">Cancelled</dt>
                                <dd class="text-sm text-text-primary">{{ formatDate(workOrder.cancelled_at) }}</dd>
                            </div>
                            <div>
                                <dt class="mb-1 text-xs text-text-tertiary">Last Updated</dt>
                                <dd class="text-sm text-text-primary">{{ formatDate(workOrder.updated_at) }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Danger Zone -->
                <Card v-if="canCancel" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Danger Zone</h3></div>
                    <div class="p-5">
                        <Button variant="danger" class="w-full" :disabled="processing" @click="cancelWorkOrder">
                            <X :size="16" />
                            Cancel Work Order
                        </Button>
                        <p class="mt-2 text-xs text-text-tertiary">
                            Any reserved components will be released back to stock.
                        </p>
                    </div>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>

