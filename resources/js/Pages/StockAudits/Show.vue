<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { ref, computed, nextTick, defineAsyncComponent } from 'vue';
import { useI18n } from 'vue-i18n';
import { displayDateTime } from '@/lib/dates';
import { useBarcodeWedge, useBarcodeLookup } from '@/composables/useBarcodeWedge';
import {
    Pencil,
    ArrowLeft,
    Play,
    CheckCircle2,
    Trash2,
    ListChecks,
    Layers,
    AlertTriangle,
    ScanLine,
} from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';
import { auditItemStatus } from '@/lib/auditItemStatus';

const { canVisit } = usePermissions();

const BarcodeScannerModal = defineAsyncComponent(() => import('@/Components/BarcodeScannerModal.vue'));

const { t } = useI18n();

const props = defineProps({
    audit: Object,
    summary: Object,
});

const processing = ref(false);
const editingItemId = ref(null);
const countValue = ref(0);
const countNotes = ref('');
const savingCount = ref(false);

const statusVariant = (status) =>
    ({
        draft: 'neutral',
        in_progress: 'info',
        completed: 'success',
        cancelled: 'danger',
    }[status] || 'neutral');

const getStatusLabel = (status) => {
    const labels = {
        'draft': t('stockAudits.statuses.draft'),
        'in_progress': t('stockAudits.statuses.in_progress'),
        'completed': t('stockAudits.statuses.completed'),
        'cancelled': t('stockAudits.statuses.cancelled'),
    };
    return labels[status] || status;
};

const itemStatusVariant = (status) =>
    ({
        pending: 'neutral',
        counted: 'info',
        verified: 'success',
        adjusted: 'brand',
    }[status] || 'neutral');

const getItemStatusLabel = (status) => {
    if (status === 'not_counted') return t('auditLabels.notCounted');
    const labels = {
        'pending': t('stockAudits.itemStatuses.pending'),
        'counted': t('stockAudits.itemStatuses.counted'),
        'verified': t('stockAudits.itemStatuses.verified'),
        'adjusted': t('stockAudits.itemStatuses.adjusted'),
    };
    return labels[status] || status;
};

const typeLabels = computed(() => ({
    'full': t('stockAudits.types.full'),
    'cycle': t('stockAudits.types.cycle'),
    'spot': t('stockAudits.types.spot'),
}));

const formatDate = (dateStr) => displayDateTime(dateStr);

const canStart = computed(() => props.audit.status === 'draft');
const canComplete = computed(() => props.audit.status === 'in_progress');
const canEdit = computed(() => props.audit.status === 'draft');
const canDelete = computed(() => props.audit.status === 'draft');
const canCount = computed(() => props.audit.status === 'in_progress');

const startAudit = () => {
    if (!confirm(t('stockAudits.show.startConfirm'))) return;
    processing.value = true;
    router.post(route('stock-audits.start', props.audit.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};

// Lines nobody counted: completing with any left needs an explicit
// confirmation, which the server also requires (allow_uncounted).
const uncountedCount = computed(() => (props.audit.items || []).filter((item) => item.counted_quantity === null || item.counted_quantity === undefined).length);

const completeAudit = () => {
    const total = (props.audit.items || []).length;
    const uncounted = uncountedCount.value;
    const message = uncounted > 0
        ? t('stockAudits.completeUncountedConfirm', { uncounted, total })
        : t('stockAudits.completeConfirm');
    if (!confirm(message)) return;
    processing.value = true;
    router.post(route('stock-audits.complete', props.audit.id), { allow_uncounted: uncounted > 0 }, {
        onFinish: () => { processing.value = false; },
    });
};

const deleteAudit = () => {
    if (!confirm(t('stockAudits.show.deleteConfirm'))) return;
    processing.value = true;
    router.delete(route('stock-audits.destroy', props.audit.id), {
        onFinish: () => { processing.value = false; },
    });
};

const startCounting = (item) => {
    editingItemId.value = item.id;
    countValue.value = item.counted_quantity !== null ? item.counted_quantity : item.system_quantity;
    countNotes.value = item.notes || '';
};

const cancelCounting = () => {
    editingItemId.value = null;
    countValue.value = 0;
    countNotes.value = '';
};

const saveCount = async (item) => {
    savingCount.value = true;
    try {
        const response = await fetch(route('stock-audits.items.count', { stockAudit: props.audit.id, item: item.id }), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            body: JSON.stringify({
                counted_quantity: parseInt(countValue.value),
                notes: countNotes.value || null,
            }),
        });

        if (response.ok) {
            // Refresh the page to get updated data
            router.reload({ only: ['audit', 'summary'] });
            editingItemId.value = null;
        } else {
            const data = await response.json();
            alert(data.message || t('stockAudits.show.updateCountFailed'));
        }
    } catch (error) {
        alert(t('stockAudits.show.saveCountError'));
    } finally {
        savingCount.value = false;
    }
};

// --- Scanning ---------------------------------------------------------
// Scan (camera modal or a keyboard-wedge scanner) to find an audit line.
// In "add one" mode each scan counts one more unit on the matched line;
// in "enter count" mode the scan opens that line's count input instead.
const showScanner = ref(false);
const scanMode = ref('increment'); // 'increment' | 'enter'
const scanMessage = ref('');
const scanMessageTone = ref('info');
const highlightedItemId = ref(null);
const { lookup } = useBarcodeLookup();

const setScanMessage = (message, tone = 'info') => {
    scanMessage.value = message;
    scanMessageTone.value = tone;
};

// A variant barcode matches the line for that variant. A product code matches
// the product's own line (no variant), falling back to its first line.
const findAuditLine = (product, variant) => {
    const items = props.audit.items || [];
    if (variant) {
        return items.find(item => item.product_variant_id === variant.id) || null;
    }
    return items.find(item => item.product_id === product.id && !item.product_variant_id)
        || items.find(item => item.product_id === product.id)
        || null;
};

const focusLine = async (item) => {
    highlightedItemId.value = item.id;
    await nextTick();
    const row = document.getElementById(`audit-item-${item.id}`);
    row?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => {
        if (highlightedItemId.value === item.id) highlightedItemId.value = null;
    }, 2500);
};

const incrementLine = async (item) => {
    const next = (item.counted_quantity ?? 0) + 1;
    try {
        await axios.post(route('stock-audits.items.count', { stockAudit: props.audit.id, item: item.id }), {
            counted_quantity: next,
            notes: item.notes || null,
        });
        item.counted_quantity = next;
        item.status = 'counted';
        setScanMessage(t('scanning.countedOne', { name: item.product?.name || '', count: next }), 'success');
        router.reload({ only: ['audit', 'summary'] });
    } catch (error) {
        setScanMessage(error.response?.data?.message || t('scanning.saveFailed'), 'danger');
    }
};

const onScannedProduct = async (product, variant = null) => {
    showScanner.value = false;
    if (!canCount.value) return;
    const item = findAuditLine(product, variant);
    if (!item) {
        setScanMessage(t('scanning.notInAudit', { name: variant?.title ? `${product.name} (${variant.title})` : product.name }), 'warning');
        return;
    }
    await focusLine(item);
    if (scanMode.value === 'enter') {
        startCounting(item);
        await nextTick();
        document.getElementById(`audit-count-input-${item.id}`)?.focus();
        setScanMessage(t('scanning.enterCountFor', { name: item.product?.name || '' }));
        return;
    }
    await incrementLine(item);
};

const onScannedCode = async (code) => {
    try {
        const found = await lookup(code);
        if (!found) {
            setScanMessage(t('scanning.notFound', { code }), 'danger');
            return;
        }
        await onScannedProduct(found.product, found.variant);
    } catch (error) {
        setScanMessage(t('scanning.lookupFailed'), 'danger');
    }
};

useBarcodeWedge(onScannedCode, { enabled: () => canCount.value && !showScanner.value });

const getDiscrepancyClass = (item) => {
    if (item.counted_quantity === null) return 'text-text-tertiary';
    const disc = item.counted_quantity - item.system_quantity;
    if (disc > 0) return 'text-status-success';
    if (disc < 0) return 'text-status-danger';
    return 'text-text-secondary';
};

const getDiscrepancyText = (item) => {
    if (item.counted_quantity === null) return '-';
    const disc = item.counted_quantity - item.system_quantity;
    if (disc === 0) return '0';
    return (disc > 0 ? '+' : '') + disc;
};

const thClass = 'px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-text-tertiary';
</script>

<template>
    <Head :title="t('stockAudits.show.pageTitle', { number: audit.audit_number })" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('stock-audits.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('stock-audits.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.stockAudits') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ audit.audit_number }}</span>
            </div>
        </template>

        <PageHeader :title="audit.audit_number" :description="audit.name">
            <template #actions>
                <Badge :variant="statusVariant(audit.status)" size="sm" dot>{{ getStatusLabel(audit.status) }}</Badge>
                <Button
                    v-if="(canEdit) && canVisit('stock-audits.edit')"
                    variant="default"
                    size="sm"
                    as="Link"
                    :href="route('stock-audits.edit', audit.id)"
                >
                    <Pencil :size="14" />
                    {{ t('common.edit') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-audits.index')">
                    <ArrowLeft :size="14" />
                    {{ t('stockAudits.backToList') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Summary metrics -->
        <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-border-subtle bg-surface-raised p-4 transition-colors hover:border-border-strong">
                <p class="text-xs font-medium uppercase tracking-wider text-text-tertiary">{{ t('common.status') }}</p>
                <div class="mt-2">
                    <Badge :variant="statusVariant(audit.status)" size="md" dot>{{ getStatusLabel(audit.status) }}</Badge>
                </div>
            </div>
            <StatTile
                :label="t('stockAudits.show.totalItems')"
                :value="summary.total_items"
                icon-tone="brand"
            >
                <template #icon><Layers :size="18" /></template>
            </StatTile>
            <div class="rounded-lg border border-border-subtle bg-surface-raised p-4 transition-colors hover:border-border-strong">
                <div class="flex items-start justify-between gap-2">
                    <p class="text-xs font-medium uppercase tracking-wider text-text-tertiary">{{ t('stockAudits.show.progress') }}</p>
                    <span class="shrink-0 text-status-info">
                        <ListChecks :size="20" :stroke-width="1.5" />
                    </span>
                </div>
                <p class="mt-2 text-2xl font-semibold tabular-nums tracking-tight text-text-primary">{{ summary.progress }}%</p>
                <div class="mt-2 h-2 w-full rounded-full bg-surface-sunken">
                    <div
                        class="h-2 rounded-full bg-brand transition-all duration-300"
                        :style="{ width: summary.progress + '%' }"
                    ></div>
                </div>
            </div>
            <StatTile
                :label="t('stockAudits.show.discrepancies')"
                :value="summary.discrepancies"
                :icon-tone="summary.discrepancies > 0 ? 'warning' : 'success'"
            >
                <template #icon><AlertTriangle :size="18" /></template>
            </StatTile>
        </section>

        <!-- Audit Details -->
        <Card :padded="false" class="mt-4">
            <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('stockAudits.auditDetails') }}</h3></div>
            <div class="p-5">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <p class="mb-1 text-xs text-text-tertiary">{{ t('stockAudits.fields.auditType') }}</p>
                        <p class="text-sm font-medium text-text-primary">{{ typeLabels[audit.audit_type] || audit.audit_type }}</p>
                    </div>
                    <div>
                        <p class="mb-1 text-xs text-text-tertiary">{{ t('stockAudits.columns.location') }}</p>
                        <p class="text-sm font-medium text-text-primary">
                            {{ audit.warehouse_location?.name || t('products.allLocations') }}
                        </p>
                    </div>
                    <div>
                        <p class="mb-1 text-xs text-text-tertiary">{{ t('stockAudits.columns.createdBy') }}</p>
                        <p class="text-sm font-medium text-text-primary">{{ audit.creator?.name || '-' }}</p>
                    </div>
                    <div>
                        <p class="mb-1 text-xs text-text-tertiary">{{ t('common.createdAt') }}</p>
                        <p class="text-sm font-medium text-text-primary">{{ formatDate(audit.created_at) }}</p>
                    </div>
                    <div v-if="audit.started_at">
                        <p class="mb-1 text-xs text-text-tertiary">{{ t('stockAudits.show.started') }}</p>
                        <p class="text-sm font-medium text-text-primary">{{ formatDate(audit.started_at) }}</p>
                    </div>
                    <div v-if="audit.completed_at">
                        <p class="mb-1 text-xs text-text-tertiary">{{ t('stockAudits.show.completed') }}</p>
                        <p class="text-sm font-medium text-text-primary">{{ formatDate(audit.completed_at) }}</p>
                    </div>
                </div>

                <div v-if="audit.description" class="mt-6 border-t border-border-subtle pt-6">
                    <p class="mb-1 text-xs text-text-tertiary">{{ t('common.description') }}</p>
                    <p class="text-sm text-text-primary">{{ audit.description }}</p>
                </div>

                <div v-if="audit.notes" class="mt-4">
                    <p class="mb-1 text-xs text-text-tertiary">{{ t('common.notes') }}</p>
                    <p class="whitespace-pre-line text-sm text-text-primary">{{ audit.notes }}</p>
                </div>
            </div>
        </Card>

        <!-- Audit Items / Counting Interface -->
        <Card :padded="false" class="mt-4">
            <div class="px-5 pt-5">
                <h3 class="text-sm font-semibold text-text-primary">
                    {{ t('stockAudits.show.auditItems') }}
                    <span class="text-xs font-normal text-text-tertiary">
                        {{ t('stockAudits.show.countedProgress', { counted: summary.counted_items, total: summary.total_items }) }}
                    </span>
                </h3>

                <!-- Scan to count -->
                <div v-if="canCount" class="mt-4 rounded-lg border border-border-subtle bg-surface-sunken p-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <Button size="lg" class="min-h-11" @click="showScanner = true">
                            <ScanLine :size="18" />
                            {{ t('scanning.scan') }}
                        </Button>
                        <div class="flex flex-wrap gap-2" role="radiogroup" :aria-label="t('scanning.modeLabel')">
                            <button
                                type="button"
                                role="radio"
                                :aria-checked="scanMode === 'increment'"
                                class="min-h-11 rounded-md border px-3 text-sm font-medium ds-focus-ring"
                                :class="scanMode === 'increment' ? 'border-brand bg-brand-soft text-brand' : 'border-border-subtle bg-surface-raised text-text-secondary'"
                                @click="scanMode = 'increment'"
                            >
                                {{ t('scanning.modeIncrement') }}
                            </button>
                            <button
                                type="button"
                                role="radio"
                                :aria-checked="scanMode === 'enter'"
                                class="min-h-11 rounded-md border px-3 text-sm font-medium ds-focus-ring"
                                :class="scanMode === 'enter' ? 'border-brand bg-brand-soft text-brand' : 'border-border-subtle bg-surface-raised text-text-secondary'"
                                @click="scanMode = 'enter'"
                            >
                                {{ t('scanning.modeEnter') }}
                            </button>
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-text-tertiary">{{ t('scanning.auditHint') }}</p>
                    <p
                        v-if="scanMessage"
                        class="mt-2 text-sm font-medium"
                        :class="{
                            'text-status-success': scanMessageTone === 'success',
                            'text-status-warning': scanMessageTone === 'warning',
                            'text-status-danger': scanMessageTone === 'danger',
                            'text-text-secondary': scanMessageTone === 'info',
                        }"
                        role="status"
                        aria-live="polite"
                    >
                        {{ scanMessage }}
                    </p>
                </div>
            </div>
            <div class="mt-4 w-full overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-border-subtle">
                            <th :class="thClass">{{ t('common.product') }}</th>
                            <th :class="thClass">{{ t('stockAudits.columns.location') }}</th>
                            <th :class="[thClass, 'text-right']">{{ t('stockAudits.columns.systemQty') }}</th>
                            <th :class="[thClass, 'text-right']">{{ t('stockAudits.columns.countedQty') }}</th>
                            <th :class="[thClass, 'text-right']">{{ t('stockAudits.columns.discrepancy') }}</th>
                            <th :class="thClass">{{ t('common.status') }}</th>
                            <th :class="thClass">{{ t('stockAudits.columns.countedBy') }}</th>
                            <th v-if="canCount" :class="thClass">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="item in audit.items" :key="item.id">
                            <!-- Normal Row -->
                            <tr v-if="editingItemId !== item.id" :id="`audit-item-${item.id}`" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay" :class="{ 'bg-brand-soft': highlightedItemId === item.id }">
                                <td class="px-6 py-4 text-sm text-text-primary">
                                    <div class="font-medium">{{ item.product?.name || '-' }}</div>
                                    <div class="text-xs text-text-tertiary">SKU: {{ item.product?.sku || '-' }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-text-secondary">
                                    {{ item.location?.name || '-' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm tabular-nums text-text-secondary">
                                    {{ item.system_quantity }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium tabular-nums text-text-primary">
                                    {{ item.counted_quantity !== null ? item.counted_quantity : '-' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-semibold tabular-nums" :class="getDiscrepancyClass(item)">
                                    {{ getDiscrepancyText(item) }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <Badge :variant="itemStatusVariant(auditItemStatus(item.status, audit.status))" size="sm">
                                        {{ getItemStatusLabel(auditItemStatus(item.status, audit.status)) }}
                                    </Badge>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-text-secondary">
                                    {{ item.counted_by_user?.name || '-' }}
                                </td>
                                <td v-if="canCount" class="whitespace-nowrap px-6 py-4 text-sm">
                                    <button
                                        @click="startCounting(item)"
                                        class="text-sm font-medium text-brand hover:underline"
                                    >
                                        {{ item.counted_quantity !== null ? t('stockAudits.show.recount') : t('stockAudits.show.count') }}
                                    </button>
                                </td>
                            </tr>

                            <!-- Inline Editing Row -->
                            <tr v-else :id="`audit-item-${item.id}`" class="border-b border-border-subtle bg-brand-soft last:border-b-0">
                                <td class="px-6 py-4 text-sm text-text-primary">
                                    <div class="font-medium">{{ item.product?.name || '-' }}</div>
                                    <div class="text-xs text-text-tertiary">SKU: {{ item.product?.sku || '-' }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-text-secondary">
                                    {{ item.location?.name || '-' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm tabular-nums text-text-secondary">
                                    {{ item.system_quantity }}
                                </td>
                                <td class="px-6 py-3">
                                    <input
                                        :id="`audit-count-input-${item.id}`"
                                        v-model.number="countValue"
                                        type="number"
                                        min="0"
                                        step="1"
                                        class="ml-auto block h-9 w-24 rounded-md border border-border-subtle bg-surface-canvas px-3 text-right text-sm text-text-primary ds-focus-ring"
                                        @keyup.enter="saveCount(item)"
                                        @keyup.escape="cancelCounting"
                                    />
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-semibold tabular-nums" :class="(countValue - item.system_quantity) > 0 ? 'text-status-success' : (countValue - item.system_quantity) < 0 ? 'text-status-danger' : 'text-text-secondary'">
                                    {{ (countValue - item.system_quantity) > 0 ? '+' : '' }}{{ countValue - item.system_quantity }}
                                </td>
                                <td colspan="2" class="px-6 py-3">
                                    <input
                                        v-model="countNotes"
                                        type="text"
                                        :placeholder="t('stockAudits.show.countNotesPlaceholder')"
                                        class="block h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                                        @keyup.enter="saveCount(item)"
                                    />
                                </td>
                                <td v-if="canCount" class="px-6 py-3">
                                    <div class="flex gap-2">
                                        <Button
                                            size="sm"
                                            :loading="savingCount"
                                            :disabled="savingCount"
                                            @click="saveCount(item)"
                                        >
                                            {{ savingCount ? '...' : t('common.save') }}
                                        </Button>
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            @click="cancelCounting"
                                        >
                                            {{ t('common.cancel') }}
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </template>

                        <tr v-if="!audit.items || audit.items.length === 0">
                            <td :colspan="canCount ? 8 : 7" class="px-6 py-12 text-center">
                                <p class="text-sm text-text-tertiary">{{ t('stockAudits.show.noItems') }}</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <!-- Actions -->
        <Card v-if="canStart || canComplete" :padded="false" class="mt-4">
            <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('common.actions') }}</h3></div>
            <div class="p-5">
                <div class="flex flex-wrap gap-3">
                    <Button
                        v-if="canStart"
                        :loading="processing"
                        :disabled="processing"
                        @click="startAudit"
                    >
                        <Play :size="16" />
                        {{ processing ? t('stockAudits.show.processing') : t('stockAudits.show.startAudit') }}
                    </Button>
                    <Button
                        v-if="canComplete"
                        :loading="processing"
                        :disabled="processing"
                        @click="completeAudit"
                    >
                        <CheckCircle2 :size="16" />
                        {{ processing ? t('stockAudits.show.processing') : t('stockAudits.show.completeAudit') }}
                    </Button>
                </div>
                <p v-if="canStart" class="mt-3 text-xs text-text-tertiary">
                    {{ t('stockAudits.show.startHint') }}
                </p>
                <p v-if="canComplete" class="mt-3 text-xs text-text-tertiary">
                    {{ t('stockAudits.show.completeHint') }}
                </p>
            </div>
        </Card>

        <!-- Danger Zone -->
        <Card v-if="canDelete" :padded="false" class="mt-4">
            <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('stockAudits.show.dangerZone') }}</h3></div>
            <div class="p-5">
                <Button
                    variant="danger"
                    :loading="processing"
                    :disabled="processing"
                    @click="deleteAudit"
                >
                    <Trash2 :size="16" />
                    {{ processing ? t('stockAudits.show.processing') : t('stockAudits.show.deleteAudit') }}
                </Button>
                <p class="mt-2 text-xs text-text-tertiary">
                    {{ t('stockAudits.show.deleteHint') }}
                </p>
            </div>
        </Card>

        <!-- Barcode Scanner Modal -->
        <BarcodeScannerModal
            :show="showScanner"
            @close="showScanner = false"
            @product-found="onScannedProduct"
        />
    </AppLayout>
</template>
