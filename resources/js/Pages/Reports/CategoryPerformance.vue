<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    Tag,
    Boxes,
    DollarSign,
    ArrowLeft,
    Download,
} from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    categories: Array,
    summary: Object,
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};

// Largest category value, used to scale the bar visuals.
const maxValue = computed(() =>
    props.categories.reduce((max, c) => Math.max(max, Number(c.total_value) || 0), 0)
);

const barWidth = (value) => {
    if (!maxValue.value) return '0%';
    return `${Math.max(4, (Number(value) / maxValue.value) * 100)}%`;
};

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('reports.categoryPerformance.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('reports.index')" class="text-text-tertiary transition-colors hover:text-text-primary">{{ t('reports.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('reports.categoryPerformance.title') }}</span>
            </div>
        </template>

        <PageHeader
            :title="t('reports.categoryPerformance.title')"
            :description="t('reports.categoryPerformance.description')"
        >
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('reports.index')">
                    <ArrowLeft :size="14" />
                    {{ t('reports.backToReports') }}
                </Button>
                <Button variant="default" size="sm" as="a" :href="route('reports.category-performance', { export: 'csv' })">
                    <Download :size="14" />
                    {{ t('common.export') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Summary stats -->
        <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <StatTile
                :label="t('reports.categoryPerformance.totalCategories')"
                :value="summary.total_categories"
                icon-tone="brand"
            >
                <template #icon><Tag :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.categoryPerformance.totalProducts')"
                :value="summary.total_products"
                icon-tone="violet"
            >
                <template #icon><Boxes :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.categoryPerformance.totalValue')"
                :value="formatCurrency(summary.total_value)"
                icon-tone="success"
            >
                <template #icon><DollarSign :size="18" /></template>
            </StatTile>
        </section>

        <!-- Category cards with value bars -->
        <section v-if="categories.length > 0" class="mt-6 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="category in categories"
                :key="category.category_id"
                class="rounded-lg border border-border-subtle bg-surface-raised p-4 transition-colors hover:border-border-strong"
            >
                <div class="flex items-start justify-between gap-2">
                    <h3 class="min-w-0 truncate text-sm font-semibold text-text-primary">{{ category.category_name }}</h3>
                    <Badge v-if="category.low_stock_items > 0" variant="danger" size="sm" dot>
                        {{ category.low_stock_items }} {{ t('reports.categoryPerformance.low') }}
                    </Badge>
                </div>

                <p class="mt-3 text-2xl font-semibold tabular-nums tracking-tight text-text-primary">
                    {{ formatCurrency(category.total_value) }}
                </p>

                <!-- Value bar visual -->
                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-surface-sunken">
                    <div
                        class="h-full rounded-full bg-gradient-to-t from-sky-200 to-sky-500"
                        :style="{ width: barWidth(category.total_value) }"
                    />
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-3">
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wider text-text-tertiary">{{ t('common.products') }}</dt>
                        <dd class="mt-0.5 text-sm font-semibold tabular-nums text-text-primary">{{ category.product_count }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wider text-text-tertiary">{{ t('reports.categoryPerformance.totalStock') }}</dt>
                        <dd class="mt-0.5 text-sm font-semibold tabular-nums text-text-primary">{{ category.total_stock }}</dd>
                    </div>
                </dl>

                <div class="mt-4 flex items-center justify-between border-t border-border-subtle pt-3">
                    <span class="text-xs text-text-tertiary">{{ t('reports.categoryPerformance.avgPerProduct') }}</span>
                    <span class="text-xs font-medium tabular-nums text-text-secondary">
                        {{ formatCurrency(category.total_value / category.product_count) }}
                    </span>
                </div>
            </div>
        </section>

        <!-- Performance table -->
        <section v-if="categories.length > 0" class="mt-6">
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('reports.categoryPerformance.title')" />
                </div>
                <div class="mt-2 w-full overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border-subtle">
                                <th :class="thClass">{{ t('reports.categoryPerformance.title') }}</th>
                                <th :class="[thClass, 'text-right']">{{ t('common.products') }}</th>
                                <th :class="[thClass, 'text-right']">{{ t('reports.categoryPerformance.totalStock') }}</th>
                                <th :class="[thClass, 'text-right']">{{ t('reports.categoryPerformance.totalValue') }}</th>
                                <th :class="[thClass, 'text-right']">{{ t('reports.categoryPerformance.avgPerProduct') }}</th>
                                <th :class="[thClass, 'text-right']">{{ t('reports.categoryPerformance.low') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="category in categories"
                                :key="category.category_id"
                                class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                            >
                                <td class="px-4 py-3 font-medium text-text-primary">{{ category.category_name }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ category.product_count }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ category.total_stock }}</td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums text-text-primary">{{ formatCurrency(category.total_value) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatCurrency(category.total_value / category.product_count) }}</td>
                                <td class="px-4 py-3 text-right">
                                    <Badge v-if="category.low_stock_items > 0" variant="danger" size="sm">{{ category.low_stock_items }}</Badge>
                                    <span v-else class="text-xs text-text-tertiary">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </section>

        <!-- Empty State -->
        <Card v-if="categories.length === 0" class="mt-6">
            <div class="flex flex-col items-center gap-3 py-12 text-center">
                <Tag :size="22" class="text-text-tertiary" />
                <p class="text-sm font-medium text-text-primary">{{ t('reports.categoryPerformance.noCategoriesFound') }}</p>
                <p class="text-sm text-text-tertiary">{{ t('reports.categoryPerformance.addProductsHint') }}</p>
            </div>
        </Card>
    </AppLayout>
</template>
