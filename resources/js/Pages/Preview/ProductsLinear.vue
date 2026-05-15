<script setup>
/**
 * Linear-inspired products list preview.
 * Renders against the same data Products/Index.vue receives.
 *
 * Visit: /preview/products
 *
 * Demonstrates the redesigned list pattern: dense table, monochromatic
 * status pills, inline search + filter chips, hoverable rows.
 */
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

const debounce = (fn, ms = 250) => {
    let t;
    return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), ms);
    };
};
import {
    Boxes,
    Plus,
    Filter,
    Search,
    Download,
    Package,
} from 'lucide-vue-next';

import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Input from '@/Components/ui/Input.vue';
import DataTable from '@/Components/ui/DataTable.vue';

const props = defineProps({
    products: Object,
    filters: Object,
    categories: Array,
    locations: Array,
});

const search = ref(props.filters?.search ?? '');
const lowStockOnly = ref(!!props.filters?.low_stock);
const categoryFilter = ref(props.filters?.category ?? '');

const fmtMoney = (v) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: 'USD',
}).format(Number(v ?? 0));

const stockTone = (p) => {
    if (p.stock <= 0) return 'danger';
    if (p.min_stock != null && p.stock <= p.min_stock) return 'warning';
    return 'success';
};

const reload = () => {
    router.get(route('preview.products'), {
        search: search.value || undefined,
        category: categoryFilter.value || undefined,
        low_stock: lowStockOnly.value ? 1 : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const debouncedReload = debounce(reload, 300);

watch(search, debouncedReload);
watch([lowStockOnly, categoryFilter], reload);

const productRows = computed(() => props.products?.data ?? []);

const columns = [
    { key: 'name', label: 'Product' },
    { key: 'sku', label: 'SKU' },
    { key: 'category', label: 'Category' },
    { key: 'price', label: 'Price', align: 'right' },
    { key: 'stock', label: 'Stock', align: 'right' },
    { key: 'is_active', label: 'Status', align: 'right' },
];

const totalCount = computed(() => props.products?.total ?? 0);
</script>

<template>
    <Head title="Products (preview)" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="text-text-primary font-medium">Inventory</span>
                <Badge variant="brand" size="sm" class="ml-2">Preview</Badge>
            </div>
        </template>

        <PageHeader
            title="Inventory"
            :description="`${totalCount} products in catalog`"
        >
            <template #actions>
                <Button variant="secondary" size="sm">
                    <Download :size="14" />
                    Export
                </Button>
                <Button variant="default" size="sm" as="Link" :href="route('products.create')">
                    <Plus :size="14" />
                    New product
                </Button>
            </template>
        </PageHeader>

        <!-- Filter strip -->
        <div class="mt-6 flex flex-wrap items-center gap-2">
            <div class="flex-1 min-w-64 max-w-md">
                <Input
                    v-model="search"
                    placeholder="Search by name, SKU, or barcode…"
                    size="sm"
                >
                    <template #prefix>
                        <Search :size="14" />
                    </template>
                </Input>
            </div>

            <button
                @click="lowStockOnly = !lowStockOnly"
                :class="[
                    'inline-flex items-center gap-1.5 h-8 px-3 rounded-md text-xs font-medium border transition-colors ds-focus-ring',
                    lowStockOnly
                        ? 'bg-status-warning-soft text-status-warning border-status-warning/20'
                        : 'bg-surface-canvas text-text-secondary border-border-subtle hover:bg-surface-overlay',
                ]"
            >
                <Filter :size="13" />
                Low stock
            </button>

            <select
                v-model="categoryFilter"
                class="h-8 px-2 pr-8 text-xs bg-surface-canvas text-text-primary border border-border-subtle rounded-md
                       hover:border-border-strong transition-colors ds-focus-ring"
            >
                <option value="">All categories</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
        </div>

        <!-- Table -->
        <div class="mt-4">
            <DataTable
                :columns="columns"
                :rows="productRows"
                :row-href="(p) => route('products.show', p.id)"
                dense
            >
                <template #cell-name="{ row }">
                    <div class="flex items-center gap-3">
                        <span class="h-7 w-7 rounded-md bg-surface-overlay grid place-items-center shrink-0">
                            <Package :size="14" class="text-text-tertiary" />
                        </span>
                        <div class="min-w-0">
                            <p class="font-medium text-text-primary truncate">{{ row.name }}</p>
                            <p v-if="row.barcode" class="text-xs text-text-tertiary font-mono">{{ row.barcode }}</p>
                        </div>
                    </div>
                </template>

                <template #cell-sku="{ row }">
                    <span class="font-mono text-xs text-text-secondary">{{ row.sku }}</span>
                </template>

                <template #cell-category="{ row }">
                    <span v-if="row.category" class="text-text-secondary">{{ row.category.name }}</span>
                    <span v-else class="text-text-tertiary">—</span>
                </template>

                <template #cell-price="{ row }">
                    <span class="font-medium tabular-nums">{{ fmtMoney(row.price) }}</span>
                </template>

                <template #cell-stock="{ row }">
                    <Badge :variant="stockTone(row)" size="sm" dot>
                        {{ row.stock }}<span v-if="row.min_stock != null" class="text-text-tertiary"> / {{ row.min_stock }}</span>
                    </Badge>
                </template>

                <template #cell-is_active="{ row }">
                    <Badge :variant="row.is_active ? 'success' : 'neutral'" size="sm">
                        {{ row.is_active ? 'Active' : 'Inactive' }}
                    </Badge>
                </template>

                <template #empty>
                    <div class="flex flex-col items-center gap-3 py-10">
                        <Boxes :size="24" class="text-text-tertiary" />
                        <div class="text-center">
                            <p class="text-sm font-medium text-text-primary">No products found</p>
                            <p class="text-xs text-text-tertiary mt-0.5">Try clearing filters or add a new product.</p>
                        </div>
                        <Button variant="default" size="sm" as="Link" :href="route('products.create')">
                            <Plus :size="14" />
                            New product
                        </Button>
                    </div>
                </template>
            </DataTable>
        </div>

        <!-- Pagination -->
        <div v-if="products?.last_page > 1" class="mt-4 flex items-center justify-between text-xs text-text-tertiary">
            <p>
                Showing {{ products.from }}–{{ products.to }} of {{ products.total }}
            </p>
            <div class="flex items-center gap-1">
                <Button
                    v-for="link in products.links"
                    :key="link.label"
                    :as="link.url ? 'Link' : 'button'"
                    :href="link.url"
                    :disabled="!link.url || link.active"
                    :variant="link.active ? 'secondary' : 'ghost'"
                    size="xs"
                >
                    <span v-html="link.label" />
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
