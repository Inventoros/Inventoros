<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';

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

const getStatusBadgeClass = (status) => {
    const classes = {
        'draft': 'bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-300',
        'in_progress': 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        'completed': 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
        'cancelled': 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
    };
    return classes[status] || classes.draft;
};

const getStatusLabel = (status) => {
    const labels = {
        'draft': 'Draft',
        'in_progress': 'In Progress',
        'completed': 'Completed',
        'cancelled': 'Cancelled',
    };
    return labels[status] || status;
};

const getItemStatusBadgeClass = (status) => {
    const classes = {
        'pending': 'bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-300',
        'counted': 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        'verified': 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
        'adjusted': 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300',
    };
    return classes[status] || classes.pending;
};

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
    if (item.counted_quantity === null) return 'text-gray-400 dark:text-gray-500';
    const disc = item.counted_quantity - item.system_quantity;
    if (disc > 0) return 'text-green-600 dark:text-green-400';
    if (disc < 0) return 'text-red-600 dark:text-red-400';
    return 'text-gray-600 dark:text-gray-300';
};

const getDiscrepancyText = (item) => {
    if (item.counted_quantity === null) return '-';
    const disc = item.counted_quantity - item.system_quantity;
    if (disc === 0) return '0';
    return (disc > 0 ? '+' : '') + disc;
};
</script>

<template>
    <Head :title="`Audit ${audit.audit_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">{{ audit.audit_number }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ audit.name }}</p>
                </div>
                <div class="flex gap-2">
                    <Link
                        v-if="canEdit"
                        :href="route('stock-audits.edit', audit.id)"
                        class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                    >
                        Edit
                    </Link>
                    <Link
                        :href="route('stock-audits.index')"
                        class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                    >
                        Back to List
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Flash Messages -->
                <div v-if="$page.props.flash?.success" class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                    <p class="text-sm text-green-800 dark:text-green-300">{{ $page.props.flash.success }}</p>
                </div>
                <div v-if="$page.props.flash?.error" class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                    <p class="text-sm text-red-800 dark:text-red-300">{{ $page.props.flash.error }}</p>
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm rounded-lg p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Status</p>
                        <span :class="getStatusBadgeClass(audit.status)" class="px-3 py-1 rounded-full text-sm font-medium">
                            {{ getStatusLabel(audit.status) }}
                        </span>
                    </div>
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm rounded-lg p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Total Items</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ summary.total_items }}</p>
                    </div>
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm rounded-lg p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Progress</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ summary.progress }}%</p>
                        <div class="mt-2 w-full bg-gray-200 dark:bg-dark-bg rounded-full h-2">
                            <div
                                class="bg-primary-500 h-2 rounded-full transition-all duration-300"
                                :style="{ width: summary.progress + '%' }"
                            ></div>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm rounded-lg p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Discrepancies</p>
                        <p class="text-3xl font-bold" :class="summary.discrepancies > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'">
                            {{ summary.discrepancies }}
                        </p>
                    </div>
                </div>

                <!-- Audit Details -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-6">Audit Details</h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Audit Type</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ typeLabels[audit.audit_type] || audit.audit_type }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Location</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ audit.warehouse_location?.name || 'All Locations' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Created By</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ audit.creator?.name || '-' }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Created</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ formatDate(audit.created_at) }}</p>
                        </div>
                        <div v-if="audit.started_at">
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Started</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ formatDate(audit.started_at) }}</p>
                        </div>
                        <div v-if="audit.completed_at">
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Completed</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ formatDate(audit.completed_at) }}</p>
                        </div>
                    </div>

                    <div v-if="audit.description" class="mt-6 pt-6 border-t border-gray-200 dark:border-dark-border">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Description</p>
                        <p class="text-sm text-gray-900 dark:text-gray-100">{{ audit.description }}</p>
                    </div>

                    <div v-if="audit.notes" class="mt-4">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Notes</p>
                        <p class="text-sm text-gray-900 dark:text-gray-100 whitespace-pre-line">{{ audit.notes }}</p>
                    </div>
                </div>

                <!-- Audit Items / Counting Interface -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-dark-border">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            Audit Items
                            <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                                ({{ summary.counted_items }} of {{ summary.total_items }} counted)
                            </span>
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                            <thead class="bg-gray-50 dark:bg-dark-bg/50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Product</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Location</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">System Qty</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Counted Qty</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Discrepancy</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Counted By</th>
                                    <th v-if="canCount" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-dark-border">
                                <template v-for="item in audit.items" :key="item.id">
                                    <!-- Normal Row -->
                                    <tr v-if="editingItemId !== item.id" class="hover:bg-gray-50 dark:hover:bg-dark-bg/50">
                                        <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            <div class="font-medium">{{ item.product?.name || '-' }}</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">SKU: {{ item.product?.sku || '-' }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                            {{ item.location?.name || '-' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-600 dark:text-gray-300">
                                            {{ item.system_quantity }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ item.counted_quantity !== null ? item.counted_quantity : '-' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold" :class="getDiscrepancyClass(item)">
                                            {{ getDiscrepancyText(item) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full" :class="getItemStatusBadgeClass(item.status)">
                                                {{ getItemStatusLabel(item.status) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                            {{ item.counted_by_user?.name || '-' }}
                                        </td>
                                        <td v-if="canCount" class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <button
                                                @click="startCounting(item)"
                                                class="text-primary-400 hover:text-primary-300"
                                            >
                                                {{ item.counted_quantity !== null ? 'Recount' : 'Count' }}
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Inline Editing Row -->
                                    <tr v-else class="bg-primary-400/5">
                                        <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                            <div class="font-medium">{{ item.product?.name || '-' }}</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">SKU: {{ item.product?.sku || '-' }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                            {{ item.location?.name || '-' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-600 dark:text-gray-300">
                                            {{ item.system_quantity }}
                                        </td>
                                        <td class="px-6 py-3">
                                            <input
                                                v-model.number="countValue"
                                                type="number"
                                                min="0"
                                                step="1"
                                                class="block w-24 ml-auto rounded-md bg-white dark:bg-dark-bg border-gray-300 dark:border-dark-border text-gray-900 dark:text-gray-100 text-right text-sm shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                                @keyup.enter="saveCount(item)"
                                                @keyup.escape="cancelCounting"
                                            />
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold" :class="(countValue - item.system_quantity) > 0 ? 'text-green-600 dark:text-green-400' : (countValue - item.system_quantity) < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-600 dark:text-gray-300'">
                                            {{ (countValue - item.system_quantity) > 0 ? '+' : '' }}{{ countValue - item.system_quantity }}
                                        </td>
                                        <td colspan="2" class="px-6 py-3">
                                            <input
                                                v-model="countNotes"
                                                type="text"
                                                placeholder="Notes (optional)"
                                                class="block w-full rounded-md bg-white dark:bg-dark-bg border-gray-300 dark:border-dark-border text-gray-900 dark:text-gray-100 text-sm shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                                @keyup.enter="saveCount(item)"
                                            />
                                        </td>
                                        <td v-if="canCount" class="px-6 py-3">
                                            <div class="flex gap-2">
                                                <button
                                                    @click="saveCount(item)"
                                                    :disabled="savingCount"
                                                    class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-xs font-medium rounded-md transition disabled:opacity-50"
                                                >
                                                    {{ savingCount ? '...' : 'Save' }}
                                                </button>
                                                <button
                                                    @click="cancelCounting"
                                                    class="px-3 py-1.5 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 text-xs font-medium rounded-md transition"
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>

                                <tr v-if="!audit.items || audit.items.length === 0">
                                    <td :colspan="canCount ? 8 : 7" class="px-6 py-12 text-center">
                                        <p class="text-gray-500 dark:text-gray-400">No items in this audit</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Actions -->
                <div v-if="canStart || canComplete || canDelete" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Actions</h3>
                    <div class="flex gap-3">
                        <button
                            v-if="canStart"
                            @click="startAudit"
                            :disabled="processing"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {{ processing ? 'Processing...' : 'Start Audit' }}
                        </button>
                        <button
                            v-if="canComplete"
                            @click="completeAudit"
                            :disabled="processing"
                            class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {{ processing ? 'Processing...' : 'Complete Audit' }}
                        </button>
                        <button
                            v-if="canDelete"
                            @click="deleteAudit"
                            :disabled="processing"
                            class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {{ processing ? 'Processing...' : 'Delete Audit' }}
                        </button>
                    </div>
                    <p v-if="canStart" class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        Starting the audit will record current system stock levels and allow counting to begin.
                    </p>
                    <p v-if="canComplete" class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        Completing the audit will create stock adjustments for any discrepancies between system and counted quantities.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
