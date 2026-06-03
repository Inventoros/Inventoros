<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    Pencil,
    ArrowLeft,
    Play,
    CheckCircle2,
    Trash2,
    ListChecks,
    Layers,
    AlertTriangle,
} from 'lucide-vue-next';

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
        'draft': 'Draft',
        'in_progress': 'In Progress',
        'completed': 'Completed',
        'cancelled': 'Cancelled',
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
    const labels = {
        'pending': 'Pending',
        'counted': 'Counted',
        'verified': 'Verified',
        'adjusted': 'Adjusted',
    };
    return labels[status] || status;
};

const typeLabels = {
    'full': 'Full Audit',
    'cycle': 'Cycle Count',
    'spot': 'Spot Check',
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

const canStart = computed(() => props.audit.status === 'draft');
const canComplete = computed(() => props.audit.status === 'in_progress');
const canEdit = computed(() => props.audit.status === 'draft');
const canDelete = computed(() => props.audit.status === 'draft');
const canCount = computed(() => props.audit.status === 'in_progress');

const startAudit = () => {
    if (!confirm('Are you sure you want to start this audit? System quantities will be recorded at current levels.')) return;
    processing.value = true;
    router.post(route('stock-audits.start', props.audit.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};

const completeAudit = () => {
    if (!confirm('Are you sure you want to complete this audit? Stock adjustments will be created for any discrepancies.')) return;
    processing.value = true;
    router.post(route('stock-audits.complete', props.audit.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};

const deleteAudit = () => {
    if (!confirm('Are you sure you want to delete this audit? This action cannot be undone.')) return;
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
            alert(data.message || 'Failed to update count');
        }
    } catch (error) {
        alert('An error occurred while saving the count');
    } finally {
        savingCount.value = false;
    }
};

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
    <Head :title="`Audit ${audit.audit_number}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('stock-audits.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('stock-audits.index')" class="text-text-tertiary hover:text-text-primary">Stock Audits</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ audit.audit_number }}</span>
            </div>
        </template>

        <PageHeader :title="audit.audit_number" :description="audit.name">
            <template #actions>
                <Badge :variant="statusVariant(audit.status)" size="sm" dot>{{ getStatusLabel(audit.status) }}</Badge>
                <Button
                    v-if="canEdit"
                    variant="default"
                    size="sm"
                    as="Link"
                    :href="route('stock-audits.edit', audit.id)"
                >
                    <Pencil :size="14" />
                    Edit
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-audits.index')">
                    <ArrowLeft :size="14" />
                    Back to List
                </Button>
            </template>
        </PageHeader>

        <!-- Flash Messages -->
        <div v-if="$page.props.flash?.success" class="mt-6 rounded-lg border border-status-success/20 bg-status-success-soft p-4">
            <p class="text-sm text-status-success">{{ $page.props.flash.success }}</p>
        </div>
        <div v-if="$page.props.flash?.error" class="mt-6 rounded-lg border border-status-danger/20 bg-status-danger-soft p-4">
            <p class="text-sm text-status-danger">{{ $page.props.flash.error }}</p>
        </div>

        <!-- Summary metrics -->
        <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-border-subtle bg-surface-raised p-4 transition-colors hover:border-border-strong">
                <p class="text-xs font-medium uppercase tracking-wider text-text-tertiary">Status</p>
                <div class="mt-2">
                    <Badge :variant="statusVariant(audit.status)" size="md" dot>{{ getStatusLabel(audit.status) }}</Badge>
                </div>
            </div>
            <StatTile
                label="Total Items"
                :value="summary.total_items"
                icon-tone="brand"
            >
                <template #icon><Layers :size="18" /></template>
            </StatTile>
            <div class="rounded-lg border border-border-subtle bg-surface-raised p-4 transition-colors hover:border-border-strong">
                <div class="flex items-start justify-between gap-2">
                    <p class="text-xs font-medium uppercase tracking-wider text-text-tertiary">Progress</p>
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
                label="Discrepancies"
                :value="summary.discrepancies"
                :icon-tone="summary.discrepancies > 0 ? 'warning' : 'success'"
            >
                <template #icon><AlertTriangle :size="18" /></template>
            </StatTile>
        </section>

        <!-- Audit Details -->
        <Card :padded="false" class="mt-4">
            <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Audit Details</h3></div>
            <div class="p-5">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <p class="mb-1 text-xs text-text-tertiary">Audit Type</p>
                        <p class="text-sm font-medium text-text-primary">{{ typeLabels[audit.audit_type] || audit.audit_type }}</p>
                    </div>
                    <div>
                        <p class="mb-1 text-xs text-text-tertiary">Location</p>
                        <p class="text-sm font-medium text-text-primary">
                            {{ audit.warehouse_location?.name || 'All Locations' }}
                        </p>
                    </div>
                    <div>
                        <p class="mb-1 text-xs text-text-tertiary">Created By</p>
                        <p class="text-sm font-medium text-text-primary">{{ audit.creator?.name || '-' }}</p>
                    </div>
                    <div>
                        <p class="mb-1 text-xs text-text-tertiary">Created</p>
                        <p class="text-sm font-medium text-text-primary">{{ formatDate(audit.created_at) }}</p>
                    </div>
                    <div v-if="audit.started_at">
                        <p class="mb-1 text-xs text-text-tertiary">Started</p>
                        <p class="text-sm font-medium text-text-primary">{{ formatDate(audit.started_at) }}</p>
                    </div>
                    <div v-if="audit.completed_at">
                        <p class="mb-1 text-xs text-text-tertiary">Completed</p>
                        <p class="text-sm font-medium text-text-primary">{{ formatDate(audit.completed_at) }}</p>
                    </div>
                </div>

                <div v-if="audit.description" class="mt-6 border-t border-border-subtle pt-6">
                    <p class="mb-1 text-xs text-text-tertiary">Description</p>
                    <p class="text-sm text-text-primary">{{ audit.description }}</p>
                </div>

                <div v-if="audit.notes" class="mt-4">
                    <p class="mb-1 text-xs text-text-tertiary">Notes</p>
                    <p class="whitespace-pre-line text-sm text-text-primary">{{ audit.notes }}</p>
                </div>
            </div>
        </Card>

        <!-- Audit Items / Counting Interface -->
        <Card :padded="false" class="mt-4">
            <div class="px-5 pt-5">
                <h3 class="text-sm font-semibold text-text-primary">
                    Audit Items
                    <span class="text-xs font-normal text-text-tertiary">
                        ({{ summary.counted_items }} of {{ summary.total_items }} counted)
                    </span>
                </h3>
            </div>
            <div class="mt-4 w-full overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-border-subtle">
                            <th :class="thClass">Product</th>
                            <th :class="thClass">Location</th>
                            <th :class="[thClass, 'text-right']">System Qty</th>
                            <th :class="[thClass, 'text-right']">Counted Qty</th>
                            <th :class="[thClass, 'text-right']">Discrepancy</th>
                            <th :class="thClass">Status</th>
                            <th :class="thClass">Counted By</th>
                            <th v-if="canCount" :class="thClass">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="item in audit.items" :key="item.id">
                            <!-- Normal Row -->
                            <tr v-if="editingItemId !== item.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
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
                                    <Badge :variant="itemStatusVariant(item.status)" size="sm">
                                        {{ getItemStatusLabel(item.status) }}
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
                                        {{ item.counted_quantity !== null ? 'Recount' : 'Count' }}
                                    </button>
                                </td>
                            </tr>

                            <!-- Inline Editing Row -->
                            <tr v-else class="border-b border-border-subtle bg-brand-soft last:border-b-0">
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
                                        placeholder="Notes (optional)"
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
                                            {{ savingCount ? '...' : 'Save' }}
                                        </Button>
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            @click="cancelCounting"
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </template>

                        <tr v-if="!audit.items || audit.items.length === 0">
                            <td :colspan="canCount ? 8 : 7" class="px-6 py-12 text-center">
                                <p class="text-sm text-text-tertiary">No items in this audit</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <!-- Actions -->
        <Card v-if="canStart || canComplete" :padded="false" class="mt-4">
            <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Actions</h3></div>
            <div class="p-5">
                <div class="flex flex-wrap gap-3">
                    <Button
                        v-if="canStart"
                        :loading="processing"
                        :disabled="processing"
                        @click="startAudit"
                    >
                        <Play :size="16" />
                        {{ processing ? 'Processing...' : 'Start Audit' }}
                    </Button>
                    <Button
                        v-if="canComplete"
                        :loading="processing"
                        :disabled="processing"
                        @click="completeAudit"
                    >
                        <CheckCircle2 :size="16" />
                        {{ processing ? 'Processing...' : 'Complete Audit' }}
                    </Button>
                </div>
                <p v-if="canStart" class="mt-3 text-xs text-text-tertiary">
                    Starting the audit will record current system stock levels and allow counting to begin.
                </p>
                <p v-if="canComplete" class="mt-3 text-xs text-text-tertiary">
                    Completing the audit will create stock adjustments for any discrepancies between system and counted quantities.
                </p>
            </div>
        </Card>

        <!-- Danger Zone -->
        <Card v-if="canDelete" :padded="false" class="mt-4">
            <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Danger Zone</h3></div>
            <div class="p-5">
                <Button
                    variant="danger"
                    :loading="processing"
                    :disabled="processing"
                    @click="deleteAudit"
                >
                    <Trash2 :size="16" />
                    {{ processing ? 'Processing...' : 'Delete Audit' }}
                </Button>
                <p class="mt-2 text-xs text-text-tertiary">
                    Deleting this audit is permanent and cannot be undone.
                </p>
            </div>
        </Card>
    </AppLayout>
</template>
