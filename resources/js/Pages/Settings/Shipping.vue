<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Copy, RefreshCw } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    settings: Object,
    webhookUrl: String,
    warehouses: Array,
});

const addressFields = ['name', 'company', 'street1', 'street2', 'city', 'state', 'zip', 'country', 'phone', 'email'];

const form = useForm({
    easypost_enabled: !!props.settings.easypost_enabled,
    easypost_test_mode: !!props.settings.easypost_test_mode,
    easypost_api_key: '',
    easypost_test_api_key: '',
    easypost_webhook_secret: '',
    default_warehouse_id: props.settings.default_warehouse_id ?? '',
    from_address: Object.fromEntries(addressFields.map((f) => [f, props.settings.from_address?.[f] ?? ''])),
    default_parcel: {
        weight_oz: props.settings.default_parcel?.weight_oz ?? '',
        length_in: props.settings.default_parcel?.length_in ?? '',
        width_in: props.settings.default_parcel?.width_in ?? '',
        height_in: props.settings.default_parcel?.height_in ?? '',
    },
    notify_customers: !!props.settings.notify_customers,
});

const submit = () => {
    form
        .transform((data) => ({ ...data, default_warehouse_id: data.default_warehouse_id || null }))
        .patch(route('settings.shipping.update'), {
            preserveScroll: true,
            onSuccess: () => form.reset('easypost_api_key', 'easypost_test_api_key', 'easypost_webhook_secret'),
        });
};

const copied = ref(false);
const copyWebhookUrl = async () => {
    try {
        await navigator.clipboard.writeText(props.webhookUrl);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
};

const rotating = ref(false);
const rotateWebhookUrl = () => {
    rotating.value = true;
    router.post(route('settings.shipping.webhook-token'), {}, {
        preserveScroll: true,
        onFinish: () => (rotating.value = false),
    });
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
const fieldHelp = 'mt-1 text-xs text-text-tertiary';
</script>

<template>
    <Head :title="t('shipping.settings.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('settings.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('settings.index')" class="text-text-tertiary hover:text-text-primary">{{ t('settings.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('shipping.settings.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('shipping.settings.title')" :description="t('shipping.settings.description')" />

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <!-- EasyPost -->
            <Card>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('shipping.settings.easypost') }}</h3>
                        <p class="mt-1 text-sm text-text-secondary">{{ t('shipping.settings.easypostHelp') }}</p>
                    </div>
                    <Badge v-if="form.easypost_enabled && form.easypost_test_mode" variant="warning" size="sm" dot>
                        {{ t('shipping.settings.testMode') }}
                    </Badge>
                </div>

                <div class="mt-5 space-y-5">
                    <label class="flex items-center gap-2 text-sm text-text-primary">
                        <input v-model="form.easypost_enabled" type="checkbox" class="rounded border-border-strong text-brand ds-focus-ring" />
                        {{ t('shipping.settings.enable') }}
                    </label>

                    <div>
                        <label class="flex items-center gap-2 text-sm text-text-primary">
                            <input v-model="form.easypost_test_mode" type="checkbox" class="rounded border-border-strong text-brand ds-focus-ring" />
                            {{ t('shipping.settings.testMode') }}
                        </label>
                        <p :class="fieldHelp">{{ t('shipping.settings.testModeHelp') }}</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label :class="fieldLabel" for="easypost_test_api_key">{{ t('shipping.settings.testApiKey') }}</label>
                            <input
                                id="easypost_test_api_key"
                                v-model="form.easypost_test_api_key"
                                type="password"
                                autocomplete="new-password"
                                :class="fieldInput"
                                :placeholder="settings.easypost_test_api_key_set ? t('shipping.settings.secretSaved') : 'EZTK...'"
                            />
                            <p v-if="form.errors.easypost_test_api_key" :class="fieldError">{{ form.errors.easypost_test_api_key }}</p>
                        </div>
                        <div>
                            <label :class="fieldLabel" for="easypost_api_key">{{ t('shipping.settings.apiKey') }}</label>
                            <input
                                id="easypost_api_key"
                                v-model="form.easypost_api_key"
                                type="password"
                                autocomplete="new-password"
                                :class="fieldInput"
                                :placeholder="settings.easypost_api_key_set ? t('shipping.settings.secretSaved') : 'EZAK...'"
                            />
                            <p v-if="form.errors.easypost_api_key" :class="fieldError">{{ form.errors.easypost_api_key }}</p>
                        </div>
                    </div>

                    <div class="border-t border-border-subtle pt-5">
                        <label :class="fieldLabel">{{ t('shipping.settings.webhookUrl') }}</label>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <input :value="webhookUrl" readonly :class="[fieldInput, 'font-mono text-xs']" />
                            <div class="flex gap-2">
                                <Button variant="secondary" size="sm" type="button" @click="copyWebhookUrl">
                                    <Copy :size="14" />
                                    {{ copied ? t('shipping.settings.copied') : t('shipping.settings.copy') }}
                                </Button>
                                <Button variant="secondary" size="sm" type="button" :loading="rotating" @click="rotateWebhookUrl">
                                    <RefreshCw :size="14" />
                                    {{ t('shipping.settings.rotate') }}
                                </Button>
                            </div>
                        </div>
                        <p :class="fieldHelp">{{ t('shipping.settings.webhookUrlHelp') }}</p>
                    </div>

                    <div class="md:w-1/2">
                        <label :class="fieldLabel" for="easypost_webhook_secret">{{ t('shipping.settings.webhookSecret') }}</label>
                        <input
                            id="easypost_webhook_secret"
                            v-model="form.easypost_webhook_secret"
                            type="password"
                            autocomplete="new-password"
                            :class="fieldInput"
                            :placeholder="settings.easypost_webhook_secret_set ? t('shipping.settings.secretSaved') : ''"
                        />
                        <p v-if="form.errors.easypost_webhook_secret" :class="fieldError">{{ form.errors.easypost_webhook_secret }}</p>
                    </div>
                </div>
            </Card>

            <!-- Defaults -->
            <Card>
                <h3 class="text-sm font-semibold text-text-primary">{{ t('shipping.settings.defaults') }}</h3>

                <div class="mt-5 space-y-5">
                    <div class="md:w-1/2">
                        <label :class="fieldLabel" for="default_warehouse_id">{{ t('shipping.settings.defaultWarehouse') }}</label>
                        <select id="default_warehouse_id" v-model="form.default_warehouse_id" :class="fieldInput">
                            <option value="">{{ t('shipping.settings.none') }}</option>
                            <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                        </select>
                        <p v-if="form.errors.default_warehouse_id" :class="fieldError">{{ form.errors.default_warehouse_id }}</p>
                    </div>

                    <div>
                        <h4 class="text-sm font-medium text-text-primary">{{ t('shipping.settings.fromAddress') }}</h4>
                        <p :class="fieldHelp">{{ t('shipping.settings.fromAddressHelp') }}</p>
                        <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div v-for="field in addressFields" :key="field">
                                <label :class="fieldLabel" :for="`from_${field}`">{{ t(`shipping.address.${field}`) }}</label>
                                <input
                                    :id="`from_${field}`"
                                    v-model="form.from_address[field]"
                                    :type="field === 'email' ? 'email' : 'text'"
                                    :maxlength="field === 'country' ? 2 : 255"
                                    :class="fieldInput"
                                />
                                <p v-if="form.errors[`from_address.${field}`]" :class="fieldError">{{ form.errors[`from_address.${field}`] }}</p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-sm font-medium text-text-primary">{{ t('shipping.settings.defaultParcel') }}</h4>
                        <div class="mt-3 grid grid-cols-2 gap-4 md:grid-cols-4">
                            <div v-for="(label, field) in { weight_oz: 'weightOz', length_in: 'lengthIn', width_in: 'widthIn', height_in: 'heightIn' }" :key="field">
                                <label :class="fieldLabel" :for="`parcel_${field}`">{{ t(`shipping.${label}`) }}</label>
                                <input :id="`parcel_${field}`" v-model="form.default_parcel[field]" type="number" min="0" step="0.1" :class="fieldInput" />
                            </div>
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-text-primary">
                        <input v-model="form.notify_customers" type="checkbox" class="rounded border-border-strong text-brand ds-focus-ring" />
                        {{ t('shipping.settings.notifyCustomers') }}
                    </label>
                </div>
            </Card>

            <div class="flex justify-end">
                <Button type="submit" :loading="form.processing" :disabled="form.processing">
                    {{ t('shipping.settings.save') }}
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
