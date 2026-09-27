<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PortalStatusBadge from '@/Components/Portal/PortalStatusBadge.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { formatDate, formatMoney } from '@/lib/portal';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Components/ui/Badge.vue';
import { ArrowLeft, CheckCircle2, Circle, ExternalLink, FileDown, RotateCcw, Truck } from 'lucide-vue-next';

const props = defineProps({
    order: { type: Object, required: true },
    returns: { type: Array, default: () => [] },
});

const { t, te } = useI18n();

const money = (value) => formatMoney(value, props.order.currency);

const shipments = computed(() => props.order.shipments || []);

const SHIPMENT_VARIANTS = {
    pending: 'neutral',
    label_created: 'neutral',
    shipped: 'brand',
    in_transit: 'info',
    delivered: 'success',
    exception: 'danger',
};

const shipmentStatusLabel = (shipment) => {
    const key = `portal.order.shipmentStatuses.${shipment.status}`;
    return te(key) ? t(key) : shipment.status_label;
};

const hasDiscount = computed(() => Number(props.order.discount_amount || 0) > 0);

const paymentStatusLabel = computed(() => {
    const key = `portal.order.paymentStatuses.${props.order.payment_status}`;
    return te(key) ? t(key) : props.order.payment_status;
});

const progress = computed(() => [
    { key: 'ordered', label: t('portal.order.ordered'), date: props.order.order_date, done: true },
    { key: 'shipped', label: t('portal.order.shipped'), date: props.order.shipped_at, done: ['shipped', 'delivered'].includes(props.order.status) || !!props.order.shipped_at },
    { key: 'delivered', label: t('portal.order.delivered'), date: props.order.delivered_at, done: props.order.status === 'delivered' || !!props.order.delivered_at },
]);

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('portal.order.title', { number: order.order_number })" />

    <PortalLayout>
        <div class="mb-4">
            <Link :href="route('portal.orders.index')" class="inline-flex items-center gap-1 text-sm text-text-secondary hover:text-text-primary">
                <ArrowLeft :size="14" />
                {{ t('portal.orders.back') }}
            </Link>
        </div>

        <PageHeader :title="t('portal.order.title', { number: order.order_number })" :description="formatDate(order.order_date)">
            <template #actions>
                <PortalStatusBadge :status="order.status" />
                <Button
                    v-if="order.invoice_available"
                    variant="secondary"
                    size="sm"
                    as="a"
                    :href="route('portal.orders.invoice', { order: order.id })"
                >
                    <FileDown :size="14" />
                    {{ t('portal.order.downloadInvoice') }}
                </Button>
                <Button
                    v-if="order.can_request_return"
                    size="sm"
                    as="Link"
                    :href="route('portal.returns.create', { order: order.id })"
                >
                    <RotateCcw :size="14" />
                    {{ t('portal.order.requestReturn') }}
                </Button>
            </template>
        </PageHeader>

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <Card :padded="false">
                    <div class="px-5 pt-5"><h2 class="text-sm font-semibold text-text-primary">{{ t('portal.order.lines') }}</h2></div>
                    <div class="p-5">
                        <div class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-border-subtle">
                                        <th :class="thClass">{{ t('portal.order.product') }}</th>
                                        <th :class="[thClass, 'text-right']">{{ t('portal.order.quantity') }}</th>
                                        <th :class="[thClass, 'text-right']">{{ t('portal.order.unitPrice') }}</th>
                                        <th :class="[thClass, 'text-right']">{{ t('portal.order.lineTotal') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in order.items" :key="item.id" class="border-b border-border-subtle last:border-b-0">
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-text-primary">{{ item.product_name }}</div>
                                            <div class="text-xs text-text-tertiary">
                                                {{ t('portal.order.sku') }} {{ item.sku }}
                                                <span v-if="Number(item.discount_amount || 0) > 0"> · {{ t('portal.order.lineDiscount', { amount: money(item.discount_amount) }) }}</span>
                                                <span v-if="item.returned_quantity > 0"> · {{ t('portal.order.returned', { count: item.returned_quantity }) }}</span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ item.quantity }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums text-text-secondary">{{ money(item.unit_price) }}</td>
                                        <td class="px-4 py-3 text-right font-medium tabular-nums text-text-primary">{{ money(item.total) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <dl class="ml-auto mt-4 w-full max-w-xs space-y-1.5 text-sm">
                            <div class="flex justify-between text-text-secondary">
                                <dt>{{ t('portal.order.subtotal') }}</dt>
                                <dd class="tabular-nums">{{ money(order.subtotal) }}</dd>
                            </div>
                            <div v-if="hasDiscount" class="flex justify-between text-text-secondary">
                                <dt>
                                    {{ t('portal.order.discount') }}
                                    <span v-if="order.discount_type === 'percent'">({{ Number(order.discount_value) }}%)</span>
                                </dt>
                                <dd class="tabular-nums">-{{ money(order.discount_amount) }}</dd>
                            </div>
                            <div class="flex justify-between text-text-secondary">
                                <dt>{{ t('portal.order.tax') }}</dt>
                                <dd class="tabular-nums">{{ money(order.tax) }}</dd>
                            </div>
                            <div class="flex justify-between text-text-secondary">
                                <dt>{{ t('portal.order.shipping') }}</dt>
                                <dd class="tabular-nums">{{ money(order.shipping) }}</dd>
                            </div>
                            <div class="flex justify-between border-t border-border-subtle pt-2 font-semibold text-text-primary">
                                <dt>{{ t('portal.order.total') }}</dt>
                                <dd class="tabular-nums">{{ money(order.total) }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <Card v-if="shipments.length" :padded="false">
                    <div class="px-5 pt-5"><h2 class="text-sm font-semibold text-text-primary">{{ t('portal.order.shipments') }}</h2></div>
                    <ul class="divide-y divide-border-subtle p-5 pt-2">
                        <li v-for="(shipment, index) in shipments" :key="shipment.id" class="space-y-2 py-3 first:pt-2 last:pb-0">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="flex min-w-0 items-start gap-2.5">
                                    <Truck :size="16" class="mt-0.5 shrink-0 text-text-tertiary" />
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-text-primary">
                                            {{ t('portal.order.shipment', { number: index + 1 }) }}
                                            <span v-if="shipment.carrier_name" class="font-normal text-text-secondary">
                                                · {{ shipment.carrier_name }}<span v-if="shipment.service"> {{ shipment.service }}</span>
                                            </span>
                                        </p>
                                        <p v-if="shipment.tracking_number" class="break-all text-xs text-text-tertiary">
                                            {{ t('portal.order.trackingNumber') }} {{ shipment.tracking_number }}
                                        </p>
                                        <p class="text-xs text-text-tertiary">
                                            <span v-if="shipment.shipped_at">{{ t('portal.order.shippedOn', { date: formatDate(shipment.shipped_at) }) }}</span>
                                            <span v-if="shipment.shipped_at && shipment.delivered_at"> · </span>
                                            <span v-if="shipment.delivered_at">{{ t('portal.order.deliveredOn', { date: formatDate(shipment.delivered_at) }) }}</span>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <Badge :variant="SHIPMENT_VARIANTS[shipment.status] || 'neutral'" size="sm" dot>
                                        {{ shipmentStatusLabel(shipment) }}
                                    </Badge>
                                    <a
                                        v-if="shipment.tracking_url"
                                        :href="shipment.tracking_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 text-xs text-brand hover:underline"
                                    >
                                        {{ t('portal.order.track') }}
                                        <ExternalLink :size="12" />
                                    </a>
                                </div>
                            </div>
                            <ul v-if="shipment.items.length" class="ml-6 space-y-0.5 text-xs text-text-secondary">
                                <li v-for="(line, lineIndex) in shipment.items" :key="lineIndex">
                                    {{ line.quantity }} x {{ line.product_name }} <span class="text-text-tertiary">({{ line.sku }})</span>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </Card>

                <Card v-if="returns.length" :padded="false">
                    <div class="px-5 pt-5"><h2 class="text-sm font-semibold text-text-primary">{{ t('portal.order.returns') }}</h2></div>
                    <ul class="divide-y divide-border-subtle p-5 pt-3">
                        <li v-for="ret in returns" :key="ret.id" class="flex items-center justify-between gap-3 py-2.5">
                            <Link :href="route('portal.returns.show', { returnOrder: ret.id })" class="font-medium text-brand hover:underline">
                                {{ ret.return_number }}
                            </Link>
                            <span class="text-xs text-text-tertiary">{{ formatDate(ret.created_at) }}</span>
                            <PortalStatusBadge :status="ret.status" />
                        </li>
                    </ul>
                </Card>
            </div>

            <div class="space-y-4">
                <Card :padded="false">
                    <div class="px-5 pt-5"><h2 class="text-sm font-semibold text-text-primary">{{ t('portal.order.progress') }}</h2></div>
                    <ol class="space-y-3 p-5">
                        <li v-for="step in progress" :key="step.key" class="flex items-start gap-2.5">
                            <CheckCircle2 v-if="step.done" :size="18" class="mt-0.5 shrink-0 text-status-success" />
                            <Circle v-else :size="18" class="mt-0.5 shrink-0 text-text-tertiary" />
                            <div>
                                <p :class="['text-sm font-medium', step.done ? 'text-text-primary' : 'text-text-tertiary']">{{ step.label }}</p>
                                <p v-if="step.date" class="text-xs text-text-tertiary">{{ formatDate(step.date) }}</p>
                            </div>
                        </li>
                    </ol>
                </Card>

                <Card :padded="false">
                    <div class="flex items-center justify-between gap-2 px-5 pt-5">
                        <h2 class="text-sm font-semibold text-text-primary">{{ t('portal.order.payments') }}</h2>
                        <span v-if="order.payment_status" class="text-xs text-text-tertiary">{{ paymentStatusLabel }}</span>
                    </div>
                    <div class="space-y-3 p-5 text-sm">
                        <ul v-if="order.payments && order.payments.length" class="space-y-1.5">
                            <li v-for="payment in order.payments" :key="payment.id" class="flex justify-between gap-2 text-text-secondary">
                                <span>
                                    {{ formatDate(payment.paid_at) }}
                                    <span class="text-text-tertiary">· {{ payment.type === 'refund' ? t('portal.order.refund') : payment.method_label }}</span>
                                </span>
                                <span class="tabular-nums">{{ payment.type === 'refund' ? '-' : '' }}{{ money(payment.amount) }}</span>
                            </li>
                        </ul>
                        <p v-else class="text-text-tertiary">{{ t('portal.order.noPayments') }}</p>
                        <dl class="space-y-1.5 border-t border-border-subtle pt-3">
                            <div class="flex justify-between text-text-secondary">
                                <dt>{{ t('portal.order.paid') }}</dt>
                                <dd class="tabular-nums">{{ money(order.amount_paid) }}</dd>
                            </div>
                            <div class="flex justify-between font-semibold text-text-primary">
                                <dt>{{ t('portal.order.balanceDue') }}</dt>
                                <dd class="tabular-nums">{{ money(order.balance_due) }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <Card :padded="false">
                    <div class="px-5 pt-5"><h2 class="text-sm font-semibold text-text-primary">{{ t('portal.order.invoice') }}</h2></div>
                    <div class="p-5 text-sm">
                        <template v-if="order.invoice_available">
                            <p v-if="order.invoice_number" class="font-medium text-text-primary">{{ order.invoice_number }}</p>
                            <a
                                :href="route('portal.orders.invoice', { order: order.id })"
                                class="mt-1 inline-flex items-center gap-1 text-brand hover:underline"
                            >
                                <FileDown :size="14" />
                                {{ t('portal.order.downloadInvoice') }}
                            </a>
                        </template>
                        <p v-else class="text-text-tertiary">{{ t('portal.order.invoiceNotReady') }}</p>
                    </div>
                </Card>

                <Card v-if="order.customer_address" :padded="false">
                    <div class="px-5 pt-5"><h2 class="text-sm font-semibold text-text-primary">{{ t('portal.order.shipTo') }}</h2></div>
                    <address class="whitespace-pre-line p-5 text-sm not-italic text-text-secondary">{{ order.customer_address }}</address>
                </Card>
            </div>
        </div>
    </PortalLayout>
</template>
