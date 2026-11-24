<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import TableViewport from '@/Components/ui/TableViewport.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RefreshCw, Trash2, Plus } from '@lucide/vue';
import { displayCalendarDate, displayDateTime } from '@/lib/dates';

const { t } = useI18n();

const props = defineProps({
    settings: Object,
    providerAvailable: Boolean,
    fallbackAvailable: Boolean,
    providers: Array,
    organizationCurrency: String,
    trackedCurrencies: Array,
    supplierCurrencies: Array,
    currencyOptions: Array,
    providerCurrencies: { type: Array, default: null },
    frequencies: Array,
    rates: Array,
    manualRates: Array,
    today: String,
});

// Every provider's settings travel in one object keyed by provider id, so
// switching the selected provider never loses what was typed for another.
const initialConfig = Object.fromEntries(
    props.providers.map((provider) => [
        provider.id,
        Object.fromEntries(provider.fields.map((field) => [field.key, field.type === 'secret' ? '' : field.value])),
    ]),
);

const form = useForm({
    provider: props.providerAvailable ? props.settings.provider : (props.providers[0]?.id ?? ''),
    fallback_provider: props.fallbackAvailable ? (props.settings.fallback_provider ?? '') : '',
    currencies: [...props.settings.currencies],
    stale_after_days: props.settings.stale_after_days,
    sync_enabled: props.settings.sync_enabled,
    sync_frequency: props.settings.sync_frequency,
    config: initialConfig,
});

const providerById = (id) => props.providers.find((provider) => provider.id === id) ?? null;
const selectedProvider = computed(() => providerById(form.provider));
const fallbackProvider = computed(() => providerById(form.fallback_provider));
const configuredProviders = computed(() => [selectedProvider.value, fallbackProvider.value].filter((p) => p && p.fields.length));

const submit = () => {
    form
        .transform((data) => ({ ...data, fallback_provider: data.fallback_provider || null }))
        .patch(route('settings.exchange-rates.update'), {
            preserveScroll: true,
            onSuccess: () => {
                for (const provider of props.providers) {
                    for (const field of provider.fields) {
                        if (field.type === 'secret') form.config[provider.id][field.key] = '';
                    }
                }
            },
        });
};

const toggleCurrency = (code) => {
    const index = form.currencies.indexOf(code);
    if (index >= 0) form.currencies.splice(index, 1);
    else form.currencies.push(code);
};

const syncing = ref(false);
const syncNow = () => {
    syncing.value = true;
    router.post(route('settings.exchange-rates.sync'), {}, {
        preserveScroll: true,
        onFinish: () => (syncing.value = false),
    });
};

const rateForm = useForm({
    currency: props.trackedCurrencies[0] ?? props.currencyOptions[0] ?? '',
    rate: '',
    effective_date: props.today,
});

const addRate = () => {
    rateForm.post(route('settings.exchange-rates.rates.store'), {
        preserveScroll: true,
        onSuccess: () => rateForm.reset('rate'),
    });
};

const deleteRate = (rate) => {
    if (confirm(t('exchangeRates.settings.confirmDeleteRate', { currency: rate.currency, date: displayCalendarDate(rate.effective_date) }))) {
        router.delete(route('settings.exchange-rates.rates.destroy', rate.id), { preserveScroll: true });
    }
};

const syncStatusVariant = computed(() => ({
    success: 'success',
    partial: 'warning',
    failed: 'danger',
}[props.settings.last_sync_status] ?? 'neutral'));

const syncStatusLabel = computed(() => props.settings.last_sync_status
    ? t(`exchangeRates.settings.syncStatus.${props.settings.last_sync_status}`)
    : t('exchangeRates.settings.syncStatus.never'));

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
const fieldHelp = 'mt-1 text-xs text-text-tertiary';
const thClass = 'px-4 py-2.5 text-start text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('exchangeRates.settings.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link data-crumb-root :href="route('settings.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('settings.index')" class="text-text-tertiary hover:text-text-primary">{{ t('settings.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('exchangeRates.settings.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('exchangeRates.settings.title')" :description="t('exchangeRates.settings.description', { currency: organizationCurrency })" />

        <div v-if="!providerAvailable" class="mt-6 rounded-lg border border-status-danger/20 bg-status-danger-soft p-4 text-sm text-status-danger" role="alert">
            {{ t('exchangeRates.settings.providerMissing', { provider: settings.provider }) }}
        </div>
        <div v-if="!fallbackAvailable" class="mt-4 rounded-lg border border-status-warning/20 bg-status-warning-soft p-4 text-sm text-status-warning" role="alert">
            {{ t('exchangeRates.settings.fallbackMissing', { provider: settings.fallback_provider }) }}
        </div>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <!-- Provider -->
            <Card>
                <h3 class="text-sm font-semibold text-text-primary">{{ t('exchangeRates.settings.provider') }}</h3>
                <p class="mt-1 text-sm text-text-secondary">{{ t('exchangeRates.settings.providerHelp') }}</p>

                <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label :class="fieldLabel" for="provider">{{ t('exchangeRates.settings.defaultProvider') }}</label>
                        <select id="provider" v-model="form.provider" :class="fieldInput">
                            <option v-for="provider in providers" :key="provider.id" :value="provider.id">{{ provider.name }}</option>
                        </select>
                        <p v-if="form.errors.provider" :class="fieldError">{{ form.errors.provider }}</p>
                        <p v-if="selectedProvider" :class="fieldHelp">{{ selectedProvider.description }}</p>
                        <div v-if="selectedProvider" class="mt-2 flex flex-wrap gap-1.5">
                            <Badge v-if="selectedProvider.plugin" variant="info" size="sm">{{ t('exchangeRates.settings.fromPlugin', { plugin: selectedProvider.plugin }) }}</Badge>
                            <Badge v-if="selectedProvider.capabilities.automatic" variant="neutral" size="sm">{{ t('exchangeRates.settings.capability.automatic') }}</Badge>
                            <Badge v-else variant="neutral" size="sm">{{ t('exchangeRates.settings.capability.manual') }}</Badge>
                            <Badge v-if="selectedProvider.capabilities.historical" variant="neutral" size="sm">{{ t('exchangeRates.settings.capability.historical') }}</Badge>
                            <Badge v-if="selectedProvider.capabilities.shareable" variant="neutral" size="sm">{{ t('exchangeRates.settings.capability.shareable') }}</Badge>
                        </div>
                    </div>

                    <div>
                        <label :class="fieldLabel" for="fallback_provider">{{ t('exchangeRates.settings.fallbackProvider') }}</label>
                        <select id="fallback_provider" v-model="form.fallback_provider" :class="fieldInput">
                            <option value="">{{ t('exchangeRates.settings.noFallback') }}</option>
                            <option v-for="provider in providers.filter((p) => p.id !== form.provider)" :key="provider.id" :value="provider.id">{{ provider.name }}</option>
                        </select>
                        <p v-if="form.errors.fallback_provider" :class="fieldError">{{ form.errors.fallback_provider }}</p>
                        <p :class="fieldHelp">{{ t('exchangeRates.settings.fallbackHelp') }}</p>
                    </div>
                </div>

                <div v-for="provider in configuredProviders" :key="provider.id" class="mt-5 border-t border-border-subtle pt-5">
                    <h4 class="text-sm font-medium text-text-primary">{{ t('exchangeRates.settings.providerSettings', { provider: provider.name }) }}</h4>
                    <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div v-for="field in provider.fields" :key="field.key">
                            <label :class="fieldLabel" :for="`config_${provider.id}_${field.key}`">{{ field.label }}<span v-if="field.required"> *</span></label>
                            <input
                                :id="`config_${provider.id}_${field.key}`"
                                v-model="form.config[provider.id][field.key]"
                                :type="field.type === 'secret' ? 'password' : (field.type === 'url' ? 'url' : 'text')"
                                :autocomplete="field.type === 'secret' ? 'new-password' : 'off'"
                                :placeholder="field.type === 'secret' && field.is_set ? t('exchangeRates.settings.secretSaved') : ''"
                                :class="fieldInput"
                            />
                            <p v-if="field.help" :class="fieldHelp">{{ field.help }}</p>
                            <p v-if="form.errors[`config.${provider.id}.${field.key}`]" :class="fieldError">{{ form.errors[`config.${provider.id}.${field.key}`] }}</p>
                        </div>
                    </div>
                </div>

                <div class="mt-5 md:w-1/2">
                    <label :class="fieldLabel" for="stale_after_days">{{ t('exchangeRates.settings.staleAfter') }}</label>
                    <input id="stale_after_days" v-model.number="form.stale_after_days" type="number" min="1" max="60" :class="fieldInput" />
                    <p v-if="form.errors.stale_after_days" :class="fieldError">{{ form.errors.stale_after_days }}</p>
                    <p :class="fieldHelp">{{ t('exchangeRates.settings.staleAfterHelp') }}</p>
                </div>
            </Card>

            <!-- Currencies and synchronization -->
            <Card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('exchangeRates.settings.sync') }}</h3>
                        <p class="mt-1 text-sm text-text-secondary">{{ t('exchangeRates.settings.syncHelp') }}</p>
                    </div>
                    <Button variant="secondary" size="sm" type="button" :loading="syncing" :disabled="syncing || !providerAvailable" @click="syncNow">
                        <RefreshCw :size="14" />
                        {{ t('exchangeRates.settings.refreshNow') }}
                    </Button>
                </div>

                <div class="mt-5 space-y-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label class="flex items-center gap-2 text-sm text-text-primary">
                            <input v-model="form.sync_enabled" type="checkbox" class="rounded border-border-strong text-brand ds-focus-ring" />
                            {{ t('exchangeRates.settings.syncEnabled') }}
                        </label>
                        <div>
                            <label :class="fieldLabel" for="sync_frequency">{{ t('exchangeRates.settings.frequency') }}</label>
                            <select id="sync_frequency" v-model="form.sync_frequency" :class="fieldInput" :disabled="!form.sync_enabled">
                                <option v-for="frequency in frequencies" :key="frequency" :value="frequency">{{ t(`exchangeRates.settings.frequencies.${frequency}`) }}</option>
                            </select>
                            <p v-if="form.errors.sync_frequency" :class="fieldError">{{ form.errors.sync_frequency }}</p>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-sm font-medium text-text-primary">{{ t('exchangeRates.settings.currencies') }}</h4>
                        <p :class="fieldHelp">{{ t('exchangeRates.settings.currenciesHelp', { currency: organizationCurrency }) }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <label
                                v-for="code in currencyOptions"
                                :key="code"
                                class="flex items-center gap-1.5 rounded-md border border-border-subtle px-2.5 py-1.5 text-xs text-text-primary"
                                :class="providerCurrencies && !providerCurrencies.includes(code) ? 'opacity-60' : ''"
                            >
                                <input
                                    type="checkbox"
                                    class="rounded border-border-strong text-brand ds-focus-ring"
                                    :checked="form.currencies.includes(code) || supplierCurrencies.includes(code)"
                                    :disabled="supplierCurrencies.includes(code)"
                                    @change="toggleCurrency(code)"
                                />
                                <span class="font-mono">{{ code }}</span>
                                <span v-if="supplierCurrencies.includes(code)" class="text-text-tertiary">{{ t('exchangeRates.settings.fromSuppliers') }}</span>
                            </label>
                        </div>
                        <p v-if="form.errors.currencies" :class="fieldError">{{ form.errors.currencies }}</p>
                        <p v-if="providerCurrencies === null && providerAvailable" :class="fieldHelp">{{ t('exchangeRates.settings.providerCurrenciesUnknown') }}</p>
                        <p v-else-if="providerCurrencies" :class="fieldHelp">{{ t('exchangeRates.settings.unsupportedDimmed') }}</p>
                    </div>

                    <dl class="grid grid-cols-1 gap-4 border-t border-border-subtle pt-5 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt class="text-xs text-text-tertiary">{{ t('exchangeRates.settings.lastStatus') }}</dt>
                            <dd class="mt-1"><Badge :variant="syncStatusVariant" size="sm" dot>{{ syncStatusLabel }}</Badge></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-text-tertiary">{{ t('exchangeRates.settings.lastSuccess') }}</dt>
                            <dd class="mt-1 text-text-primary">{{ settings.last_synced_at ? displayDateTime(settings.last_synced_at) : '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-text-tertiary">{{ t('exchangeRates.settings.lastAttempt') }}</dt>
                            <dd class="mt-1 text-text-primary">{{ settings.last_sync_attempt_at ? displayDateTime(settings.last_sync_attempt_at) : '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-text-tertiary">{{ t('exchangeRates.settings.nextAttempt') }}</dt>
                            <dd class="mt-1 text-text-primary">{{ settings.sync_enabled && settings.next_sync_at ? displayDateTime(settings.next_sync_at) : '-' }}</dd>
                        </div>
                    </dl>
                    <div v-if="settings.last_sync_error" class="rounded-md border border-status-warning/20 bg-status-warning-soft p-3 text-xs text-status-warning">
                        <p class="font-medium">{{ t('exchangeRates.settings.lastError') }}</p>
                        <p class="mt-1 break-words font-mono">{{ settings.last_sync_error }}</p>
                    </div>
                </div>
            </Card>

            <div class="flex justify-end">
                <Button type="submit" :loading="form.processing" :disabled="form.processing">
                    {{ t('exchangeRates.settings.save') }}
                </Button>
            </div>
        </form>

        <!-- Current rates -->
        <Card class="mt-4" :padded="false">
            <div class="px-5 pt-5">
                <h3 class="text-sm font-semibold text-text-primary">{{ t('exchangeRates.settings.currentRates') }}</h3>
                <p class="mt-1 text-sm text-text-secondary">{{ t('exchangeRates.settings.currentRatesHelp', { currency: organizationCurrency, days: settings.stale_after_days }) }}</p>
            </div>
            <div class="p-5">
                <p v-if="rates.length === 0" class="text-sm text-text-tertiary">{{ t('exchangeRates.settings.noTrackedCurrencies') }}</p>
                <TableViewport v-else class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border-subtle">
                                <th :class="thClass">{{ t('common.currency') }}</th>
                                <th :class="thClass">{{ t('exchangeRates.rate') }}</th>
                                <th :class="thClass">{{ t('exchangeRates.provider') }}</th>
                                <th :class="thClass">{{ t('exchangeRates.effectiveDate') }}</th>
                                <th :class="thClass">{{ t('exchangeRates.retrievedAt') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rates" :key="`${row.provider}-${row.currency}`" class="border-b border-border-subtle last:border-b-0">
                                <td class="px-4 py-2.5 font-mono text-text-primary">{{ row.currency }}</td>
                                <td class="px-4 py-2.5 tabular-nums text-text-primary">
                                    <template v-if="row.rate">1 {{ row.currency }} = {{ row.rate }} {{ organizationCurrency }}</template>
                                    <span v-else class="text-text-tertiary">{{ t('exchangeRates.noRateYet') }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-text-secondary">
                                    {{ row.provider_name }}
                                    <Badge v-if="row.is_fallback" variant="neutral" size="sm" class="ms-1">{{ t('exchangeRates.fallback') }}</Badge>
                                </td>
                                <td class="px-4 py-2.5 text-text-secondary">
                                    {{ row.effective_date ? displayCalendarDate(row.effective_date) : '-' }}
                                    <Badge v-if="row.stale" variant="warning" size="sm" class="ms-1">{{ t('exchangeRates.staleDays', { count: row.age_days }) }}</Badge>
                                </td>
                                <td class="px-4 py-2.5 text-text-tertiary">{{ row.retrieved_at ? displayDateTime(row.retrieved_at) : '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </TableViewport>
            </div>
        </Card>

        <!-- Manual rates -->
        <Card class="mt-4" :padded="false">
            <div class="px-5 pt-5">
                <h3 class="text-sm font-semibold text-text-primary">{{ t('exchangeRates.settings.manualRates') }}</h3>
                <p class="mt-1 text-sm text-text-secondary">{{ t('exchangeRates.settings.manualRatesHelp') }}</p>
            </div>
            <div class="space-y-4 p-5">
                <form class="grid grid-cols-1 gap-3 md:grid-cols-4" @submit.prevent="addRate">
                    <div>
                        <label :class="fieldLabel" for="manual_currency">{{ t('common.currency') }}</label>
                        <select id="manual_currency" v-model="rateForm.currency" :class="fieldInput">
                            <option v-for="code in currencyOptions" :key="code" :value="code">{{ code }}</option>
                        </select>
                        <p v-if="rateForm.errors.currency" :class="fieldError">{{ rateForm.errors.currency }}</p>
                    </div>
                    <div>
                        <label :class="fieldLabel" for="manual_rate">{{ t('exchangeRates.settings.rateIn', { currency: rateForm.currency, organizationCurrency }) }}</label>
                        <input id="manual_rate" v-model="rateForm.rate" type="number" step="any" min="0" inputmode="decimal" :class="fieldInput" />
                        <p v-if="rateForm.errors.rate" :class="fieldError">{{ rateForm.errors.rate }}</p>
                    </div>
                    <div>
                        <label :class="fieldLabel" for="manual_date">{{ t('exchangeRates.effectiveDate') }}</label>
                        <input id="manual_date" v-model="rateForm.effective_date" type="date" :max="today" :class="fieldInput" />
                        <p v-if="rateForm.errors.effective_date" :class="fieldError">{{ rateForm.errors.effective_date }}</p>
                    </div>
                    <div class="flex items-end">
                        <Button type="submit" variant="secondary" class="w-full" :loading="rateForm.processing" :disabled="rateForm.processing || !rateForm.rate">
                            <Plus :size="14" />
                            {{ t('exchangeRates.settings.addRate') }}
                        </Button>
                    </div>
                </form>

                <p v-if="manualRates.length === 0" class="text-sm text-text-tertiary">{{ t('exchangeRates.settings.noManualRates') }}</p>
                <ul v-else class="divide-y divide-border-subtle rounded-lg border border-border-subtle">
                    <li v-for="rate in manualRates" :key="rate.id" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                        <div>
                            <p class="tabular-nums text-text-primary">1 {{ rate.currency }} = {{ rate.rate }} {{ rate.quote_currency }}</p>
                            <p class="text-xs text-text-tertiary">
                                {{ displayCalendarDate(rate.effective_date) }}<template v-if="rate.entered_by"> &middot; {{ t('exchangeRates.enteredBy', { name: rate.entered_by }) }}</template>
                            </p>
                        </div>
                        <button type="button" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-danger ds-focus-ring" :aria-label="t('common.delete')" @click="deleteRate(rate)">
                            <Trash2 :size="16" />
                        </button>
                    </li>
                </ul>
            </div>
        </Card>
    </AppLayout>
</template>
