<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({ balances: Object, filters: Object });
const page = usePage();
const { t } = useI18n();
const { hasPermission } = usePermissions();
const enabled = (value) => [true, 1, '1', 'true'].includes(value);
const form = useForm({ search: props.filters.search || '', warehouse_id: props.filters.warehouse_id || '', out_of_stock: enabled(props.filters.out_of_stock), include_zero: enabled(props.filters.include_zero), sort_by: props.filters.sort_by || 'sku', sort_dir: props.filters.sort_dir || 'asc' });
const columns = computed(() => [
    ['sku', t('products.sku')], ['product_name', t('common.product')], ['variant_title', t('orders.create.variant')],
    ['warehouse_name', t('locations.warehouse')], ['location_name', t('products.location')],
    ['on_hand', t('products.stock')], ['reserved', t('components.variantsTable.reserved')],
    ['available', t('components.variantsTable.available')], ['in_transit', t('stockTransfers.statuses.in_transit')],
]);
const apply = () => form.get(route('stock.index'), { preserveState: true, preserveScroll: true });
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
    <AppLayout>
        <PageHeader :title="t('warehouseStock.title')">
            <template #actions><Button v-if="hasPermission('export_data')" as="a" :href="exportUrl" variant="secondary">{{ t('common.export') }} CSV</Button></template>
        </PageHeader>
        <Card class="mt-5" :padded="false">
            <form class="flex flex-wrap items-end gap-3 border-b border-border-subtle p-4" @submit.prevent="apply">
                <label class="min-w-48 flex-1 text-xs text-text-secondary">{{ t('common.search') }}<input v-model="form.search" :class="field + ' mt-1 w-full'" :placeholder="t('products.searchPlaceholder')" /></label>
                <label class="text-xs text-text-secondary">{{ t('locations.warehouse') }}<select v-model="form.warehouse_id" :class="field + ' mt-1 block'">
                    <option value="">{{ t('warehouses.allWarehouses') }}</option>
                    <option v-for="warehouse in page.props.warehouses || []" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                </select></label>
                <label class="flex min-h-9 items-center gap-2 text-sm"><input v-model="form.out_of_stock" type="checkbox" />{{ t('reports.lowStock.outOfStock') }}</label>
                <label class="flex min-h-9 items-center gap-2 text-sm"><input v-model="form.include_zero" type="checkbox" />{{ t('warehouseStock.includeZero') }}</label>
                <Button type="submit" :loading="form.processing">{{ t('common.search') }}</Button>
            </form>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b border-border-subtle bg-surface-sunken">
                        <th v-for="[key, label] in columns" :key="key" :aria-sort="form.sort_by === key ? form.sort_dir === 'asc' ? 'ascending' : 'descending' : 'none'" class="whitespace-nowrap px-4 py-3 text-xs font-medium text-text-secondary">
                            <button type="button" class="rounded ds-focus-ring" :aria-label="label" @click="sort(key)">{{ label }} <span v-if="form.sort_by === key" aria-hidden="true">{{ form.sort_dir === 'asc' ? '↑' : '↓' }}</span></button>
                        </th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="row in balances.data" :key="`${row.product_id}:${row.product_variant_id}:${row.location_id}`" class="border-b border-border-subtle last:border-0">
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs"><Link :href="route('products.show', row.product_id)" class="text-brand hover:underline">{{ row.sku }}</Link></td>
                            <td class="px-4 py-3">{{ row.product_name }}</td><td class="px-4 py-3">{{ row.variant_title || t('common.na') }}</td>
                            <td class="px-4 py-3">{{ row.warehouse_name || t('locations.noWarehouse') }}</td><td class="px-4 py-3">{{ row.location_name || t('common.na') }}</td>
                            <td v-for="key in ['on_hand', 'reserved', 'available', 'in_transit']" :key="key" class="px-4 py-3 text-right tabular-nums" :class="key === 'available' && row.available === 0 ? 'font-semibold text-status-danger' : ''">{{ row[key] }}</td>
                        </tr>
                        <tr v-if="!balances.data.length"><td colspan="9" class="p-8 text-center text-text-secondary">{{ t('common.noResults') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </Card>
        <nav class="mt-4 flex flex-wrap gap-2" :aria-label="t('common.pagination')">
            <template v-for="link in balances.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" class="rounded-md border border-border-subtle px-3 py-2 text-sm ds-focus-ring" :class="link.active ? 'bg-brand text-brand-foreground' : 'bg-surface-canvas'" v-html="link.label" />
                <span v-else class="rounded-md border border-border-subtle px-3 py-2 text-sm text-text-tertiary" v-html="link.label" />
            </template>
        </nav>
    </AppLayout>
</template>
