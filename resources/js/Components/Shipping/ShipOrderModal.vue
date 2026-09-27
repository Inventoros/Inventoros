<script setup>
import Button from '@/Components/ui/Button.vue';
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { X, Download, Truck } from 'lucide-vue-next';

/**
 * The order page "Ship" flow.
 *
 *   details  pick lines and quantities, ship-from, parcel, and either
 *            manual tracking or EasyPost
 *   rates    (EasyPost) choose a quoted rate
 *   done     (EasyPost) label bought: download or print it
 *
 * Manual tracking posts a normal Inertia form. The EasyPost steps use JSON so
 * the modal can move between steps without a page reload; the page reloads
 * its shipping props when the modal closes.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    orderId: { type: Number, required: true },
    shipping: { type: Object, required: true },
    // Resume the rates step for an existing pending carrier shipment.
    resumeShipment: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const { t } = useI18n();

const addressFields = ['name', 'company', 'street1', 'street2', 'city', 'state', 'zip', 'country', 'phone'];

const step = ref('details');
const busy = ref(false);
const error = ref('');
const errors = ref({});
const shipment = ref(null);
const rates = ref([]);
const selectedRate = ref('');
const changed = ref(false);

const form = reactive({});

const hasEasyPost = computed(() => props.shipping.carriers.includes('easypost'));
const isManual = computed(() => form.carrier === 'manual');
const selectedTotal = computed(() => Object.values(form.quantities || {}).reduce((sum, q) => sum + (Number(q) || 0), 0));

const reset = () => {
    const parcel = props.shipping.parcel || {};
    Object.assign(form, {
        carrier: hasEasyPost.value ? 'easypost' : 'manual',
        quantities: Object.fromEntries(props.shipping.lines.map((l) => [l.order_item_id, l.remaining])),
        warehouse_id: props.shipping.defaultWarehouseId ?? '',
        weight_oz: parcel.weight_oz ?? '',
        length_in: parcel.length_in ?? '',
        width_in: parcel.width_in ?? '',
        height_in: parcel.height_in ?? '',
        to_address: Object.fromEntries(addressFields.map((f) => [f, props.shipping.toAddress?.[f] ?? ''])),
        carrier_name: '',
        service: '',
        tracking_number: '',
        tracking_url: '',
        cost: '',
        mark_shipped: true,
        notify_customer: !!props.shipping.notifyCustomers && !!props.shipping.customerEmail,
    });
    step.value = 'details';
    error.value = '';
    errors.value = {};
    shipment.value = null;
    rates.value = [];
    selectedRate.value = '';
    changed.value = false;
};

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        reset();
        if (props.resumeShipment) {
            shipment.value = props.resumeShipment;
            fetchRates();
        }
    },
);

const payload = () => ({
    carrier: form.carrier,
    items: Object.entries(form.quantities)
        .filter(([, qty]) => Number(qty) > 0)
        .map(([id, qty]) => ({ order_item_id: Number(id), quantity: Number(qty) })),
    warehouse_id: form.warehouse_id || null,
    weight_oz: form.weight_oz === '' ? null : form.weight_oz,
    length_in: form.length_in === '' ? null : form.length_in,
    width_in: form.width_in === '' ? null : form.width_in,
    height_in: form.height_in === '' ? null : form.height_in,
    notify_customer: form.notify_customer,
    ...(isManual.value
        ? {
            carrier_name: form.carrier_name || null,
            service: form.service || null,
            tracking_number: form.tracking_number || null,
            tracking_url: form.tracking_url || null,
            cost: form.cost === '' ? null : form.cost,
            mark_shipped: form.mark_shipped,
        }
        : { to_address: form.to_address }),
});

const handleError = (e) => {
    const data = e.response?.data || {};
    error.value = data.message || e.message;
    errors.value = data.errors || {};
    if (data.shipment) {
        shipment.value = data.shipment;
        changed.value = true;
    }
};

const submitDetails = () => {
    error.value = '';
    errors.value = {};

    if (selectedTotal.value === 0) {
        error.value = t('shipping.noItemsSelected');
        return;
    }

    if (isManual.value) {
        busy.value = true;
        router.post(route('orders.shipments.store', props.orderId), payload(), {
            preserveScroll: true,
            onSuccess: (page) => {
                // Refusals (for example over-shipping) come back as a flash.
                if (page.props.flash?.error) {
                    error.value = page.props.flash.error;
                } else {
                    emit('close');
                }
            },
            onError: (bag) => {
                errors.value = bag;
                error.value = Object.values(bag)[0] || '';
            },
            onFinish: () => (busy.value = false),
        });
        return;
    }

    busy.value = true;
    window.axios
        .post(route('orders.shipments.store', props.orderId), payload())
        .then(({ data }) => {
            shipment.value = data.shipment;
            rates.value = data.rates;
            selectedRate.value = data.rates[0]?.id || '';
            changed.value = true;
            step.value = 'rates';
        })
        .catch(handleError)
        .finally(() => (busy.value = false));
};

const fetchRates = () => {
    busy.value = true;
    error.value = '';
    window.axios
        .post(route('shipments.rates', shipment.value.id))
        .then(({ data }) => {
            rates.value = data.rates;
            selectedRate.value = data.rates[0]?.id || '';
            step.value = 'rates';
        })
        .catch(handleError)
        .finally(() => (busy.value = false));
};

const buyLabel = () => {
    if (!selectedRate.value) return;
    busy.value = true;
    error.value = '';
    window.axios
        .post(route('shipments.buy', shipment.value.id), { rate_id: selectedRate.value })
        .then(({ data }) => {
            shipment.value = data.shipment;
            changed.value = true;
            step.value = 'done';
        })
        .catch(handleError)
        .finally(() => (busy.value = false));
};

const close = () => {
    if (busy.value) return;
    emit('close');
    if (changed.value) {
        router.reload({ only: ['order', 'shipments', 'shipping'], preserveScroll: true });
    }
};

const formatMoney = (amount, currency) => {
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'USD' }).format(Number(amount));
    } catch {
        return `${amount} ${currency || ''}`;
    }
};

const inputClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ' +
    'placeholder:text-text-tertiary transition-colors hover:border-border-strong ds-focus-ring';
const labelClass = 'mb-1 block text-sm font-medium text-text-secondary';
const errorClass = 'mt-1 text-xs text-status-danger';
const sectionTitle = 'text-xs font-semibold uppercase tracking-wide text-text-tertiary';
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto py-8" @click="close">
            <div class="fixed inset-0 bg-black/50"></div>

            <div
                class="relative mx-4 w-full max-w-2xl rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg"
                role="dialog"
                aria-modal="true"
                @click.stop
            >
                <div class="mb-5 flex items-center justify-between">
                    <h3 class="text-base font-semibold text-text-primary">
                        {{ step === 'rates' ? t('shipping.chooseRate') : t('shipping.shipItems') }}
                    </h3>
                    <button
                        type="button"
                        class="text-text-tertiary transition-colors hover:text-text-primary ds-focus-ring"
                        :aria-label="t('shipping.close')"
                        @click="close"
                    >
                        <X :size="18" />
                    </button>
                </div>

                <div v-if="error" class="mb-4 rounded-lg border border-status-danger/20 bg-status-danger-soft px-4 py-3 text-sm text-status-danger" role="alert">
                    {{ error }}
                </div>

                <!-- Step 1: details -->
                <form v-if="step === 'details'" class="space-y-6" @submit.prevent="submitDetails">
                    <div v-if="hasEasyPost">
                        <p :class="sectionTitle">{{ t('shipping.method') }}</p>
                        <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <label
                                v-for="option in [{ value: 'easypost', label: t('shipping.methodEasypost') }, { value: 'manual', label: t('shipping.methodManual') }]"
                                :key="option.value"
                                :class="[
                                    'flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2.5 text-sm',
                                    form.carrier === option.value ? 'border-brand bg-brand-soft text-text-primary' : 'border-border-subtle text-text-secondary hover:border-border-strong',
                                ]"
                            >
                                <input v-model="form.carrier" type="radio" :value="option.value" class="text-brand ds-focus-ring" />
                                {{ option.label }}
                            </label>
                        </div>
                    </div>

                    <!-- Lines -->
                    <div>
                        <p :class="sectionTitle">{{ t('shipping.items') }}</p>
                        <div class="mt-2 divide-y divide-border-subtle rounded-lg border border-border-subtle">
                            <div v-for="line in shipping.lines" :key="line.order_item_id" class="flex items-center gap-3 px-3 py-2.5">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-text-primary">{{ line.product_name }}</p>
                                    <p class="text-xs text-text-tertiary">{{ line.sku }} · {{ t('shipping.remaining', { count: line.remaining }) }}</p>
                                </div>
                                <label class="sr-only" :for="`qty-${line.order_item_id}`">{{ t('shipping.quantity') }}</label>
                                <input
                                    :id="`qty-${line.order_item_id}`"
                                    v-model.number="form.quantities[line.order_item_id]"
                                    type="number"
                                    min="0"
                                    :max="line.remaining"
                                    :disabled="line.remaining === 0"
                                    class="h-8 w-20 rounded-md border border-border-subtle bg-surface-canvas px-2 text-right text-sm tabular-nums text-text-primary ds-focus-ring disabled:opacity-50"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Ship from + parcel -->
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label :class="labelClass" for="ship-warehouse">{{ t('shipping.shipFrom') }}</label>
                            <select id="ship-warehouse" v-model="form.warehouse_id" :class="inputClass">
                                <option value="">{{ t('shipping.settings.none') }}</option>
                                <option v-for="warehouse in shipping.warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="labelClass" for="ship-weight">{{ t('shipping.weightOz') }}</label>
                            <input id="ship-weight" v-model="form.weight_oz" type="number" min="0" step="0.1" :class="inputClass" />
                            <p v-if="errors.weight_oz" :class="errorClass">{{ errors.weight_oz[0] || errors.weight_oz }}</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div v-for="(label, field) in { length_in: 'lengthIn', width_in: 'widthIn', height_in: 'heightIn' }" :key="field">
                            <label :class="labelClass" :for="`ship-${field}`">{{ t(`shipping.${label}`) }}</label>
                            <input :id="`ship-${field}`" v-model="form[field]" type="number" min="0" step="0.1" :class="inputClass" />
                        </div>
                    </div>

                    <!-- EasyPost: ship-to address -->
                    <div v-if="!isManual">
                        <p :class="sectionTitle">{{ t('shipping.shipTo') }}</p>
                        <div class="mt-2 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div v-for="field in addressFields" :key="field">
                                <label :class="labelClass" :for="`to-${field}`">{{ t(`shipping.address.${field}`) }}</label>
                                <input :id="`to-${field}`" v-model="form.to_address[field]" type="text" :maxlength="field === 'country' ? 2 : 255" :class="inputClass" />
                            </div>
                        </div>
                    </div>

                    <!-- Manual tracking -->
                    <div v-else class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label :class="labelClass" for="ship-carrier">{{ t('shipping.carrier') }}</label>
                                <input id="ship-carrier" v-model="form.carrier_name" type="text" maxlength="100" :placeholder="t('shipping.carrierPlaceholder')" :class="inputClass" />
                            </div>
                            <div>
                                <label :class="labelClass" for="ship-service">{{ t('shipping.service') }}</label>
                                <input id="ship-service" v-model="form.service" type="text" maxlength="100" :class="inputClass" />
                            </div>
                            <div>
                                <label :class="labelClass" for="ship-tracking">{{ t('shipping.trackingNumber') }}</label>
                                <input id="ship-tracking" v-model="form.tracking_number" type="text" maxlength="100" :class="inputClass" />
                            </div>
                            <div>
                                <label :class="labelClass" for="ship-cost">{{ t('shipping.cost') }}</label>
                                <input id="ship-cost" v-model="form.cost" type="number" min="0" step="0.01" :class="inputClass" />
                            </div>
                        </div>
                        <div>
                            <label :class="labelClass" for="ship-tracking-url">{{ t('shipping.trackingUrl') }}</label>
                            <input id="ship-tracking-url" v-model="form.tracking_url" type="url" maxlength="2048" :class="inputClass" />
                            <p class="mt-1 text-xs text-text-tertiary">{{ t('shipping.trackingUrlHint') }}</p>
                            <p v-if="errors.tracking_url" :class="errorClass">{{ errors.tracking_url }}</p>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-text-primary">
                            <input v-model="form.mark_shipped" type="checkbox" class="rounded border-border-strong text-brand ds-focus-ring" />
                            {{ t('shipping.markShippedNow') }}
                        </label>
                    </div>

                    <label v-if="shipping.customerEmail" class="flex items-center gap-2 text-sm text-text-primary">
                        <input v-model="form.notify_customer" type="checkbox" class="rounded border-border-strong text-brand ds-focus-ring" />
                        {{ t('shipping.notifyCustomer', { email: shipping.customerEmail }) }}
                    </label>

                    <div class="flex justify-end gap-3 border-t border-border-subtle pt-4">
                        <Button variant="secondary" :disabled="busy" @click="close">{{ t('common.cancel') }}</Button>
                        <Button type="submit" :loading="busy" :disabled="busy || selectedTotal === 0">
                            <Truck :size="14" />
                            {{ isManual ? t('shipping.saveShipment') : t('shipping.createAndGetRates') }}
                        </Button>
                    </div>
                </form>

                <!-- Step 2: rates -->
                <div v-else-if="step === 'rates'" class="space-y-4">
                    <fieldset class="space-y-2">
                        <legend class="sr-only">{{ t('shipping.chooseRate') }}</legend>
                        <label
                            v-for="rate in rates"
                            :key="rate.id"
                            :class="[
                                'flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-3',
                                selectedRate === rate.id ? 'border-brand bg-brand-soft' : 'border-border-subtle hover:border-border-strong',
                            ]"
                        >
                            <input v-model="selectedRate" type="radio" :value="rate.id" class="text-brand ds-focus-ring" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-text-primary">{{ rate.carrier }} {{ rate.service }}</p>
                                <p v-if="rate.delivery_days" class="text-xs text-text-tertiary">{{ t('shipping.estDays', { count: rate.delivery_days }) }}</p>
                            </div>
                            <span class="text-sm font-semibold tabular-nums text-text-primary">{{ formatMoney(rate.amount, rate.currency) }}</span>
                        </label>
                    </fieldset>

                    <div class="flex justify-end gap-3 border-t border-border-subtle pt-4">
                        <Button variant="secondary" :disabled="busy" @click="fetchRates">{{ t('shipping.getRates') }}</Button>
                        <Button :loading="busy" :disabled="busy || !selectedRate" @click="buyLabel">{{ t('shipping.buyLabel') }}</Button>
                    </div>
                </div>

                <!-- Step 3: done -->
                <div v-else class="space-y-5">
                    <p class="text-sm text-text-secondary">{{ t('shipping.labelBought', { number: shipment?.tracking_number }) }}</p>
                    <div class="flex flex-wrap justify-end gap-3 border-t border-border-subtle pt-4">
                        <Button
                            v-if="shipment?.has_label"
                            variant="secondary"
                            as="a"
                            :href="route('shipments.label', shipment.id)"
                            target="_blank"
                            rel="noopener"
                        >
                            <Download :size="14" />
                            {{ t('shipping.downloadLabel') }}
                        </Button>
                        <Button @click="close">{{ t('shipping.close') }}</Button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
