<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    ArrowLeftRight,
    TrendingUp,
    TrendingDown,
    Activity,
    Search,
    ArrowLeft,
} from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    adjustments: Object,
    summary: Object,
    products: Array,
    filters: Object,
});

const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');
const productId = ref(props.filters?.product_id || '');
const type = ref(props.filters?.type || '');

const applyFilters = () => {
    router.get(route('reports.stock-movement'), {
        date_from: dateFrom.value,
        date_to: dateTo.value,
        product_id: productId.value,
        type: type.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    dateFrom.value = '';
    dateTo.value = '';
    productId.value = '';
    type.value = '';
    applyFilters();
};

const typeVariant = (adjustmentType) =>
    ({
        manual: 'info',
        recount: 'brand',
        damage: 'danger',
        loss: 'warning',
        return: 'success',
        correction: 'warning',
    }[adjustmentType] || 'neutral');

const fieldClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const thClass =
    'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('reports.stockMovement.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('reports.index')" class="text-text-tertiary transition-colors hover:text-text-primary">{{ t('reports.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('reports.stockMovement.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('reports.stockMovement.title')" :description="t('reports.stockMovement.description')">
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
                :label="t('reports.stockMovement.totalAdjustments')"
                :value="summary.total_adjustments"
                icon-tone="brand"
            >
                <template #icon><ArrowLeftRight :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.stockMovement.stockIncreases')"
                :value="`+${summary.total_increases}`"
                icon-tone="success"
            >
                <template #icon><TrendingUp :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.stockMovement.stockDecreases')"
                :value="`-${summary.total_decreases}`"
                icon-tone="warning"
            >
                <template #icon><TrendingDown :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('reports.stockMovement.netChange')"
                :value="`${summary.net_change >= 0 ? '+' : ''}${summary.net_change}`"
                :delta-tone="summary.net_change >= 0 ? 'up' : 'down'"
                icon-tone="brand"
            >
                <template #icon><Activity :size="18" /></template>
            </StatTile>
        </section>

        <!-- Filters -->
        <Card class="mt-4">
            <form @submit.prevent="applyFilters" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-text-secondary">{{ t('reports.stockMovement.dateFrom') }}</label>
                        <input v-model="dateFrom" type="date" :class="fieldClass" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-text-secondary">{{ t('reports.stockMovement.dateTo') }}</label>
                        <input v-model="dateTo" type="date" :class="fieldClass" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-text-secondary">{{ t('common.product') }}</label>
                        <select v-model="productId" :class="fieldClass">
                            <option value="">{{ t('reports.stockMovement.allProducts') }}</option>
                            <option v-for="product in products" :key="product.id" :value="product.id">
                                {{ product.name }} ({{ product.sku }})
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-text-secondary">{{ t('common.type') }}</label>
                        <select v-model="type" :class="fieldClass">
                            <option value="">{{ t('common.allTypes') }}</option>
                            <option value="manual">{{ t('reports.adjustmentTypes.manual') }}</option>
                            <option value="recount">{{ t('reports.adjustmentTypes.recount') }}</option>
                            <option value="damage">{{ t('reports.adjustmentTypes.damage') }}</option>
                            <option value="loss">{{ t('reports.adjustmentTypes.loss') }}</option>
                            <option value="return">{{ t('reports.adjustmentTypes.return') }}</option>
                            <option value="correction">{{ t('reports.adjustmentTypes.correction') }}</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="submit" variant="default" size="sm"><Search :size="14" />{{ t('common.applyFilters') }}</Button>
                    <Button type="button" variant="secondary" size="sm" @click="clearFilters">{{ t('common.clear') }}</Button>
                </div>
            </form>
        </Card>

        <!-- Adjustments table -->
        <div class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">{{ t('common.date') }}</th>
                        <th :class="thClass">{{ t('common.product') }}</th>
                        <th :class="thClass">{{ t('common.type') }}</th>
                        <th :class="[thClass, 'text-right']">{{ t('reports.stockMovement.before') }}</th>
                        <th :class="[thClass, 'text-right']">{{ t('reports.stockMovement.change') }}</th>
                        <th :class="[thClass, 'text-right']">{{ t('reports.stockMovement.after') }}</th>
                        <th :class="thClass">{{ t('common.user') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="adj in adjustments.data"
                        :key="adj.id"
                        class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                    >
                        <td class="whitespace-nowrap px-4 py-3 text-text-secondary">
                            {{ new Date(adj.created_at).toLocaleDateString() }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-text-primary">{{ adj.product.name }}</div>
                            <div class="text-xs text-text-tertiary">{{ adj.product.sku }}</div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <Badge :variant="typeVariant(adj.type)" size="sm">{{ adj.type }}</Badge>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-text-secondary">
                            {{ adj.quantity_before }}
                        </td>
                        <td
                            class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums"
                            :class="adj.adjustment_quantity >= 0 ? 'text-status-success' : 'text-status-danger'"
                        >
                            {{ adj.adjustment_quantity >= 0 ? '+' : '' }}{{ adj.adjustment_quantity }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums text-text-primary">
                            {{ adj.quantity_after }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-text-secondary">
                            {{ adj.user?.name || t('common.system') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="adjustments.links && adjustments.links.length > 3" class="mt-4 flex justify-center">
            <nav class="inline-flex items-center gap-1">
                <template v-for="(link, index) in adjustments.links" :key="index">
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
                    <span
                        v-else
                        class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-2.5 text-xs text-text-tertiary opacity-50"
                        v-html="link.label"
                    />
                </template>
            </nav>
        </div>
    </AppLayout>
</template>
