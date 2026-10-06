<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import ExportMenu from '@/Components/Reports/ExportMenu.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useQuickReorder } from '@/composables/useQuickReorder';
import { formatMoney } from '@/lib/money';
import {
    AlertTriangle,
    PackageX,
    DollarSign,
    ArrowLeft,
    CheckCircle2,
    ClipboardList,
} from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';

const { canVisit } = usePermissions();

const { t } = useI18n();

const props = defineProps({
    products: Array,
    summary: Object,
    truncated: Boolean,
});

const formatCurrency = (value) => formatMoney(value);

const quickReorder = useQuickReorder(computed(() => props.products || []));

const statusVariant = (status) => (status === 'out_of_stock' ? 'danger' : 'warning');

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
const thClassRight = 'px-4 py-2.5 text-right text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('reports.lowStock.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('reports.index')" class="text-text-tertiary transition-colors hover:text-text-primary">{{ t('reports.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('reports.lowStock.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('reports.lowStock.title')" :description="t('reports.lowStock.description')">
            <template #actions>
                <ExportMenu route-name="reports.low-stock" />
                <Button variant="secondary" size="sm" as="Link" :href="route('reports.index')">
                    <ArrowLeft :size="14" />
                    {{ t('reports.backToReports') }}
                </Button>
            </template>
        </PageHeader>

        <p v-if="truncated" class="mt-4 text-xs text-status-warning">{{ t('reports.common.truncated', { count: products.length }) }}</p>

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
                <CardHeader :title="t('reports.lowStock.productsRequiringAttention')">
                    <template v-if="quickReorder.canCreatePo.value && products.length > 0" #actions>
                        <Button
                            size="sm"
                            :disabled="quickReorder.selected.value.length === 0 || quickReorder.submitting.value"
                            :loading="quickReorder.submitting.value"
                            @click="quickReorder.createPurchaseOrders()"
                        >
                            <ClipboardList :size="14" />
                            {{ t('quickReorder.createPos', { count: quickReorder.selected.value.length }) }}
                        </Button>
                    </template>
                </CardHeader>
                <p v-if="quickReorder.canCreatePo.value && products.length > 0" class="mt-1 text-xs text-text-tertiary">{{ t('quickReorder.hint') }}</p>
            </div>

            <div v-if="products.length > 0" class="w-full overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-subtle">
                            <th v-if="quickReorder.canCreatePo.value" class="w-8 px-4 py-2.5">
                                <input
                                    type="checkbox"
                                    class="rounded border-border-subtle text-brand ds-focus-ring"
                                    :checked="quickReorder.allSelected.value"
                                    :disabled="quickReorder.orderable.value.length === 0"
                                    :aria-label="t('quickReorder.selectAll')"
                                    @change="quickReorder.toggleAll()"
                                />
                            </th>
                            <th :class="thClass">{{ t('common.status') }}</th>
                            <th :class="thClass">{{ t('common.product') }}</th>
                            <th :class="thClass">{{ t('products.category') }}</th>
                            <th :class="thClassRight">{{ t('reports.lowStock.current') }}</th>
                            <th :class="thClassRight">{{ t('reports.lowStock.min') }}</th>
                            <th :class="thClassRight">{{ t('reports.lowStock.max') }}</th>
                            <th :class="thClassRight">{{ t('reports.lowStock.deficit') }}</th>
                            <th :class="thClassRight">{{ t('reports.lowStock.reorderCost') }}</th>
                            <th :class="thClass">{{ t('productSuppliers.supplier') }}</th>
                            <th :class="thClassRight">{{ t('quickReorder.suggestedQty') }}</th>
                            <th v-if="quickReorder.canCreatePo.value" :class="thClassRight"><span class="sr-only">{{ t('common.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="product in products"
                            :key="product.id"
                            class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                        >
                            <td v-if="quickReorder.canCreatePo.value" class="px-4 py-3">
                                <input
                                    type="checkbox"
                                    class="rounded border-border-subtle text-brand ds-focus-ring disabled:opacity-40"
                                    :checked="quickReorder.isSelected(product.id)"
                                    :disabled="!product.supplier_id"
                                    :aria-label="t('quickReorder.selectForReorder', { name: product.name })"
                                    @change="quickReorder.toggle(product.id)"
                                />
                            </td>
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
                            <td class="px-4 py-3">
                                <span v-if="product.supplier" class="text-text-secondary">{{ product.supplier }}</span>
                                <span v-else class="flex flex-col">
                                    <span class="text-xs italic text-status-danger">{{ t('quickReorder.noSupplier') }}</span>
                                    <Link v-if="canVisit('products.edit')" :href="route('products.edit', product.id)" class="text-xs font-medium text-brand hover:underline">{{ t('quickReorder.addSupplier') }}</Link>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-text-primary">{{ product.suggested_quantity }}</td>
                            <td v-if="quickReorder.canCreatePo.value" class="px-4 py-3 text-right">
                                <Button
                                    v-if="product.supplier_id"
                                    variant="ghost"
                                    size="xs"
                                    :disabled="quickReorder.submitting.value"
                                    @click="quickReorder.createPurchaseOrders([product.id])"
                                >
                                    <ClipboardList :size="12" />
                                    {{ t('quickReorder.createPo') }}
                                </Button>
                            </td>
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
