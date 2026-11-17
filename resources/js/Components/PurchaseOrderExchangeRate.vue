<script setup>
// The exchange rate panel of the purchase order create and edit forms.
//
// For a currency other than the organization's it asks the server which
// rate the PO would take for its order date and shows it with its provider,
// date and age. A user allowed to override can accept a stale rate or enter
// one; otherwise the rate is applied on save. The server decides the final
// snapshot: this only sets exchange_rate_mode / exchange_rate on the form.
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { displayCalendarDate, toIsoDate } from '@/lib/dates';
import { formatMoney } from '@/lib/money';

const { t } = useI18n();

const props = defineProps({
    form: { type: Object, required: true },
    currency: { type: String, default: '' },
    orderDate: { type: String, default: '' },
    organizationCurrency: { type: String, required: true },
    total: { type: Number, default: 0 },
    canOverride: { type: Boolean, default: false },
    // The saved snapshot when editing (exchangeRateSummary), else null.
    current: { type: Object, default: null },
});

const emit = defineEmits(['rate']);

const foreign = computed(() => !!props.currency && props.currency.toUpperCase() !== props.organizationCurrency.toUpperCase());
const quote = ref(null);
const loading = ref(false);
const failed = ref(false);

// The server keeps the draft's saved rate while its currency stays: an
// entered or confirmed rate until changed, a provider rate while the order
// date stays too.
const keepsSaved = computed(() => !props.form.exchange_rate_mode
    && !!props.current?.rate
    && props.current?.from === props.currency?.toUpperCase()
    && (['manual', 'confirmed'].includes(props.current?.source) || toIsoDate(props.current?.order_date) === props.orderDate));

let requestId = 0;
const load = async () => {
    if (!foreign.value) {
        quote.value = null;
        return;
    }
    const id = ++requestId;
    loading.value = true;
    failed.value = false;
    try {
        const { data } = await axios.get(route('purchase-orders.exchange-rate'), {
            params: { currency: props.currency.toUpperCase(), date: props.orderDate || undefined },
        });
        if (id === requestId) quote.value = data.data;
    } catch {
        if (id === requestId) {
            quote.value = null;
            failed.value = true;
        }
    } finally {
        if (id === requestId) loading.value = false;
    }
};

watch(() => [props.currency, props.orderDate], load, { immediate: true });

// A manual or confirmed choice belongs to one currency.
watch(() => props.currency, (next, previous) => {
    if (previous && next !== previous && ['manual', 'confirm'].includes(props.form.exchange_rate_mode)) {
        props.form.exchange_rate_mode = null;
        props.form.exchange_rate = null;
    }
});

// The rate the PO will be saved with, for the converted amounts shown.
const effectiveRate = computed(() => {
    if (!foreign.value) return null;
    if (props.form.exchange_rate_mode === 'manual') return props.form.exchange_rate || null;
    if (keepsSaved.value) return props.current.rate;
    if (props.form.exchange_rate_mode === 'confirm') return quote.value?.rate ?? null;
    if (quote.value?.status === 'ok') return quote.value.rate;
    return null;
});

watch(effectiveRate, (rate) => emit('rate', rate), { immediate: true });

const convertedTotal = computed(() => effectiveRate.value ? Number(props.total) * Number(effectiveRate.value) : null);

const providerLabel = computed(() => quote.value?.provider_name || quote.value?.provider || '');

const errorText = computed(() => {
    if (failed.value) return t('exchangeRates.po.lookupFailed');
    const error = quote.value?.error;
    if (!error) return t('exchangeRates.po.noRate');
    return t(`exchangeRates.errors.${error}`, { provider: providerLabel.value || quote.value?.selected_provider });
});

const manualOpen = ref(props.form.exchange_rate_mode === 'manual');
const openManual = () => {
    manualOpen.value = true;
    props.form.exchange_rate_mode = 'manual';
    props.form.exchange_rate = props.form.exchange_rate || quote.value?.rate || props.current?.rate || '';
};
const useProvider = () => {
    manualOpen.value = false;
    props.form.exchange_rate_mode = 'auto';
    props.form.exchange_rate = null;
};
const confirmStale = () => {
    manualOpen.value = false;
    props.form.exchange_rate_mode = 'confirm';
    props.form.exchange_rate = null;
};

const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
</script>

<template>
    <div v-if="foreign" class="rounded-lg border border-border-subtle bg-surface-canvas p-4" data-testid="po-exchange-rate">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h4 class="text-sm font-medium text-text-primary">{{ t('exchangeRates.po.title') }}</h4>
                <p class="mt-0.5 text-xs text-text-tertiary">{{ t('exchangeRates.po.help', { from: currency.toUpperCase(), to: organizationCurrency }) }}</p>
            </div>
            <Badge v-if="form.exchange_rate_mode === 'manual' || keepsSaved && current?.source === 'manual'" variant="info" size="sm">{{ t('exchangeRates.source.manual') }}</Badge>
            <Badge v-else-if="form.exchange_rate_mode === 'confirm' || keepsSaved && current?.source === 'confirmed'" variant="info" size="sm">{{ t('exchangeRates.source.confirmed') }}</Badge>
            <Badge v-else-if="quote?.status === 'stale'" variant="warning" size="sm" dot>{{ t('exchangeRates.stale') }}</Badge>
            <Badge v-else-if="quote?.is_fallback" variant="warning" size="sm">{{ t('exchangeRates.fallback') }}</Badge>
        </div>

        <div class="mt-3 text-sm">
            <p v-if="loading" class="text-text-tertiary">{{ t('exchangeRates.po.loading') }}</p>

            <template v-else-if="keepsSaved && form.exchange_rate_mode !== 'manual'">
                <p class="tabular-nums text-text-primary">1 {{ current.from }} = {{ current.rate }} {{ current.to }}</p>
                <p v-if="current.provider_name && current.effective_date" class="text-xs text-text-tertiary">
                    {{ t('exchangeRates.po.rateSource', { provider: current.provider_name, date: displayCalendarDate(current.effective_date) }) }}
                </p>
                <p class="text-xs text-text-tertiary">{{ t('exchangeRates.po.keptRate') }}</p>
            </template>

            <template v-else-if="form.exchange_rate_mode !== 'manual' && quote?.rate">
                <p class="tabular-nums text-text-primary">1 {{ quote.from }} = {{ quote.rate }} {{ quote.to }}</p>
                <p class="text-xs text-text-tertiary">
                    {{ t('exchangeRates.po.rateSource', { provider: providerLabel, date: displayCalendarDate(quote.effective_date) }) }}
                </p>
                <p v-if="quote.is_fallback" class="mt-1 text-xs text-status-warning">{{ t('exchangeRates.po.fallbackNotice', { provider: providerLabel }) }}</p>
                <p v-if="quote.status === 'stale'" class="mt-1 text-xs text-status-warning">
                    {{ t('exchangeRates.po.staleNotice', { count: quote.age_days, days: quote.stale_after_days }) }}
                </p>
            </template>

            <p v-else-if="form.exchange_rate_mode !== 'manual'" class="text-xs text-status-warning">{{ errorText }} {{ t('exchangeRates.po.savedWithout') }}</p>

            <div v-if="manualOpen || form.exchange_rate_mode === 'manual'" class="mt-2 max-w-xs">
                <label for="po_exchange_rate" class="mb-1 block text-xs text-text-secondary">{{ t('exchangeRates.po.manualLabel', { from: currency.toUpperCase(), to: organizationCurrency }) }}</label>
                <input id="po_exchange_rate" v-model="form.exchange_rate" type="number" step="any" min="0" inputmode="decimal" :class="fieldInput" />
            </div>
            <p v-if="form.errors.exchange_rate" class="mt-1 text-xs text-status-danger">{{ form.errors.exchange_rate }}</p>
            <p v-if="form.errors.exchange_rate_mode" class="mt-1 text-xs text-status-danger">{{ form.errors.exchange_rate_mode }}</p>

            <p v-if="convertedTotal !== null" class="mt-3 text-sm text-text-secondary">
                {{ t('exchangeRates.po.convertedTotal', { currency: organizationCurrency }) }}
                <span class="font-semibold tabular-nums text-text-primary">{{ formatMoney(convertedTotal, organizationCurrency) }}</span>
            </p>
        </div>

        <div v-if="canOverride && !loading" class="mt-3 flex flex-wrap gap-2">
            <Button v-if="quote?.status === 'stale' && form.exchange_rate_mode !== 'confirm'" type="button" variant="secondary" size="sm" @click="confirmStale">
                {{ t('exchangeRates.po.useStale') }}
            </Button>
            <Button v-if="form.exchange_rate_mode !== 'manual'" type="button" variant="secondary" size="sm" @click="openManual">
                {{ t('exchangeRates.po.enterManually') }}
            </Button>
            <Button v-if="form.exchange_rate_mode === 'manual' || form.exchange_rate_mode === 'confirm' || keepsSaved" type="button" variant="ghost" size="sm" @click="useProvider">
                {{ t('exchangeRates.po.useProviderRate') }}
            </Button>
        </div>
    </div>
</template>
