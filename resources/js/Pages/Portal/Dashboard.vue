<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PortalStatusBadge from '@/Components/Portal/PortalStatusBadge.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import { formatDate, formatDay, formatMoney } from '@/lib/portal';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowRight, Package, PackageOpen, RotateCcw, Truck } from 'lucide-vue-next';

defineProps({
    stats: { type: Object, required: true },
    recentOrders: { type: Array, default: () => [] },
});

const { t } = useI18n();
const page = usePage();
const contact = computed(() => page.props.portal?.contact ?? {});
const orgName = computed(() => page.props.portal?.organization?.name ?? '');

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('portal.dashboard.title')" />

    <PortalLayout>
        <PageHeader
            :title="t('portal.dashboard.welcome', { name: contact.name })"
            :description="t('portal.dashboard.description', { org: orgName })"
            :eyebrow="contact.customer"
        />

        <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <StatTile :label="t('portal.dashboard.orders')" :value="stats.orders" icon-tone="brand">
                <template #icon><Package :size="18" /></template>
            </StatTile>
            <StatTile :label="t('portal.dashboard.openOrders')" :value="stats.open_orders" icon-tone="info">
                <template #icon><Truck :size="18" /></template>
            </StatTile>
            <StatTile :label="t('portal.dashboard.openReturns')" :value="stats.open_returns" icon-tone="warning">
                <template #icon><RotateCcw :size="18" /></template>
            </StatTile>
        </section>

        <Card :padded="false" class="mt-4">
            <div class="flex items-center justify-between px-5 pt-5">
                <h2 class="text-sm font-semibold text-text-primary">{{ t('portal.dashboard.recentOrders') }}</h2>
                <Link :href="route('portal.orders.index')" class="inline-flex items-center gap-1 text-sm text-brand hover:underline">
                    {{ t('portal.dashboard.viewAll') }}
                    <ArrowRight :size="14" />
                </Link>
            </div>
            <div class="p-5">
                <div v-if="recentOrders.length" class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-border-subtle">
                                <th :class="thClass">{{ t('portal.orders.number') }}</th>
                                <th :class="thClass">{{ t('portal.orders.date') }}</th>
                                <th :class="thClass">{{ t('portal.orders.status') }}</th>
                                <th :class="[thClass, 'text-right']">{{ t('portal.orders.total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="order in recentOrders"
                                :key="order.id"
                                class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                            >
                                <td class="px-4 py-3">
                                    <Link :href="route('portal.orders.show', { order: order.id })" class="font-medium text-brand hover:underline">
                                        {{ order.order_number }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3 text-text-tertiary">{{ formatDay(order.order_date) }}</td>
                                <td class="px-4 py-3"><PortalStatusBadge :status="order.status" /></td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums text-text-primary">
                                    {{ formatMoney(order.total, order.currency) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
                    <PackageOpen :size="22" class="text-text-tertiary" />
                    <p class="text-sm text-text-tertiary">{{ t('portal.dashboard.noOrders') }}</p>
                </div>
            </div>
        </Card>
    </PortalLayout>
</template>
