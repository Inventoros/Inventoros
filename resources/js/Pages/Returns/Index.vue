<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Search, Eye, RotateCcw } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    returns: Object,
    filters: Object,
    statuses: Array,
    types: Array,
});

const search = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || '');
const typeFilter = ref(props.filters?.type || '');

let searchTimeout = null;

const applyFilters = () => {
    router.get(route('returns.index'), {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        type: typeFilter.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
};

watch(search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 300);
});

watch([statusFilter, typeFilter], applyFilters);

const statusVariant = (status) =>
    ({ pending: 'warning', approved: 'info', received: 'brand', completed: 'success', rejected: 'danger' }[status] || 'neutral');

const typeVariant = (type) => (type === 'exchange' ? 'info' : 'warning');

const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const totalItems = (returnOrder) => {
    if (!returnOrder.items) return 0;
    return returnOrder.items.reduce((sum, item) => sum + item.quantity, 0);
};

const thClass =
    'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';

const selectClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
</script>

<template>
    <Head title="Returns & Exchanges" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">Returns</span>
            </div>
        </template>

        <PageHeader title="Returns & Exchanges" description="Track returns, exchanges, and refunds across every order.">
            <template #actions>
                <Button variant="default" size="sm" as="Link" :href="route('returns.create')">
                    <RotateCcw :size="14" />
                    New Return
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
                                placeholder="Search by return number, order number, or customer..."
                                class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            />
                        </div>
                    </div>
                    <div>
                        <label for="status" class="mb-1 block text-xs font-medium text-text-secondary">Status</label>
                        <select id="status" v-model="statusFilter" :class="selectClass">
                            <option value="">All Statuses</option>
                            <option v-for="status in statuses" :key="status" :value="status">
                                {{ status.charAt(0).toUpperCase() + status.slice(1) }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label for="type" class="mb-1 block text-xs font-medium text-text-secondary">Type</label>
                        <select id="type" v-model="typeFilter" :class="selectClass">
                            <option value="">All Types</option>
                            <option v-for="type in types" :key="type" :value="type">
                                {{ type.charAt(0).toUpperCase() + type.slice(1) }}
                            </option>
                        </select>
                    </div>
                </div>
            </form>
        </Card>

        <!-- Returns table -->
        <div class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">Return #</th>
                        <th :class="thClass">Order #</th>
                        <th :class="thClass">Type</th>
                        <th :class="thClass">Status</th>
                        <th :class="thClass">Items</th>
                        <th :class="thClass">Refund</th>
                        <th :class="thClass">Date</th>
                        <th :class="[thClass, 'text-right']">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!returns.data || returns.data.length === 0">
                        <td colspan="8" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <RotateCcw :size="22" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">No returns found.</p>
                            </div>
                        </td>
                    </tr>
                    <tr v-for="returnOrder in returns.data" :key="returnOrder.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                        <td class="px-4 py-3">
                            <span class="font-medium text-text-primary">{{ returnOrder.return_number }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <Link v-if="returnOrder.order" :href="route('orders.show', returnOrder.order_id)" class="text-brand hover:underline">
                                {{ returnOrder.order.order_number }}
                            </Link>
                            <span v-else class="text-text-secondary">-</span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="typeVariant(returnOrder.type)" size="sm">{{ returnOrder.type }}</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="statusVariant(returnOrder.status)" size="sm" dot>{{ returnOrder.status }}</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <span class="tabular-nums text-text-secondary">{{ totalItems(returnOrder) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-medium tabular-nums text-text-primary">${{ parseFloat(returnOrder.refund_amount || 0).toFixed(2) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-text-secondary">{{ formatDate(returnOrder.created_at) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Link :href="route('returns.show', returnOrder.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" :title="t('common.view')"><Eye :size="16" /></Link>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="returns.links && returns.links.length > 3" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-xs text-text-tertiary">
                Showing <span class="font-medium text-text-secondary">{{ returns.from }}</span>
                to <span class="font-medium text-text-secondary">{{ returns.to }}</span>
                of <span class="font-medium text-text-secondary">{{ returns.total }}</span> results
            </p>
            <nav class="inline-flex items-center gap-1">
                <template v-for="link in returns.links" :key="link.label">
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
    </AppLayout>
</template>

