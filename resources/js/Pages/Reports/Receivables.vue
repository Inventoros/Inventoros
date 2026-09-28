<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { formatCalendarDate } from '@/lib/dates';
import { ArrowLeft, Download, Wallet, ShoppingCart, Users, CircleCheck } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    summary: Object,
    buckets: Array,
    customers: Array,
    orders: Array,
    currency: { type: String, default: 'USD' },
});

// Bucket keys come from the server; labels live here.
const bucketKeys = ['current', '1_30', '31_60', '61_90', 'over_90'];
const bucketLabel = (key) =>
    ({
        current: t('receivables.buckets.current'),
        '1_30': t('receivables.buckets.days1to30'),
        '31_60': t('receivables.buckets.days31to60'),
        '61_90': t('receivables.buckets.days61to90'),
        over_90: t('receivables.buckets.over90'),
    }[key] ?? key);

// Older buckets read warmer, so the eye lands on what is most overdue.
const bucketTone = (key) =>
    ({
        current: 'text-text-primary',
        '1_30': 'text-text-primary',
        '31_60': 'text-status-warning',
        '61_90': 'text-status-warning',
        over_90: 'text-status-danger',
    }[key] ?? 'text-text-primary');

const bucketVariant = (key) =>
    ({ current: 'neutral', '1_30': 'info', '31_60': 'warning', '61_90': 'warning', over_90: 'danger' }[key] ?? 'neutral');

const formatCurrency = (value) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: props.currency || 'USD' }).format(parseFloat(value) || 0);

const share = (amount) => {
    const total = parseFloat(props.summary.total_outstanding) || 0;
    return total > 0 ? Math.round(((parseFloat(amount) || 0) / total) * 100) : 0;
};

const exportReport = () => window.print();

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('receivables.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('reports.index')" class="text-text-tertiary transition-colors hover:text-text-primary">{{ t('reports.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('receivables.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('receivables.title')" :description="t('receivables.description')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('reports.index')">
                    <ArrowLeft :size="14" />
                    {{ t('reports.backToReports') }}
                </Button>
                <Button variant="default" size="sm" @click="exportReport">
                    <Download :size="14" />
                    {{ t('common.export') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Summary -->
        <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <StatTile :label="t('receivables.totalOutstanding')" :value="formatCurrency(summary.total_outstanding)" icon-tone="warning">
                <template #icon><Wallet :size="18" /></template>
            </StatTile>
            <StatTile :label="t('receivables.openOrders')" :value="summary.order_count" icon-tone="brand">
                <template #icon><ShoppingCart :size="18" /></template>
            </StatTile>
            <StatTile :label="t('receivables.customers')" :value="summary.customer_count" icon-tone="brand">
                <template #icon><Users :size="18" /></template>
            </StatTile>
        </section>

        <!-- Aging buckets -->
        <Card class="mt-4" :padded="false">
            <div class="grid grid-cols-2 divide-border-subtle sm:grid-cols-5 sm:divide-x">
                <div v-for="bucket in buckets" :key="bucket.key" class="p-4">
                    <p class="text-[11px] font-medium uppercase tracking-wider text-text-tertiary">{{ bucketLabel(bucket.key) }}</p>
                    <p :class="['mt-1 text-lg font-semibold tabular-nums', parseFloat(bucket.amount) > 0 ? bucketTone(bucket.key) : 'text-text-tertiary']">{{ formatCurrency(bucket.amount) }}</p>
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-surface-sunken" aria-hidden="true">
                        <div class="h-full rounded-full bg-brand" :style="{ width: `${share(bucket.amount)}%` }"></div>
                    </div>
                    <p class="mt-1 text-xs text-text-tertiary">{{ t('payments.ordersCount', bucket.count) }}</p>
                </div>
            </div>
            <p class="border-t border-border-subtle px-4 py-3 text-xs text-text-tertiary">{{ t('receivables.agingNote') }}</p>
        </Card>

        <div v-if="summary.order_count === 0" class="mt-4">
            <Card>
                <div class="flex flex-col items-center gap-2 py-8 text-center">
                    <CircleCheck :size="22" class="text-status-success" />
                    <p class="text-sm text-text-tertiary">{{ t('receivables.empty') }}</p>
                </div>
            </Card>
        </div>

        <template v-else>
            <!-- By customer -->
            <Card class="mt-4" :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('receivables.byCustomer')" />
                </div>
                <div class="mt-4 w-full overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border-subtle">
                                <th :class="thClass">{{ t('receivables.customer') }}</th>
                                <th :class="[thClass, 'text-right']">{{ t('receivables.orders') }}</th>
                                <th v-for="key in bucketKeys" :key="key" :class="[thClass, 'text-right whitespace-nowrap']">{{ bucketLabel(key) }}</th>
                                <th :class="[thClass, 'text-right']">{{ t('receivables.total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in customers" :key="`${row.customer_id ?? ''}-${row.customer}`" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                                <td class="px-4 py-3 text-text-primary">
                                    <Link v-if="row.customer_id" :href="route('customers.show', row.customer_id)" class="hover:text-brand">{{ row.customer }}</Link>
                                    <span v-else>{{ row.customer }}</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ row.orders }}</td>
                                <td v-for="key in bucketKeys" :key="key" :class="['px-4 py-3 text-right tabular-nums', parseFloat(row[key]) > 0 ? bucketTone(key) : 'text-text-tertiary']">
                                    {{ parseFloat(row[key]) > 0 ? formatCurrency(row[key]) : '-' }}
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-text-primary">{{ formatCurrency(row.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <!-- By order -->
            <Card class="mt-4" :padded="false">
                <div class="px-5 pt-5">
                    <CardHeader :title="t('receivables.byOrder')" />
                </div>
                <div class="mt-4 w-full overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border-subtle">
                                <th :class="thClass">{{ t('orders.orderCol') }}</th>
                                <th :class="thClass">{{ t('receivables.customer') }}</th>
                                <th :class="thClass">{{ t('receivables.orderDate') }}</th>
                                <th :class="thClass">{{ t('receivables.age') }}</th>
                                <th :class="[thClass, 'text-right']">{{ t('receivables.orderTotal') }}</th>
                                <th :class="[thClass, 'text-right']">{{ t('receivables.paid') }}</th>
                                <th :class="[thClass, 'text-right']">{{ t('receivables.balance') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="order in orders" :key="order.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                                <td class="px-4 py-3">
                                    <Link :href="route('orders.show', order.id)" class="font-mono text-xs font-medium text-text-primary hover:text-brand">{{ order.order_number }}</Link>
                                </td>
                                <td class="px-4 py-3 text-text-primary">{{ order.customer || '-' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-text-secondary">{{ formatCalendarDate(order.order_date) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <Badge :variant="bucketVariant(order.bucket)" size="sm">{{ t('receivables.days', order.age_days) }}</Badge>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatCurrency(order.total) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ formatCurrency(order.amount_paid) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-text-primary">{{ formatCurrency(order.balance_due) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="summary.orders_truncated" class="border-t border-border-subtle px-4 py-3 text-xs text-text-tertiary">
                    {{ t('receivables.truncated', { count: orders.length }) }}
                </p>
            </Card>
        </template>
    </AppLayout>
</template>
