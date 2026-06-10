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
import { Plus, Search, Eye, Pencil, Trash2, ShoppingCart } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    orders: Object,
    filters: Object,
    statuses: Array,
    sources: Array,
    pluginComponents: Object,
});

const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');
const source = ref(props.filters?.source || '');

const formatCurrency = (value) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value);

const searchOrders = () => {
    router.get(route('orders.index'), {
        search: search.value,
        status: status.value,
        source: source.value,
    }, { preserveState: true, preserveScroll: true });
};

const clearFilters = () => {
    search.value = '';
    status.value = '';
    source.value = '';
    searchOrders();
};

const deleteOrder = (order) => {
    if (confirm(t('orders.show.confirmDelete', { number: order.order_number }))) {
        router.delete(route('orders.destroy', order.id));
    }
};

const statusVariant = (s) =>
    ({ pending: 'warning', processing: 'info', shipped: 'brand', delivered: 'success', cancelled: 'danger' }[s] || 'neutral');

const columns = [
    { key: 'order_number', label: t('orders.orderCol') },
    { key: 'customer_name', label: t('orders.customer') },
    { key: 'items', label: t('common.items'), align: 'right' },
    { key: 'total', label: t('common.total'), align: 'right' },
    { key: 'status', label: t('common.status') },
    { key: 'source', label: t('orders.source') },
    { key: 'order_date', label: t('common.date') },
    { key: 'actions', label: t('common.actions'), align: 'right' },
];

const selectClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
</script>

<template>
    <Head :title="t('orders.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('orders.title') }}</span>
            </div>
        </template>

        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <PageHeader :title="t('orders.title')" description="Customer orders from every channel.">
            <template #actions>
                <Button variant="default" size="sm" as="Link" :href="route('orders.create')">
                    <Plus :size="14" />
                    {{ t('orders.createOrder') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Filters -->
        <Card class="mt-6">
            <form @submit.prevent="searchOrders" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div class="md:col-span-2">
                        <label for="search" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('orders.searchOrders') }}</label>
                        <div class="relative">
                            <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                            <input
                                id="search"
                                v-model="search"
                                type="text"
                                :placeholder="t('orders.searchPlaceholder')"
                                class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            />
                        </div>
                    </div>
                    <div>
                        <label for="status" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('common.status') }}</label>
                        <select id="status" v-model="status" :class="selectClass">
                            <option value="">{{ t('common.allStatuses') }}</option>
                            <option v-for="stat in statuses" :key="stat" :value="stat">{{ stat.charAt(0).toUpperCase() + stat.slice(1) }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="source" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('orders.source') }}</label>
                        <select id="source" v-model="source" :class="selectClass">
                            <option value="">{{ t('orders.allSources') }}</option>
                            <option v-for="src in sources" :key="src" :value="src">{{ src.charAt(0).toUpperCase() + src.slice(1) }}</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="submit" variant="default" size="sm">
                        <Search :size="14" />
                        {{ t('common.search') }}
                    </Button>
                    <Button type="button" variant="secondary" size="sm" @click="clearFilters">{{ t('common.clearFilters') }}</Button>
                </div>
            </form>
        </Card>

        <PluginSlot slot="before-table" :components="pluginComponents?.beforeTable" />

        <!-- Orders table -->
        <div class="mt-4">
            <DataTable :columns="columns" :rows="orders.data" dense>
                <template #cell-order_number="{ row }">
                    <Link :href="route('orders.show', row.id)" class="font-mono text-xs font-medium text-text-primary hover:text-brand">{{ row.order_number }}</Link>
                </template>
                <template #cell-customer_name="{ row }">
                    <div class="flex flex-col">
                        <span class="text-text-primary">{{ row.customer_name }}</span>
                        <span v-if="row.customer_email" class="text-xs text-text-tertiary">{{ row.customer_email }}</span>
                    </div>
                </template>
                <template #cell-items="{ row }">
                    <span class="tabular-nums text-text-secondary">{{ row.items.length }}</span>
                </template>
                <template #cell-total="{ row }">
                    <span class="font-medium tabular-nums text-text-primary">{{ formatCurrency(row.total) }}</span>
                </template>
                <template #cell-status="{ row }">
                    <Badge :variant="statusVariant(row.status)" size="sm" dot>{{ row.status }}</Badge>
                </template>
                <template #cell-source="{ row }">
                    <Badge variant="neutral" size="sm">{{ row.source }}</Badge>
                </template>
                <template #cell-order_date="{ row }">
                    <span class="text-text-secondary">{{ new Date(row.order_date).toLocaleDateString() }}</span>
                </template>
                <template #cell-actions="{ row }">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="route('orders.show', row.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" :aria-label="t('common.view')"><Eye :size="16" /></Link>
                        <Link :href="route('orders.edit', row.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-success" :aria-label="t('common.edit')"><Pencil :size="16" /></Link>
                        <button type="button" @click="deleteOrder(row)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-danger" :aria-label="t('common.delete')"><Trash2 :size="16" /></button>
                    </div>
                </template>
                <template #empty>
                    <div class="flex flex-col items-center gap-3 py-10">
                        <ShoppingCart :size="22" class="text-text-tertiary" />
                        <p class="text-sm text-text-tertiary">{{ t('orders.noOrdersFound') }}</p>
                        <Button variant="default" size="sm" as="Link" :href="route('orders.create')">
                            <Plus :size="14" />
                            {{ t('orders.createFirstOrder') }}
                        </Button>
                    </div>
                </template>
            </DataTable>

            <!-- Pagination -->
            <div v-if="orders.data.length > 0" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
                <p class="text-xs text-text-tertiary">
                    {{ t('common.showing') }} <span class="font-medium text-text-secondary">{{ orders.from }}</span>
                    {{ t('common.to') }} <span class="font-medium text-text-secondary">{{ orders.to }}</span>
                    {{ t('common.of') }} <span class="font-medium text-text-secondary">{{ orders.total }}</span> {{ t('common.results') }}
                </p>
                <nav class="inline-flex items-center gap-1">
                    <template v-for="link in orders.links" :key="link.label">
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

