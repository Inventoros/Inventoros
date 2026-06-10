<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    DollarSign,
    ArrowLeftRight,
    TrendingUp,
    AlertTriangle,
    Tag,
    Wrench,
    ChevronRight,
    Plus,
} from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    savedReports: {
        type: Array,
        default: () => [],
    },
});

const reportCards = [
    {
        href: route('reports.inventory-valuation'),
        icon: DollarSign,
        title: t('reports.inventoryValuation.title'),
        description: t('reports.inventoryValuation.description'),
    },
    {
        href: route('reports.stock-movement'),
        icon: ArrowLeftRight,
        title: t('reports.stockMovement.title'),
        description: t('reports.stockMovement.description'),
    },
    {
        href: route('reports.sales-analysis'),
        icon: TrendingUp,
        title: t('reports.salesAnalysis.title'),
        description: t('reports.salesAnalysis.description'),
    },
    {
        href: route('reports.low-stock'),
        icon: AlertTriangle,
        title: t('reports.lowStock.title'),
        description: t('reports.lowStock.description'),
    },
    {
        href: route('reports.category-performance'),
        icon: Tag,
        title: t('reports.categoryPerformance.title'),
        description: t('reports.categoryPerformance.description'),
    },
    {
        href: route('reports.builder.index'),
        icon: Wrench,
        title: t('reports.customReports.title'),
        description: t('reports.customReports.description'),
    },
];
</script>

<template>
    <Head :title="t('reports.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('reports.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('reports.title')" :description="t('reports.subtitle')" />

        <!-- Report cards -->
        <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Link v-for="report in reportCards" :key="report.href" :href="report.href">
                <Card hoverable>
                    <div class="flex items-start gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand">
                            <component :is="report.icon" :size="18" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-sm font-semibold text-text-primary">{{ report.title }}</h3>
                            <p class="mt-1 text-sm text-text-secondary">{{ report.description }}</p>
                        </div>
                        <ChevronRight :size="16" class="mt-0.5 shrink-0 text-text-tertiary" />
                    </div>
                </Card>
            </Link>
        </div>

        <!-- Saved custom reports -->
        <section v-if="savedReports.length > 0" class="mt-10">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-text-primary">{{ t('reports.customReports.saved') }}</h2>
                <Button variant="default" size="sm" as="Link" :href="route('reports.builder.create')">
                    <Plus :size="14" />
                    {{ t('reports.customReports.create') }}
                </Button>
            </div>
            <Card :padded="false">
                <div class="overflow-hidden rounded-lg">
                    <table class="min-w-full divide-y divide-border-subtle">
                        <thead class="bg-surface-overlay">
                            <tr>
                                <th class="px-6 py-3 text-left text-[11px] font-medium uppercase tracking-wider text-text-tertiary">{{ t('reportBuilder.columns.name') }}</th>
                                <th class="px-6 py-3 text-left text-[11px] font-medium uppercase tracking-wider text-text-tertiary">{{ t('reportBuilder.columns.dataSource') }}</th>
                                <th class="px-6 py-3 text-left text-[11px] font-medium uppercase tracking-wider text-text-tertiary">{{ t('reportBuilder.columns.createdBy') }}</th>
                                <th class="px-6 py-3 text-left text-[11px] font-medium uppercase tracking-wider text-text-tertiary">{{ t('reportBuilder.columns.created') }}</th>
                                <th class="px-6 py-3 text-right text-[11px] font-medium uppercase tracking-wider text-text-tertiary"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-subtle">
                            <tr v-for="report in savedReports" :key="report.id" class="transition-colors hover:bg-surface-overlay">
                                <td class="px-6 py-4">
                                    <Link :href="route('reports.builder.show', report.id)" class="text-sm font-medium text-text-primary transition-colors hover:text-brand">
                                        {{ report.name }}
                                    </Link>
                                </td>
                                <td class="px-6 py-4">
                                    <Badge variant="info" size="sm">{{ report.data_source }}</Badge>
                                </td>
                                <td class="px-6 py-4 text-sm text-text-secondary">{{ report.creator?.name || '-' }}</td>
                                <td class="px-6 py-4 text-sm text-text-secondary">{{ new Date(report.created_at).toLocaleDateString() }}</td>
                                <td class="px-6 py-4 text-right">
                                    <Link :href="route('reports.builder.show', report.id)" class="text-sm text-brand transition-colors hover:text-brand-hover">
                                        {{ t('reportBuilder.actions.view') }}
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </section>
    </AppLayout>
</template>

