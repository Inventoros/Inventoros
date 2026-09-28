<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PortalStatusBadge from '@/Components/Portal/PortalStatusBadge.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import { formatDate, formatMoney } from '@/lib/portal';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft } from 'lucide-vue-next';

const props = defineProps({
    returnOrder: { type: Object, required: true },
});

const { t, te } = useI18n();

const nextStep = computed(() => {
    const key = `portal.returnShow.steps.${props.returnOrder.status}`;
    return te(key) ? t(key) : null;
});

const typeLabel = computed(() => {
    const key = `portal.returns.types.${props.returnOrder.type}`;
    return te(key) ? t(key) : props.returnOrder.type;
});

const conditionLabel = (condition) => {
    const key = `portal.returnForm.conditions.${condition}`;
    return te(key) ? t(key) : condition;
};

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('portal.returnShow.title', { number: returnOrder.return_number })" />

    <PortalLayout>
        <div class="mb-4">
            <Link :href="route('portal.returns.index')" class="inline-flex items-center gap-1 text-sm text-text-secondary hover:text-text-primary">
                <ArrowLeft :size="14" />
                {{ t('portal.returns.back') }}
            </Link>
        </div>

        <PageHeader
            :title="t('portal.returnShow.title', { number: returnOrder.return_number })"
            :description="returnOrder.order ? t('portal.returnShow.forOrder', { number: returnOrder.order.order_number }) : null"
            :eyebrow="typeLabel"
        >
            <template #actions>
                <PortalStatusBadge :status="returnOrder.status" />
            </template>
        </PageHeader>

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <Card :padded="false">
                    <div class="px-5 pt-5"><h2 class="text-sm font-semibold text-text-primary">{{ t('portal.returnShow.items') }}</h2></div>
                    <div class="p-5">
                        <div class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-border-subtle">
                                        <th :class="thClass">{{ t('portal.order.product') }}</th>
                                        <th :class="thClass">{{ t('portal.returnShow.condition') }}</th>
                                        <th :class="[thClass, 'text-right']">{{ t('portal.order.quantity') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in returnOrder.items" :key="item.id" class="border-b border-border-subtle last:border-b-0">
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-text-primary">{{ item.product_name }}</div>
                                            <div v-if="item.variant_title" class="text-xs text-text-secondary">{{ item.variant_title }}</div>
                                            <div class="text-xs text-text-tertiary">{{ t('portal.order.sku') }} {{ item.sku }}</div>
                                        </td>
                                        <td class="px-4 py-3 text-text-secondary">{{ conditionLabel(item.condition) }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums text-text-primary">{{ item.quantity }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </Card>

                <Card :padded="false">
                    <div class="px-5 pt-5"><h2 class="text-sm font-semibold text-text-primary">{{ t('portal.returnShow.reason') }}</h2></div>
                    <div class="space-y-4 p-5 text-sm">
                        <p class="whitespace-pre-wrap text-text-primary">{{ returnOrder.reason }}</p>
                        <div v-if="returnOrder.notes" class="border-t border-border-subtle pt-4">
                            <p class="text-xs text-text-tertiary">{{ t('portal.returnShow.notes') }}</p>
                            <p class="mt-1 whitespace-pre-wrap text-text-secondary">{{ returnOrder.notes }}</p>
                        </div>
                    </div>
                </Card>
            </div>

            <div class="space-y-4">
                <Card :padded="false">
                    <div class="px-5 pt-5"><h2 class="text-sm font-semibold text-text-primary">{{ t('portal.returnShow.nextSteps') }}</h2></div>
                    <div class="space-y-3 p-5 text-sm">
                        <p v-if="nextStep" class="text-text-secondary">{{ nextStep }}</p>
                        <dl class="space-y-2 border-t border-border-subtle pt-3">
                            <div class="flex justify-between">
                                <dt class="text-text-tertiary">{{ t('portal.returns.requested') }}</dt>
                                <dd class="text-text-primary">{{ formatDate(returnOrder.created_at) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-text-tertiary">{{ t('portal.returns.refund') }}</dt>
                                <dd class="tabular-nums text-text-primary">{{ formatMoney(returnOrder.refund_amount, returnOrder.order?.currency) }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>
            </div>
        </div>
    </PortalLayout>
</template>
