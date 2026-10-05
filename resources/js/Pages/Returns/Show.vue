<script setup>
import PluginSlot from '@/Components/PluginSlot.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Check, PackageCheck, CheckCircle2, X, PackageOpen } from 'lucide-vue-next';
import { formatMoney } from '@/lib/money';
import { displayDateTime } from '@/lib/dates';

const { t } = useI18n();
const { hasPermission, canVisit } = usePermissions();

const props = defineProps({
    pluginComponents: Object,
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
    const labels = { new: t('returns.conditions.new'), used: t('returns.conditions.used'), damaged: t('returns.conditions.damaged') };
    return labels[condition] || condition;
};

const formatDate = (date) => displayDateTime(date);

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

// Restock and condition stay editable until the return is received.
const canEditLines = computed(() =>
    ['pending', 'approved'].includes(props.returnOrder.status) && hasPermission('manage_returns')
);
const editingLines = ref(false);
const lineEdits = reactive({});

const startEditingLines = () => {
    (props.returnOrder.items || []).forEach((item) => {
        lineEdits[item.id] = { restock: !!item.restock, condition: item.condition };
    });
    editingLines.value = true;
};

const saveLines = () => {
    processing.value = true;
    router.patch(route('returns.items.update', props.returnOrder.id), {
        items: Object.entries(lineEdits).map(([id, line]) => ({ id: Number(id), restock: line.restock, condition: line.condition })),
    }, {
        preserveScroll: true,
        onSuccess: () => { editingLines.value = false; },
        onFinish: () => { processing.value = false; },
    });
};

const selectClass = 'h-8 rounded-md border border-border-subtle bg-surface-canvas px-2 text-xs text-text-primary ds-focus-ring';

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('returns.show.headTitle', { number: returnOrder.return_number })" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('returns.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('returns.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.returns') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">#{{ returnOrder.return_number }}</span>
            </div>
        </template>

        <PageHeader
            :title="t('returns.show.title', { number: returnOrder.return_number })"
            :description="t('returns.show.createdOn', { date: formatDate(returnOrder.created_at) })"
        >
            <template #actions>
                <PluginSlot slot="actions" :components="pluginComponents?.actions" />
                <Badge :variant="typeVariant(returnOrder.type)" size="sm" class="capitalize">{{ t(`portal.returns.types.${returnOrder.type}`) }}</Badge>
                <Badge :variant="statusVariant(returnOrder.status)" size="sm" dot class="capitalize">{{ t(`portal.statuses.${returnOrder.status}`) }}</Badge>

                <Button
                    v-if="returnOrder.status === 'pending'"
                    variant="default"
                    size="sm"
                    :disabled="processing"
                    @click="performAction('approve')"
                >
                    <Check :size="14" />
                    {{ t('returns.show.approve') }}
                </Button>
                <Button
                    v-if="returnOrder.status === 'approved'"
                    variant="default"
                    size="sm"
                    :disabled="processing"
                    @click="performAction('receive')"
                >
                    <PackageCheck :size="14" />
                    {{ t('returns.show.markReceived') }}
                </Button>
                <Button
                    v-if="returnOrder.status === 'received'"
                    variant="default"
                    size="sm"
                    :disabled="processing"
                    @click="performAction('complete')"
                >
                    <CheckCircle2 :size="14" />
                    {{ t('returns.show.complete') }}
                </Button>
                <Button
                    v-if="returnOrder.status === 'pending'"
                    variant="danger"
                    size="sm"
                    :disabled="processing"
                    @click="showRejectModal = true"
                >
                    <X :size="14" />
                    {{ t('returns.show.reject') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('returns.index')">
                    <ArrowLeft :size="14" />
                    {{ t('returns.show.backToReturns') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Left Column: Items & Details -->
            <div class="space-y-4 lg:col-span-2">
                <!-- Return Items -->
                <Card :padded="false">
                    <div class="flex items-center justify-between gap-2 px-5 pt-5">
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('returns.show.items') }}</h3>
                        <div v-if="canEditLines" class="flex gap-2">
                            <template v-if="editingLines">
                                <Button variant="ghost" size="xs" :disabled="processing" @click="editingLines = false">{{ t('returns.lines.cancel') }}</Button>
                                <Button size="xs" :loading="processing" :disabled="processing" @click="saveLines">{{ t('returns.lines.save') }}</Button>
                            </template>
                            <Button v-else variant="secondary" size="xs" @click="startEditingLines">{{ t('returns.lines.edit') }}</Button>
                        </div>
                    </div>
                    <p v-if="editingLines" class="px-5 pt-1 text-xs text-text-tertiary">{{ t('returns.lines.hint') }}</p>
                    <div class="p-5">
                        <div v-if="returnOrder.items && returnOrder.items.length > 0" class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                            <table class="min-w-full">
                                <thead>
                                    <tr class="border-b border-border-subtle">
                                        <th :class="thClass">{{ t('common.product') }}</th>
                                        <th :class="thClass">SKU</th>
                                        <th :class="[thClass, 'text-center']">{{ t('returns.columns.qty') }}</th>
                                        <th :class="[thClass, 'text-center']">{{ t('returns.columns.condition') }}</th>
                                        <th :class="[thClass, 'text-center']">{{ t('returns.columns.restock') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="item in returnOrder.items"
                                        :key="item.id"
                                        class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                                    >
                                        <td class="min-w-[12rem] px-4 py-3 text-sm font-medium text-text-primary">
                                            {{ item.product?.name || item.order_item?.product_name || t('returns.show.unknownProduct') }}
                                            <span v-if="item.variant?.title" class="text-text-secondary"> ({{ item.variant.title }})</span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-text-tertiary">
                                            {{ item.variant?.sku || item.order_item?.sku || item.product?.sku || '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-center text-sm font-medium tabular-nums text-text-primary">
                                            {{ item.quantity }}
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <select
                                                v-if="editingLines && lineEdits[item.id]"
                                                v-model="lineEdits[item.id].condition"
                                                :aria-label="t('returns.show.conditionFor', { name: item.product?.name || '' })"
                                                :class="selectClass"
                                            >
                                                <option value="new">{{ getConditionLabel('new') }}</option>
                                                <option value="used">{{ getConditionLabel('used') }}</option>
                                                <option value="damaged">{{ getConditionLabel('damaged') }}</option>
                                            </select>
                                            <Badge v-else :variant="conditionVariant(item.condition)" size="sm">
                                                {{ getConditionLabel(item.condition) }}
                                            </Badge>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <input
                                                v-if="editingLines && lineEdits[item.id]"
                                                v-model="lineEdits[item.id].restock"
                                                type="checkbox"
                                                :aria-label="t('returns.show.restockFor', { name: item.product?.name || '' })"
                                                class="h-4 w-4 rounded border-border-strong text-brand ds-focus-ring"
                                            />
                                            <template v-else>
                                                <Badge v-if="item.restock" variant="success" size="sm">{{ t('common.yes') }}</Badge>
                                                <Badge v-else variant="neutral" size="sm">{{ t('common.no') }}</Badge>
                                            </template>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
                            <PackageOpen :size="22" class="text-text-tertiary" />
                            <p class="text-sm text-text-tertiary">{{ t('returns.show.noItems') }}</p>
                        </div>
                    </div>
                </Card>

                <!-- Reason & Notes -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('returns.show.details') }}</h3></div>
                    <div class="p-5">
                        <dl class="space-y-3">
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('returns.show.reason') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ returnOrder.reason }}</dd>
                            </div>
                            <div v-if="returnOrder.notes">
                                <dt class="text-xs text-text-tertiary">{{ t('common.notes') }}</dt>
                                <dd class="mt-1 whitespace-pre-line text-sm text-text-secondary">{{ returnOrder.notes }}</dd>
                            </div>
                            <div v-if="returnOrder.order">
                                <dt class="text-xs text-text-tertiary">{{ t('returns.show.forOrder') }}</dt>
                                <dd class="mt-1 text-sm">
                                    <Link v-if="canVisit('orders.show')" :href="route('orders.show', returnOrder.order_id)" class="text-brand hover:underline">
                                        #{{ returnOrder.order.order_number }}
                                    </Link>
                                    <span v-else class="text-text-primary">#{{ returnOrder.order.order_number }}</span>
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
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('returns.show.summary') }}</h3></div>
                    <div class="p-5">
                        <dl class="space-y-3">
                            <div class="flex justify-between text-sm">
                                <dt class="text-text-secondary">{{ t('returns.show.returnNumber') }}</dt>
                                <dd class="font-medium text-text-primary">{{ returnOrder.return_number }}</dd>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <dt class="text-text-secondary">{{ t('common.type') }}</dt>
                                <dd>
                                    <Badge :variant="typeVariant(returnOrder.type)" size="sm" class="capitalize">{{ t(`portal.returns.types.${returnOrder.type}`) }}</Badge>
                                </dd>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <dt class="text-text-secondary">{{ t('common.status') }}</dt>
                                <dd>
                                    <Badge :variant="statusVariant(returnOrder.status)" size="sm" dot class="capitalize">{{ t(`portal.statuses.${returnOrder.status}`) }}</Badge>
                                </dd>
                            </div>
                            <div class="flex justify-between text-sm">
                                <dt class="text-text-secondary">{{ t('common.createdAt') }}</dt>
                                <dd class="font-medium text-text-primary">{{ formatDate(returnOrder.created_at) }}</dd>
                            </div>
                            <div v-if="returnOrder.completed_at" class="flex justify-between text-sm">
                                <dt class="text-text-secondary">{{ t('returns.show.completed') }}</dt>
                                <dd class="font-medium text-text-primary">{{ formatDate(returnOrder.completed_at) }}</dd>
                            </div>
                            <div v-if="returnOrder.processor" class="flex justify-between text-sm">
                                <dt class="text-text-secondary">{{ t('returns.show.processedBy') }}</dt>
                                <dd class="font-medium text-text-primary">{{ returnOrder.processor.name }}</dd>
                            </div>
                            <div class="border-t border-border-subtle pt-3">
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm font-semibold text-text-primary">{{ t('returns.show.refundAmount') }}</dt>
                                    <dd class="text-xl font-bold tabular-nums text-brand">{{ formatMoney(returnOrder.refund_amount, returnOrder.order?.currency) }}</dd>
                                </div>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Actions -->
                <Card v-if="returnOrder.status !== 'completed' && returnOrder.status !== 'rejected'" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('common.actions') }}</h3></div>
                    <div class="p-5">
                        <div class="space-y-2">
                            <Button
                                v-if="returnOrder.status === 'pending'"
                                variant="default"
                                class="w-full"
                                :disabled="processing"
                                @click="performAction('approve')"
                            >
                                {{ t('returns.show.approve') }}
                            </Button>
                            <Button
                                v-if="returnOrder.status === 'approved'"
                                variant="default"
                                class="w-full"
                                :disabled="processing"
                                @click="performAction('receive')"
                            >
                                {{ t('returns.show.markReceived') }}
                            </Button>
                            <Button
                                v-if="returnOrder.status === 'received'"
                                variant="default"
                                class="w-full"
                                :disabled="processing"
                                @click="performAction('complete')"
                            >
                                {{ t('returns.show.complete') }}
                            </Button>
                        </div>

                        <p class="mt-3 text-xs text-text-tertiary">
                            <template v-if="returnOrder.status === 'pending'">
                                {{ t('returns.show.pendingHint') }}
                            </template>
                            <template v-else-if="returnOrder.status === 'approved'">
                                {{ t('returns.show.approvedHint') }}
                            </template>
                            <template v-else-if="returnOrder.status === 'received'">
                                {{ t('returns.show.receivedHint') }}
                            </template>
                        </p>
                    </div>
                </Card>

                <!-- Danger Zone -->
                <Card v-if="returnOrder.status === 'pending'" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('returns.show.dangerZone') }}</h3></div>
                    <div class="p-5">
                        <Button variant="danger" class="w-full" :disabled="processing" @click="showRejectModal = true">
                            {{ t('returns.show.reject') }}
                        </Button>
                        <p class="mt-2 text-xs text-text-tertiary">
                            {{ t('returns.show.rejectWarning') }}
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
                        <h3 class="text-base font-semibold text-text-primary">{{ t('returns.show.reject') }}</h3>
                        <button
                            @click="showRejectModal = false"
                            class="text-text-tertiary transition-colors hover:text-text-primary"
                        >
                            <X :size="18" />
                        </button>
                    </div>

                    <div class="mb-6">
                        <label class="mb-1 block text-sm font-medium text-text-secondary">{{ t('returns.show.rejectReason') }}</label>
                        <textarea
                            v-model="rejectNotes"
                            rows="3"
                            class="w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            :placeholder="t('returns.show.rejectPlaceholder')"
                        ></textarea>
                    </div>

                    <div class="flex justify-end gap-3">
                        <Button variant="secondary" :disabled="processing" @click="showRejectModal = false">
                            {{ t('common.cancel') }}
                        </Button>
                        <Button variant="danger" :loading="processing" :disabled="processing" @click="submitReject">
                            <span v-if="processing">{{ t('returns.show.rejecting') }}</span>
                            <span v-else>{{ t('returns.show.reject') }}</span>
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
    </AppLayout>
</template>
