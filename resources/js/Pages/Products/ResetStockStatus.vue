<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { displayDateTime } from '@/lib/dates';

const props = defineProps({ run: Object });
const { t } = useI18n();
const rows = computed(() => [
    ['products', props.run.counts.products], ['variants', props.run.counts.product_variants],
    ['locations', props.run.counts.product_location_stocks + props.run.counts.variant_location_stocks],
    ['serials', props.run.counts.product_serials], ['batches', props.run.counts.product_batches],
]);
let timer;
onMounted(() => {
    timer = setInterval(() => {
        if (['pending', 'running'].includes(props.run.status)) router.reload({ only: ['run'] });
    }, 3000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head :title="t('stockReset.title')" />
    <AppLayout>
        <PageHeader :title="t('stockReset.title')" />
        <Card class="mt-6 max-w-2xl space-y-4">
            <p role="status" class="font-semibold text-text-primary">{{ t(`stockReset.${run.status}`) }}</p>
            <p class="text-sm text-text-secondary">{{ t(run.status === 'completed' ? 'stockReset.completedHint' : run.status === 'failed' ? 'stockReset.failedHint' : 'stockReset.queuedHint') }}</p>
            <p v-if="run.error_code && run.error_code !== 'stockReset.errors.failed'" role="alert" class="text-sm text-status-danger">{{ t(run.error_code) }}</p>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <template v-for="[label, count] in rows" :key="label">
                    <dt class="text-text-secondary">{{ t(`stockReset.${label}`) }}</dt><dd class="text-right font-semibold tabular-nums">{{ count }}</dd>
                </template>
            </dl>
            <dl class="space-y-2 text-sm">
                <div><dt class="text-text-tertiary">{{ t('stockReset.runId') }}</dt><dd class="break-all font-mono text-text-primary">{{ run.public_id }}</dd></div>
                <div><dt class="text-text-tertiary">{{ t('stockReset.reason') }}</dt><dd class="break-words text-text-primary">{{ run.reason }}</dd></div>
                <div v-if="run.finished_at"><dt class="text-text-tertiary">{{ t('common.date') }}</dt><dd class="text-text-primary">{{ displayDateTime(run.finished_at) }}</dd></div>
            </dl>
            <div class="flex flex-wrap gap-4">
                <Link :href="route('products.index')" class="text-sm text-brand hover:underline">{{ t('nav.inventory') }}</Link>
                <Link v-if="run.status === 'failed'" :href="route('products.stock-reset.preview')" class="text-sm text-brand hover:underline">{{ t('stockReset.refresh') }}</Link>
            </div>
        </Card>
    </AppLayout>
</template>
