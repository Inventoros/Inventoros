<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import TableViewport from '@/Components/ui/TableViewport.vue';
import TableFilters from '@/Components/ui/TableFilters.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({ balances: Object, filters: Object });
const page = usePage();
const { t } = useI18n();
const { hasPermission } = usePermissions();
const enabled = (value) => [true, 1, '1', 'true'].includes(value);
const form = useForm({ search: props.filters.search || '', warehouse_id: props.filters.warehouse_id || '', location_id: props.filters.location_id || '', out_of_stock: enabled(props.filters.out_of_stock), include_zero: enabled(props.filters.include_zero), sort_by: props.filters.sort_by || 'sku', sort_dir: props.filters.sort_dir || 'asc' });
const columns = computed(() => [
    ['sku', 'SKU'], ['product_name', t('common.product')], ['variant_title', t('orders.create.variant')],
    ['warehouse_name', t('locations.warehouse')], ['location_name', t('products.location')],
    ['on_hand', t('warehouseStock.onHand')], ['reserved', t('components.variantsTable.reserved')],
    ['available', t('components.variantsTable.available')], ['in_transit', t('stockTransfers.statuses.in_transit')],
]);
const apply = () => form.get(route('stock.index'), { preserveState: true, preserveScroll: true });
const clearFilters = () => {
    form.search = '';
    form.warehouse_id = '';
    form.location_id = '';
    form.out_of_stock = false;
    form.include_zero = false;
    apply();
};
const hasFilters = computed(() => Boolean(props.filters.search || props.filters.warehouse_id || props.filters.location_id
    || enabled(props.filters.out_of_stock) || enabled(props.filters.include_zero)));
const rowKey = row => [row.product_id, row.product_variant_id ?? 'none', row.warehouse_id ?? 'none', row.location_id ?? 'none'].join(':');
const sort = (key) => {
    form.sort_dir = form.sort_by === key && form.sort_dir === 'asc' ? 'desc' : 'asc';
    form.sort_by = key;
    apply();
};
const exportUrl = computed(() => route('stock.export', form.data()));
const field = 'h-9 rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm ds-focus-ring';
</script>

<template>
    <Head :title="t('warehouseStock.title')" />
    <AppLayout table-page>
        <PageHeader :title="t('warehouseStock.title')">
            <template #actions><Button v-if="hasPermission('export_data')" as="a" :href="exportUrl" variant="secondary">{{ t('common.export') }} CSV</Button></template>
        </PageHeader>
        <Card class="stock-layout mt-5 table-frame" :padded="false">
            <TableFilters :padded="false">
                <template #quick>
                    <form class="table-quick-search" @submit.prevent="apply">
                        <label for="stock-search" class="sr-only">{{ t('common.search') }}</label>
                        <input id="stock-search" v-model="form.search" :placeholder="t('products.searchPlaceholder')" />
                        <button type="submit" class="ds-focus-ring">{{ t('common.search') }}</button>
                    </form>
                </template>
            <form class="flex flex-wrap items-end gap-3 p-4" @submit.prevent="apply">
                <label class="text-xs text-text-secondary">{{ t('locations.warehouse') }}<select v-model="form.warehouse_id" :class="field + ' mt-1 block'">
                    <option value="">{{ t('warehouses.allWarehouses') }}</option>
                    <option v-for="warehouse in page.props.warehouses || []" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                </select></label>
                <label class="flex min-h-9 items-center gap-2 text-sm"><input v-model="form.out_of_stock" type="checkbox" />{{ t('reports.lowStock.outOfStock') }}</label>
                <label class="flex min-h-9 items-center gap-2 text-sm"><input v-model="form.include_zero" type="checkbox" />{{ t('warehouseStock.includeZero') }}</label>
                <Button type="submit" :loading="form.processing">{{ t('common.search') }}</Button>
            </form>
            </TableFilters>
            <div class="stock-compact-sort">
                <div class="stock-sort-field">
                    <label for="stock-sort-by">{{ t('common.sortBy') }}</label>
                    <select id="stock-sort-by" v-model="form.sort_by" :disabled="form.processing" class="ds-focus-ring" @change="apply">
                        <option v-for="[key, label] in columns" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>
                <div class="stock-sort-field">
                    <label for="stock-sort-direction">{{ t('common.sortDirection') }}</label>
                    <select id="stock-sort-direction" v-model="form.sort_dir" :disabled="form.processing" class="ds-focus-ring" @change="apply">
                        <option value="asc">{{ t('common.ascending') }}</option>
                        <option value="desc">{{ t('common.descending') }}</option>
                    </select>
                </div>
            </div>
            <TableViewport :empty="!balances.data.length" :label="t('warehouseStock.title')" class="stock-panel overflow-x-auto">
                <table role="table" :aria-label="t('warehouseStock.title')" class="stock-table w-full text-start text-sm">
                    <thead role="rowgroup"><tr role="row" class="border-b border-border-subtle bg-surface-sunken">
                        <th v-for="[key, label] in columns" :key="key" role="columnheader" scope="col" :aria-sort="form.sort_by === key ? form.sort_dir === 'asc' ? 'ascending' : 'descending' : 'none'" class="whitespace-nowrap px-4 py-3 text-xs font-medium text-text-secondary">
                            <span class="stock-compact-heading">{{ label }}</span>
                            <button type="button" class="stock-table-sort rounded ds-focus-ring" :aria-label="label" @click="sort(key)">{{ label }} <span v-if="form.sort_by === key" aria-hidden="true">{{ form.sort_dir === 'asc' ? '↑' : '↓' }}</span></button>
                        </th>
                    </tr></thead>
                    <tbody role="rowgroup">
                        <tr v-for="row in balances.data" :key="rowKey(row)" role="row" class="stock-record border-b border-border-subtle last:border-0">
                            <td role="cell" class="stock-cell stock-sku whitespace-nowrap px-4 py-3 font-mono text-xs"><Link :href="route('products.show', row.product_id)" class="text-brand hover:underline ds-focus-ring">{{ row.sku }}</Link></td>
                            <td role="cell" class="stock-cell stock-product px-4 py-3">{{ row.product_name }}</td>
                            <td role="cell" class="stock-cell stock-variant px-4 py-3" :class="{ 'stock-no-variant': !row.variant_title }">{{ row.variant_title || t('common.na') }}</td>
                            <td role="cell" class="stock-cell stock-warehouse px-4 py-3"><span class="stock-record-label" aria-hidden="true">{{ t('locations.warehouse') }}</span>{{ row.warehouse_name || t('locations.noWarehouse') }}</td>
                            <td role="cell" class="stock-cell stock-location px-4 py-3"><span class="stock-record-label" aria-hidden="true">{{ t('products.location') }}</span>{{ row.location_name || t('common.na') }}</td>
                            <td v-for="key in ['on_hand', 'reserved', 'available', 'in_transit']" :key="key" role="cell" class="stock-cell stock-quantity px-4 py-3 text-end tabular-nums" :class="[`stock-${key}`, key === 'available' && row.available === 0 ? 'font-semibold text-status-danger' : '']">
                                <span class="stock-record-label" aria-hidden="true">{{ columns.find(column => column[0] === key)[1] }}</span>
                                <span class="stock-quantity-value">{{ row[key] }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <template #empty><EmptyState :title="t('common.noResults')" :filtered="hasFilters" @clear="clearFilters" /></template>
                <template #footer>
                    <nav class="flex flex-wrap gap-2 table-pagination" :aria-label="t('common.pagination')">
                        <template v-for="link in balances.links" :key="link.label">
                            <Link v-if="link.url" :href="link.url" class="rounded-md border border-border-subtle px-3 py-2 text-sm ds-focus-ring" :class="link.active ? 'bg-brand-solid text-brand-solid-foreground' : 'bg-surface-canvas'" v-html="link.label" />
                            <span v-else class="rounded-md border border-border-subtle px-3 py-2 text-sm text-text-tertiary" v-html="link.label" />
                        </template>
                    </nav>
                </template>
            </TableViewport>
        </Card>
    </AppLayout>
</template>

<style scoped>
.stock-layout { container: stock-balances / inline-size; }
.stock-compact-sort, .stock-compact-heading, .stock-record-label { display: none; }

/* Keep the same rows, values and sort state; narrow panels expose the stock
   quantities as records instead of asking a phone user to scroll nine columns. */
@container stock-balances (max-width: 52rem) {
    .stock-compact-sort { display: flex; gap: 12px; padding: 12px; border-bottom: 1px solid hsl(var(--border-subtle)); }
    .stock-sort-field { min-width: 0; flex: 1; }
    .stock-sort-field label { display: block; margin-bottom: 4px; color: hsl(var(--text-secondary)); font-size: 12px; }
    .stock-sort-field select { width: 100%; min-height: 44px; border: 1px solid hsl(var(--border-subtle)); border-radius: 6px; background: hsl(var(--surface-canvas)); color: hsl(var(--text-primary)); font-size: 13px; }
    .stock-table { display: block; width: 100%; }
    .stock-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
    .stock-layout .stock-table th { position: static; }
    .stock-table-sort { display: none; }
    .stock-compact-heading { display: inline; }
    .stock-table tbody { display: block; }
    .stock-record { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px 12px; padding: 12px 16px 16px; }
    .stock-layout .stock-table .stock-cell { min-width: 0; height: auto; padding: 0; border: 0; text-align: start; overflow-wrap: anywhere; white-space: normal; }
    .stock-sku { grid-column: 1 / -1; }
    .stock-sku a { display: inline-flex; min-height: 44px; max-width: 100%; align-items: center; }
    .stock-product { grid-column: 1 / -1; font-weight: 600; }
    .stock-variant { grid-column: 1 / -1; color: hsl(var(--text-secondary)); }
    .stock-layout .stock-table .stock-no-variant { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
    .stock-warehouse { grid-column: 1 / 4; }
    .stock-location { grid-column: 4 / 7; }
    .stock-layout .stock-table .stock-available { grid-column: 1 / -1; order: 1; display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px; padding-block: 12px; border-block: 1px solid hsl(var(--border-subtle)); }
    .stock-layout .stock-available .stock-quantity-value { font-size: 26px; line-height: 32px; font-weight: 600; }
    .stock-on_hand { grid-column: 1 / 3; order: 2; }
    .stock-reserved { grid-column: 3 / 5; order: 2; }
    .stock-in_transit { grid-column: 5 / 7; order: 2; }
    .stock-record-label { display: block; margin-bottom: 4px; color: hsl(var(--text-secondary)); font-size: 12px; font-weight: 400; }
    .stock-quantity-value { font-size: 16px; font-weight: 500; }
    .stock-layout :deep(.table-expand-button) { display: none; }
}
</style>
