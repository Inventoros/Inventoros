<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    Boxes,
    Layers,
    DollarSign,
    TrendingUp,
    Download,
    ArrowLeft,
} from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    products: Array,
    summary: Object,
    byCategory: Array,
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};

const exportCsv = () => {
    const headers = [
        t('common.product'),
        'SKU',
        t('products.category'),
        t('products.stock'),
        t('common.price'),
        t('reports.inventoryValuation.stockValue'),
        t('reports.inventoryValuation.profitPotential'),
    ];
    const escape = (val) => `"${String(val ?? '').replace(/"/g, '""')}"`;
    const rows = props.products.map((p) => [
        p.name,
        p.sku,
        p.category || t('reports.inventoryValuation.uncategorized'),
        p.stock,
        p.price,
        p.stock_value,
        p.profit_potential,
    ]);
    const csv = [headers, ...rows].map((row) => row.map(escape).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'inventory-valuation.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
};

const thClass =
    'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
const thRightClass =
    'px-4 py-2.5 text-right text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('reports.inventoryValuation.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('reports.index')" class="text-text-tertiary transition-colors hover:text-text-primary">{{ t('reports.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('reports.inventoryValuation.title') }}</span>
            </div>
        </template>

        <PageHeader
            :title="t('reports.inventoryValuation.title')"
            :description="t('reports.inventoryValuation.description')"
        >
            <template #actions>
                <Button variant="secondary" size="sm" @click="exportCsv">
                    <Download :size="14" />
                    {{ t('reportBuilder.actions.export') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('reports.index')">
                    <ArrowLeft :size="14" />
                    {{ t('reports.backToReports') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Summary stats -->
        <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile
                :label="t('reports.inventoryValuation.totalItems')"
                :value="summary.total_items"
                :hint="t('reports.inventoryValuation.productSkus')"
                icon-tone="brand"
            >
                <template #icon><Boxes :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.inventoryValuation.totalQuantity')"
                :value="summary.total_quantity"
                :hint="t('reports.inventoryValuation.unitsInStock')"
                icon-tone="info"
            >
                <template #icon><Layers :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.inventoryValuation.stockValue')"
                :value="formatCurrency(summary.total_stock_value)"
                :hint="t('reports.inventoryValuation.atSellingPrice')"
                icon-tone="success"
            >
                <template #icon><DollarSign :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.inventoryValuation.profitPotential')"
                :value="formatCurrency(summary.total_profit_potential)"
                :hint="t('reports.inventoryValuation.ifAllSold')"
                icon-tone="brand"
            >
                <template #icon><TrendingUp :size="18" /></template>
            </StatTile>
        </section>

        <!-- By Category -->
        <section class="mt-4">
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('reports.inventoryValuation.valueByCategory')" />
                </div>
                <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="cat in byCategory"
                        :key="cat.category"
                        class="rounded-lg border border-border-subtle bg-surface-canvas p-4 transition-colors hover:border-border-strong"
                    >
                        <p class="text-sm font-medium text-text-primary">{{ cat.category }}</p>
                        <div class="mt-2 space-y-1">
                            <div class="flex justify-between text-sm">
                                <span class="text-text-tertiary">{{ t('common.items') }}</span>
                                <span class="font-semibold tabular-nums text-text-primary">{{ cat.items }}</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-text-tertiary">{{ t('common.quantity') }}</span>
                                <span class="font-semibold tabular-nums text-text-primary">{{ cat.quantity }}</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-text-tertiary">{{ t('reports.inventoryValuation.stockValue') }}</span>
                                <span class="font-semibold tabular-nums text-status-success">{{ formatCurrency(cat.value) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </Card>
        </section>

        <!-- Product details -->
        <section class="mt-4">
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('reports.inventoryValuation.productDetails')" />
                </div>
                <div class="p-3">
                    <div class="w-full overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border-subtle">
                                    <th :class="thClass">{{ t('common.product') }}</th>
                                    <th :class="thClass">{{ t('products.category') }}</th>
                                    <th :class="thRightClass">{{ t('products.stock') }}</th>
                                    <th :class="thRightClass">{{ t('common.price') }}</th>
                                    <th :class="thRightClass">{{ t('reports.inventoryValuation.stockValue') }}</th>
                                    <th :class="thRightClass">{{ t('reports.inventoryValuation.profitPotential') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="product in products"
                                    :key="product.id"
                                    class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                                >
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-text-primary">{{ product.name }}</p>
                                        <p class="font-mono text-xs text-text-tertiary">{{ product.sku }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-text-secondary">
                                        {{ product.category || t('reports.inventoryValuation.uncategorized') }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium tabular-nums text-text-primary">
                                        {{ product.stock }}
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-text-secondary">
                                        {{ formatCurrency(product.price) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-status-success">
                                        {{ formatCurrency(product.stock_value) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-status-success">
                                        {{ formatCurrency(product.profit_potential) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </Card>
        </section>
    </AppLayout>
</template>
