<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PortalPagination from '@/Components/Portal/PortalPagination.vue';
import PortalStatusBadge from '@/Components/Portal/PortalStatusBadge.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { formatDate, formatMoney } from '@/lib/portal';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { RotateCcw } from 'lucide-vue-next';

defineProps({
    returns: { type: Object, required: true },
});

const { t, te } = useI18n();

const typeLabel = (type) => (te(`portal.returns.types.${type}`) ? t(`portal.returns.types.${type}`) : type);

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('portal.returns.title')" />

    <PortalLayout>
        <PageHeader :title="t('portal.returns.title')" :description="t('portal.returns.description')" />

        <div class="mt-6">
            <div v-if="returns.data.length" class="w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-subtle">
                            <th :class="thClass">{{ t('portal.returns.number') }}</th>
                            <th :class="thClass">{{ t('portal.returns.order') }}</th>
                            <th :class="thClass">{{ t('portal.returns.type') }}</th>
                            <th :class="thClass">{{ t('portal.returns.requested') }}</th>
                            <th :class="thClass">{{ t('portal.returns.status') }}</th>
                            <th :class="[thClass, 'text-right']">{{ t('portal.returns.refund') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="ret in returns.data"
                            :key="ret.id"
                            class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                        >
                            <td class="px-4 py-3">
                                <Link :href="route('portal.returns.show', { returnOrder: ret.id })" class="font-medium text-brand hover:underline">
                                    {{ ret.return_number }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                <Link
                                    v-if="ret.order"
                                    :href="route('portal.orders.show', { order: ret.order.id })"
                                    class="text-text-secondary hover:text-text-primary hover:underline"
                                >
                                    {{ ret.order.order_number }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-text-secondary">{{ typeLabel(ret.type) }}</td>
                            <td class="px-4 py-3 text-text-tertiary">{{ formatDate(ret.created_at) }}</td>
                            <td class="px-4 py-3"><PortalStatusBadge :status="ret.status" /></td>
                            <td class="px-4 py-3 text-right tabular-nums text-text-primary">
                                {{ formatMoney(ret.refund_amount, ret.order?.currency) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="flex flex-col items-center gap-2 rounded-lg border border-border-subtle bg-surface-raised py-12 text-center">
                <RotateCcw :size="22" class="text-text-tertiary" />
                <p class="text-sm text-text-tertiary">{{ t('portal.returns.empty') }}</p>
            </div>

            <PortalPagination :paginator="returns" />
        </div>
    </PortalLayout>
</template>
