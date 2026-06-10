<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Search, Eye, Settings } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    workOrders: Object,
    filters: Object,
});

const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || '');

const applyFilters = () => {
    router.get(route('work-orders.index'), {
        search: search.value,
        status: selectedStatus.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    selectedStatus.value = '';
    applyFilters();
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

const formatDate = (dateStr) => {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const selectClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';

const thClass =
    'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
</script>

<template>
    <Head title="Work Orders" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">Work Orders</span>
            </div>
        </template>

        <PageHeader title="Work Orders" description="Manage assembly production work orders.">
            <template #actions>
                <Button variant="default" size="sm" as="Link" :href="route('work-orders.create')">
                    <Plus :size="14" />
                    Create Work Order
                </Button>
            </template>
        </PageHeader>

        <!-- Filters -->
        <Card class="mt-6">
            <form @submit.prevent="applyFilters" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div class="md:col-span-2">
                        <label for="search" class="mb-1 block text-xs font-medium text-text-secondary">Search</label>
                        <div class="relative">
                            <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                            <input
                                id="search"
                                v-model="search"
                                type="text"
                                placeholder="Search by WO number or product name..."
                                class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            />
                        </div>
                    </div>
                    <div>
                        <label for="status" class="mb-1 block text-xs font-medium text-text-secondary">Status</label>
                        <select id="status" v-model="selectedStatus" :class="selectClass" @change="applyFilters">
                            <option value="">All Statuses</option>
                            <option value="draft">Draft</option>
                            <option value="pending">Pending</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="submit" variant="default" size="sm">
                        <Search :size="14" />
                        Filter
                    </Button>
                    <Button type="button" variant="secondary" size="sm" @click="clearFilters">Clear</Button>
                </div>
            </form>
        </Card>

        <!-- Work orders table -->
        <div class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">WO Number</th>
                        <th :class="thClass">Assembly Product</th>
                        <th :class="[thClass, 'text-center']">Quantity</th>
                        <th :class="[thClass, 'text-center']">Produced</th>
                        <th :class="thClass">Status</th>
                        <th :class="thClass">Created By</th>
                        <th :class="thClass">Date</th>
                        <th :class="[thClass, 'text-right']">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Empty State -->
                    <tr v-if="!workOrders.data || workOrders.data.length === 0">
                        <td colspan="8" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <Settings :size="22" class="text-text-tertiary" />
                                <p class="text-sm font-medium text-text-primary">No work orders</p>
                                <p class="text-sm text-text-tertiary">Get started by creating a new work order for an assembly product.</p>
                                <Button variant="default" size="sm" as="Link" :href="route('work-orders.create')">
                                    <Plus :size="14" />
                                    Create Work Order
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-for="wo in workOrders.data" :key="wo.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                        <td class="px-4 py-3">
                            <Link :href="route('work-orders.show', wo.id)" class="font-medium text-text-primary hover:text-brand">
                                {{ wo.wo_number }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-text-primary">{{ wo.product?.name || '-' }}</div>
                            <div class="text-xs text-text-tertiary">{{ wo.product?.sku || '' }}</div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="tabular-nums text-text-secondary">{{ wo.quantity }}</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span :class="['tabular-nums', wo.quantity_produced >= wo.quantity ? 'text-status-success' : 'text-text-secondary']">
                                {{ wo.quantity_produced || 0 }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="statusVariant(wo.status)" size="sm" dot>{{ getStatusLabel(wo.status) }}</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-text-secondary">{{ wo.created_by?.name || '-' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-text-secondary">{{ formatDate(wo.created_at) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Link :href="route('work-orders.show', wo.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" title="View"><Eye :size="16" /></Link>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="workOrders.links && workOrders.links.length > 3" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-xs text-text-tertiary">
                Showing <span class="font-medium text-text-secondary">{{ workOrders.from }}</span>
                to <span class="font-medium text-text-secondary">{{ workOrders.to }}</span>
                of <span class="font-medium text-text-secondary">{{ workOrders.total }}</span> results
            </p>
            <nav class="inline-flex items-center gap-1">
                <template v-for="link in workOrders.links" :key="link.label">
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
                        preserve-scroll
                    />
                    <span v-else class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-2.5 text-xs text-text-tertiary opacity-50" v-html="link.label" />
                </template>
            </nav>
        </div>
    </AppLayout>
</template>

