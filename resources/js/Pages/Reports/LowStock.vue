<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    AlertTriangle,
    PackageX,
    DollarSign,
    ArrowLeft,
    CheckCircle2,
} from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    products: Array,
    summary: Object,
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};

const statusVariant = (status) => (status === 'out_of_stock' ? 'danger' : 'warning');

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
const thClassRight = 'px-4 py-2.5 text-right text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('reports.lowStock.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('reports.index')" class="text-text-tertiary transition-colors hover:text-text-primary">{{ t('reports.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('reports.lowStock.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('reports.lowStock.title')" :description="t('reports.lowStock.description')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('reports.index')">
                    <ArrowLeft :size="14" />
                    {{ t('reports.backToReports') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Summary metrics -->
        <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile
                :label="t('reports.lowStock.totalLowStock')"
                :value="summary.total_low_stock"
                :hint="t('common.products')"
                icon-tone="warning"
            >
                <template #icon><AlertTriangle :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.lowStock.outOfStock')"
                :value="summary.out_of_stock"
                :hint="t('reports.lowStock.critical')"
                icon-tone="warning"
            >
                <template #icon><PackageX :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.lowStock.lowStockWarning')"
                :value="summary.low_stock"
                :hint="t('reports.lowStock.warning')"
                icon-tone="warning"
            >
                <template #icon><AlertTriangle :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.lowStock.reorderCost')"
                :value="formatCurrency(summary.total_reorder_cost)"
                :hint="t('reports.lowStock.estimated')"
                icon-tone="brand"
            >
                <template #icon><DollarSign :size="18" /></template>
            </StatTile>
        </section>

        <!-- Low stock products table -->
        <Card class="mt-4" :padded="false">
            <div class="px-5 pt-5">
                <CardHeader :title="t('reports.lowStock.productsRequiringAttention')" />
            </div>

            <div v-if="products.length > 0" class="w-full overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-subtle">
                            <th :class="thClass">{{ t('common.status') }}</th>
                            <th :class="thClass">{{ t('common.product') }}</th>
                            <th :class="thClass">{{ t('products.category') }}</th>
                            <th :class="thClassRight">{{ t('reports.lowStock.current') }}</th>
                            <th :class="thClassRight">Min</th>
                            <th :class="thClassRight">Max</th>
                            <th :class="thClassRight">{{ t('reports.lowStock.deficit') }}</th>
                            <th :class="thClassRight">{{ t('reports.lowStock.reorderCost') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="product in products"
                            :key="product.id"
                            class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                        >
                            <td class="px-4 py-3">
                                <Badge :variant="statusVariant(product.status)" size="sm" dot class="capitalize">
                                    {{ product.status.replace('_', ' ') }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-medium text-text-primary">{{ product.name }}</p>
                                <p class="font-mono text-xs text-text-tertiary">SKU: {{ product.sku }}</p>
                            </td>
                            <td class="px-4 py-3 text-text-secondary">
                                {{ product.category || t('reports.inventoryValuation.uncategorized') }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <span :class="['font-medium tabular-nums', product.current_stock === 0 ? 'text-status-danger' : 'text-status-warning']">
                                        {{ product.current_stock }}
                                    </span>
                                    <AlertTriangle :size="14" class="text-status-danger" />
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ product.min_stock }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ product.max_stock || '-' }}</td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-status-danger">-{{ product.deficit }}</td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-text-primary">{{ formatCurrency(product.reorder_cost) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="flex flex-col items-center gap-2 px-5 py-12 text-center">
                <CheckCircle2 :size="22" class="text-status-success" />
                <p class="text-sm font-medium text-text-primary">{{ t('reports.lowStock.allWellStocked') }}</p>
                <p class="text-sm text-text-tertiary">{{ t('reports.lowStock.noProductsBelowMin') }}</p>
            </div>
        </Card>
    </AppLayout>
</template>

