<script setup>
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import ShipOrderModal from '@/Components/Shipping/ShipOrderModal.vue';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Truck, Download, ExternalLink, PackageCheck } from 'lucide-vue-next';

/**
 * Shipments card on the order detail page, with the "Ship" modal.
 */
const props = defineProps({
    order: { type: Object, required: true },
    shipments: { type: Array, default: () => [] },
    shipping: { type: Object, required: true },
});

const { t } = useI18n();

const showModal = ref(false);
const resumeShipment = ref(null);
const acting = ref(null);

const remainingUnits = computed(() => props.shipping.lines.reduce((sum, l) => sum + l.remaining, 0));
const canShip = computed(() =>
    props.shipping.canCreate && remainingUnits.value > 0 && !['cancelled', 'delivered'].includes(props.order.status),
);

const statusVariant = (status) =>
    ({
        pending: 'neutral',
        label_created: 'info',
        shipped: 'brand',
        in_transit: 'brand',
        delivered: 'success',
        exception: 'danger',
        cancelled: 'neutral',
    }[status] || 'neutral');

const openShip = (shipment = null) => {
    resumeShipment.value = shipment;
    showModal.value = true;
};

const post = (name, shipment, confirmMessage = null) => {
    if (confirmMessage && !window.confirm(confirmMessage)) return;
    acting.value = shipment.id;
    router.post(route(name, shipment.id), {}, {
        preserveScroll: true,
        onFinish: () => (acting.value = null),
    });
};

const formatDate = (date) =>
    date ? new Date(date).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '';

const formatMoney = (amount, currency) => {
    if (amount === null || amount === undefined || amount === '') return '';
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'USD' }).format(Number(amount));
    } catch {
        return `${amount} ${currency || ''}`;
    }
};
</script>

<template>
    <Card :padded="false">
        <div class="flex items-center justify-between gap-3 px-5 pt-5">
            <h3 class="text-sm font-semibold text-text-primary">{{ t('shipping.shipments') }}</h3>
            <Button v-if="canShip" size="sm" @click="openShip()">
                <Truck :size="14" />
                {{ t('shipping.ship') }}
            </Button>
        </div>

        <div class="p-5">
            <div v-if="shipments.length" class="space-y-3">
                <div
                    v-for="shipment in shipments"
                    :key="shipment.id"
                    class="rounded-lg border border-border-subtle bg-surface-canvas p-4"
                    :class="{ 'opacity-60': shipment.status === 'cancelled' }"
                >
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-text-primary">
                                {{ [shipment.carrier_name, shipment.service].filter(Boolean).join(' ') || t('shipping.methodManual') }}
                            </p>
                            <p v-if="shipment.tracking_number" class="mt-0.5 text-xs text-text-secondary">
                                {{ t('shipping.trackingNumber') }}:
                                <a
                                    v-if="shipment.tracking_url"
                                    :href="shipment.tracking_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 font-mono text-brand hover:underline"
                                >
                                    {{ shipment.tracking_number }}
                                    <ExternalLink :size="12" />
                                </a>
                                <span v-else class="font-mono">{{ shipment.tracking_number }}</span>
                            </p>
                        </div>
                        <Badge :variant="statusVariant(shipment.status)" size="sm" dot>
                            {{ t(`shipping.statuses.${shipment.status}`) }}
                        </Badge>
                    </div>

                    <ul class="mt-3 space-y-0.5 text-xs text-text-secondary">
                        <li v-for="item in shipment.items" :key="item.order_item_id">
                            <span class="tabular-nums">{{ item.quantity }}</span> × {{ item.product_name }}
                        </li>
                    </ul>

                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-text-tertiary">
                        <span v-if="shipment.warehouse">{{ t('shipping.shipFrom') }}: {{ shipment.warehouse.name }}</span>
                        <span v-if="shipment.cost">{{ formatMoney(shipment.cost, shipment.currency) }}</span>
                        <span v-if="shipment.shipped_at">{{ t('shipping.shippedOn', { date: formatDate(shipment.shipped_at) }) }}</span>
                        <span v-if="shipment.delivered_at">{{ t('shipping.deliveredOn', { date: formatDate(shipment.delivered_at) }) }}</span>
                        <span v-if="shipment.tracking_status_detail && shipment.status !== 'cancelled'">{{ shipment.tracking_status_detail }}</span>
                    </div>

                    <div
                        v-if="shipment.has_label || (shipping.canCreate && ['pending', 'label_created'].includes(shipment.status))"
                        class="mt-3 flex flex-wrap gap-2 border-t border-border-subtle pt-3"
                    >
                        <Button
                            v-if="shipment.has_label && shipment.status !== 'cancelled'"
                            variant="secondary"
                            size="xs"
                            as="a"
                            :href="route('shipments.label', shipment.id)"
                            target="_blank"
                            rel="noopener"
                        >
                            <Download :size="12" />
                            {{ t('shipping.downloadLabel') }}
                        </Button>
                        <template v-if="shipping.canCreate">
                            <Button
                                v-if="shipment.status === 'pending' && shipment.carrier !== 'manual'"
                                variant="secondary"
                                size="xs"
                                @click="openShip(shipment)"
                            >
                                {{ t('shipping.getRates') }}
                            </Button>
                            <Button
                                v-if="['pending', 'label_created'].includes(shipment.status)"
                                variant="secondary"
                                size="xs"
                                :loading="acting === shipment.id"
                                @click="post('shipments.ship', shipment)"
                            >
                                <PackageCheck :size="12" />
                                {{ t('shipping.markShipped') }}
                            </Button>
                            <Button
                                v-if="shipment.status === 'label_created' && shipment.carrier !== 'manual'"
                                variant="ghost"
                                size="xs"
                                :disabled="acting === shipment.id"
                                @click="post('shipments.cancel', shipment, t('shipping.confirmVoid'))"
                            >
                                {{ t('shipping.voidLabel') }}
                            </Button>
                            <Button
                                v-else-if="['pending', 'label_created'].includes(shipment.status)"
                                variant="ghost"
                                size="xs"
                                :disabled="acting === shipment.id"
                                @click="post('shipments.cancel', shipment, t('shipping.confirmCancel'))"
                            >
                                {{ t('shipping.cancelShipment') }}
                            </Button>
                        </template>
                    </div>
                </div>
            </div>

            <p v-else class="text-sm text-text-tertiary">{{ t('shipping.noShipments') }}</p>

            <p v-if="shipments.length && remainingUnits === 0 && order.status !== 'delivered'" class="mt-3 text-xs text-text-tertiary">
                {{ t('shipping.allAllocated') }}
            </p>
        </div>

        <ShipOrderModal
            :show="showModal"
            :order-id="order.id"
            :shipping="shipping"
            :resume-shipment="resumeShipment"
            @close="showModal = false"
        />
    </Card>
</template>
