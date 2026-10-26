<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';

const props = defineProps({ preview: Object, token: String, organizationName: String, pendingRun: String });
const { t, te } = useI18n();
const form = useForm({ token: props.token, confirmation: '', reason: '', password: '' });
watch(() => props.token, (token) => {
    form.token = token;
    form.confirmation = '';
    form.password = '';
});
const rows = computed(() => [
    ['products', props.preview.counts.products], ['variants', props.preview.counts.product_variants],
    ['locations', props.preview.counts.product_location_stocks + props.preview.counts.variant_location_stocks],
    ['serials', props.preview.counts.product_serials], ['batches', props.preview.counts.product_batches],
]);
const blockers = computed(() => [
    ['reservations', 'reservations.title', 'reservations.index'],
    ['transfers', 'stockTransfers.title', 'stock-transfers.index'],
    ['audits', 'nav.stockAudits', 'stock-audits.index'],
    ['production', 'workOrders.title', 'work-orders.index'],
    ['serials', 'stockReset.reservedSerials', null],
].filter(([key]) => props.preview.blockers[key] > 0));
const field = 'mt-1 block w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 ds-focus-ring';
const submit = () => form.post(route('products.stock-reset.store'), { onFinish: () => form.reset('password') });
</script>

<template>
    <Head :title="t('stockReset.title')" />
    <AppLayout>
        <PageHeader :title="t('stockReset.title')" :description="organizationName" />
        <Card class="mt-6 max-w-2xl">
            <p class="text-sm text-text-secondary">{{ t('stockReset.hint') }}</p>
            <dl class="my-5 grid grid-cols-2 gap-3 text-sm">
                <template v-for="[label, count] in rows" :key="label">
                    <dt class="text-text-secondary">{{ t(`stockReset.${label}`) }}</dt><dd class="text-right font-semibold tabular-nums">{{ count }}</dd>
                </template>
            </dl>
            <div v-if="preview.blocked" role="alert" class="space-y-3 text-sm text-status-danger">
                <p v-if="blockers.length">{{ t('stockReset.blocked') }}</p>
                <ul v-if="blockers.length" class="list-inside list-disc">
                    <li v-for="[key, label, destination] in blockers" :key="key">
                        <Link v-if="destination" :href="route(destination)" class="underline">{{ t(label) }}</Link>
                        <span v-else>{{ t(label) }}</span>: {{ preview.blockers[key] }}
                    </li>
                </ul>
                <p v-if="preview.blockers.integrity">{{ t('stockReset.integrityHint') }}</p>
                <p v-if="preview.blockers.limit">{{ t('stockReset.limitHint', { limit: preview.limit }) }}</p>
                <Link v-if="pendingRun" :href="route('products.stock-reset.show', pendingRun)" class="inline-block underline">{{ t('stockReset.viewRun') }}</Link>
            </div>
            <form v-else class="space-y-4" @submit.prevent="submit">
                <p v-for="(error, key) in form.errors" :key="key" role="alert" class="text-sm text-status-danger">{{ te(error) ? t(error) : error }}</p>
                <label class="block text-sm">{{ t('stockReset.reason') }}<textarea v-model="form.reason" rows="2" :class="field" required minlength="3" maxlength="500" /></label>
                <label class="block text-sm">{{ t('stockReset.password') }}<input v-model="form.password" type="password" autocomplete="current-password" :class="field" required /></label>
                <label class="block text-sm">{{ t('stockReset.confirm', { phrase: 'ZERO STOCK' }) }}<input v-model="form.confirmation" :class="field" required autocomplete="off" /></label>
                <div class="flex items-center gap-3">
                    <Button variant="danger" type="submit" :disabled="form.processing || form.confirmation !== 'ZERO STOCK'">{{ t('stockReset.title') }}</Button>
                </div>
            </form>
            <div class="mt-5 flex flex-wrap gap-4">
                <Link :href="route('products.stock-reset.preview')" class="text-sm text-brand hover:underline">{{ t('stockReset.refresh') }}</Link>
                <Link :href="route('products.index')" class="text-sm text-brand hover:underline">{{ t('common.cancel') }}</Link>
            </div>
        </Card>
    </AppLayout>
</template>
