<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import DataTable from '@/Components/ui/DataTable.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Search, Eye, Pencil, Trash2, PackageCheck, ClipboardList } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    purchaseOrders: Object,
    suppliers: Array,
    filters: Object,
    statuses: Array,
    pluginComponents: Object,
});

const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || '');
const selectedSupplierId = ref(props.filters?.supplier_id || '');

const searchPOs = () => {
    router.get(route('purchase-orders.index'), {
        search: search.value,
        status: selectedStatus.value,
        supplier_id: selectedSupplierId.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    selectedStatus.value = '';
    selectedSupplierId.value = '';
    searchPOs();
};

const deletePO = (po) => {
    if (confirm(`Are you sure you want to delete "${po.po_number}"?`)) {
        router.delete(route('purchase-orders.destroy', po.id));
    }
};

const formatCurrency = (value, currency = 'USD') => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency,
    }).format(value || 0);
};

const formatDate = (dateString) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const statusVariant = (s) =>
    ({ draft: 'neutral', sent: 'info', partial: 'warning', received: 'success', cancelled: 'danger' }[s] || 'neutral');

const statusLabels = {
    draft: 'Draft',
    sent: 'Sent',
    partial: 'Partial',
    received: 'Received',
    cancelled: 'Cancelled',
};

const columns = [
    { key: 'po_number', label: t('purchaseOrders.poNumber') },
    { key: 'supplier', label: t('purchaseOrders.supplier') },
    { key: 'order_date', label: t('purchaseOrders.orderDate') },
    { key: 'expected_date', label: t('purchaseOrders.expected') },
    { key: 'quantity', label: t('common.quantity'), align: 'right' },
    { key: 'total', label: t('common.total'), align: 'right' },
    { key: 'status', label: t('common.status') },
    { key: 'actions', label: t('common.actions'), align: 'right' },
];

const selectClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
</script>

<template>
    <Head :title="t('purchaseOrders.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('purchaseOrders.title') }}</span>
            </div>
        </template>

        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <PageHeader :title="t('purchaseOrders.title')" description="Restock orders to your suppliers.">
            <template #actions>
                <Button variant="default" size="sm" as="Link" :href="route('purchase-orders.create')">
                    <Plus :size="14" />
                    {{ t('purchaseOrders.createPo') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Filters -->
        <Card class="mt-6">
            <form @submit.prevent="searchPOs" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div>
                        <label for="search" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('common.search') }}</label>
                        <div class="relative">
                            <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                            <input
                                id="search"
                                v-model="search"
                                type="text"
                                :placeholder="t('purchaseOrders.searchPlaceholder')"
                                class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            />
                        </div>
                    </div>
                    <div>
                        <label for="status" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('common.status') }}</label>
                        <select id="status" v-model="selectedStatus" :class="selectClass">
                            <option value="">{{ t('common.allStatuses') }}</option>
                            <option v-for="status in statuses" :key="status" :value="status">
                                {{ statusLabels[status] || status }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label for="supplier" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('purchaseOrders.supplier') }}</label>
                        <select id="supplier" v-model="selectedSupplierId" :class="selectClass">
                            <option value="">{{ t('purchaseOrders.allSuppliers') }}</option>
                            <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">
                                {{ supplier.name }}
                            </option>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <Button type="submit" variant="default" size="sm">
                            <Search :size="14" />
                            {{ t('common.search') }}
                        </Button>
                        <Button type="button" variant="secondary" size="sm" @click="clearFilters">{{ t('common.clear') }}</Button>
                    </div>
                </div>
            </form>
        </Card>

        <PluginSlot slot="before-table" :components="pluginComponents?.beforeTable" />

        <!-- Purchase orders table -->
        <div class="mt-4">
            <DataTable :columns="columns" :rows="purchaseOrders.data" dense>
                <template #cell-po_number="{ row }">
                    <Link :href="route('purchase-orders.show', row.id)" class="font-mono text-xs font-medium text-text-primary hover:text-brand">{{ row.po_number }}</Link>
                </template>
                <template #cell-supplier="{ row }">
                    <span class="text-text-primary">{{ row.supplier?.name || '-' }}</span>
                </template>
                <template #cell-order_date="{ row }">
                    <span class="text-text-secondary">{{ formatDate(row.order_date) }}</span>
                </template>
                <template #cell-expected_date="{ row }">
                    <span class="text-text-secondary">{{ formatDate(row.expected_date) }}</span>
                </template>
                <template #cell-quantity="{ row }">
                    <span class="tabular-nums text-text-secondary">{{ row.items_count || 0 }}</span>
                </template>
                <template #cell-total="{ row }">
                    <span class="font-medium tabular-nums text-text-primary">{{ formatCurrency(row.total, row.currency) }}</span>
                </template>
                <template #cell-status="{ row }">
                    <Badge :variant="statusVariant(row.status)" size="sm" dot>{{ statusLabels[row.status] || row.status }}</Badge>
                </template>
                <template #cell-actions="{ row }">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="route('purchase-orders.show', row.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" :aria-label="t('common.view')" title="View"><Eye :size="16" /></Link>
                        <Link v-if="row.status === 'draft'" :href="route('purchase-orders.edit', row.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-success" :aria-label="t('common.edit')" title="Edit"><Pencil :size="16" /></Link>
                        <Link v-if="row.status === 'sent' || row.status === 'partial'" :href="route('purchase-orders.receive', row.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-success" title="Receive"><PackageCheck :size="16" /></Link>
                        <button v-if="row.status === 'draft'" type="button" @click="deletePO(row)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-danger" :aria-label="t('common.delete')" title="Delete"><Trash2 :size="16" /></button>
                    </div>
                </template>
                <template #empty>
                    <div class="flex flex-col items-center gap-3 py-10">
                        <ClipboardList :size="22" class="text-text-tertiary" />
                        <p class="text-sm font-medium text-text-secondary">{{ t('purchaseOrders.noPoFound') }}</p>
                        <p class="text-sm text-text-tertiary">{{ t('purchaseOrders.getStarted') }}</p>
                        <Button variant="default" size="sm" as="Link" :href="route('purchase-orders.create')">
                            <Plus :size="14" />
                            {{ t('purchaseOrders.createPo') }}
                        </Button>
                    </div>
                </template>
            </DataTable>

            <!-- Pagination -->
            <div v-if="purchaseOrders.data.length > 0" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
                <p class="text-xs text-text-tertiary">
                    {{ t('common.showing') }} <span class="font-medium text-text-secondary">{{ purchaseOrders.from }}</span>
                    {{ t('common.to') }} <span class="font-medium text-text-secondary">{{ purchaseOrders.to }}</span>
                    {{ t('common.of') }} <span class="font-medium text-text-secondary">{{ purchaseOrders.total }}</span> {{ t('common.results') }}
                </p>
                <nav class="inline-flex items-center gap-1">
                    <template v-for="link in purchaseOrders.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            :class="[
                                'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-colors',
                                link.active
                                    ? 'border-brand bg-brand text-brand-foreground'
                                    : 'border-border-subtle bg-surface-canvas text-text-secondary hover:bg-surface-overlay',
                            ]"
                            v-html="link.label"
                        />
                        <span v-else class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-2.5 text-xs text-text-tertiary opacity-50" v-html="link.label" />
                    </template>
                </nav>
            </div>
        </div>

        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
    </AppLayout>
</template>

