<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PortalPagination from '@/Components/Portal/PortalPagination.vue';
import PortalStatusBadge from '@/Components/Portal/PortalStatusBadge.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { formatDate, formatDay, formatMoney } from '@/lib/portal';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { PackageOpen } from 'lucide-vue-next';

defineProps({
    orders: { type: Object, required: true },
});

const { t } = useI18n();
const page = usePage();
const orgName = computed(() => page.props.portal?.organization?.name ?? '');

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('portal.orders.title')" />

    <PortalLayout>
        <PageHeader :title="t('portal.orders.title')" :description="t('portal.orders.description', { org: orgName })" />

        <div class="mt-6">
            <div v-if="orders.data.length" class="w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-subtle">
                            <th :class="thClass">{{ t('portal.orders.number') }}</th>
                            <th :class="thClass">{{ t('portal.orders.date') }}</th>
                            <th :class="thClass">{{ t('portal.orders.status') }}</th>
                            <th :class="[thClass, 'text-right']">{{ t('portal.orders.items') }}</th>
                            <th :class="[thClass, 'text-right']">{{ t('portal.orders.total') }}</th>
                            <th :class="[thClass, 'text-right']"><span class="sr-only">{{ t('portal.orders.view') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="order in orders.data"
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
                            <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ order.items_count }}</td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-text-primary">
                                {{ formatMoney(order.total, order.currency) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link :href="route('portal.orders.show', { order: order.id })" class="text-sm text-brand hover:underline">
                                    {{ t('portal.orders.view') }}
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="flex flex-col items-center gap-2 rounded-lg border border-border-subtle bg-surface-raised py-12 text-center">
                <PackageOpen :size="22" class="text-text-tertiary" />
                <p class="text-sm text-text-tertiary">{{ t('portal.orders.empty') }}</p>
            </div>

            <PortalPagination :paginator="orders" />
        </div>
    </PortalLayout>
</template>
