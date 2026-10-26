<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import { displayDateTime } from '@/lib/dates';

defineProps({ audit: Object, item: Object, counts: Object });
const { t } = useI18n();
const heading = 'whitespace-nowrap px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-tertiary';
</script>

<template>
    <Head :title="t('stockAudits.history.title')" />
    <AppLayout>
        <PageHeader :title="t('stockAudits.history.title')" :description="`${audit.audit_number}: ${item.product?.name || '-'} ${item.variant?.title || ''}`">
            <template #actions><Link :href="route('stock-audits.show', audit.id)" class="text-sm text-brand hover:underline">{{ t('common.back') }}</Link></template>
        </PageHeader>
        <p class="mt-4 text-sm text-text-secondary">{{ t('stockAudits.history.hint') }}</p>
        <Card :padded="false" class="mt-5 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-border-subtle bg-surface-overlay"><tr>
                        <th :class="heading">#</th><th :class="heading">{{ t('common.date') }}</th>
                        <th :class="heading">{{ t('stockAudits.columns.countedBy') }}</th>
                        <th :class="heading">{{ t('stockAudits.columns.systemQty') }}</th>
                        <th :class="heading">{{ t('stockAudits.columns.countedQty') }}</th>
                        <th :class="heading">{{ t('stockAudits.columns.discrepancy') }}</th>
                        <th :class="heading">{{ t('common.notes') }}</th>
                    </tr></thead>
                    <tbody><tr v-for="count in counts.data" :key="count.id" class="border-b border-border-subtle last:border-0">
                        <td class="px-5 py-4 text-text-secondary">{{ count.is_snapshot ? t('stockAudits.history.snapshot') : count.sequence }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-text-secondary">{{ displayDateTime(count.counted_at) }}</td>
                        <td class="px-5 py-4 text-text-primary">{{ count.actor_name || '-' }}</td>
                        <td class="px-5 py-4 tabular-nums text-text-secondary">{{ count.system_quantity }}</td>
                        <td class="px-5 py-4 tabular-nums text-text-primary">{{ count.counted_quantity }}</td>
                        <td class="px-5 py-4 tabular-nums text-text-secondary">{{ count.discrepancy }}</td>
                        <td class="min-w-48 max-w-md whitespace-pre-wrap break-words px-5 py-4 text-text-secondary">{{ count.notes || '-' }}</td>
                    </tr>
                    <tr v-if="!counts.data.length"><td colspan="7" class="px-5 py-10 text-center text-text-tertiary">{{ t('stockAudits.history.empty') }}</td></tr></tbody>
                </table>
            </div>
            <nav v-if="counts.last_page > 1" :aria-label="t('stockAudits.history.title')" class="flex justify-between border-t border-border-subtle p-4 text-sm">
                <Link v-if="counts.prev_page_url" :href="counts.prev_page_url" class="text-brand hover:underline">{{ t('common.previous') }}</Link><span v-else />
                <span class="tabular-nums text-text-secondary">{{ counts.current_page }} / {{ counts.last_page }}</span>
                <Link v-if="counts.next_page_url" :href="counts.next_page_url" class="text-brand hover:underline">{{ t('common.next') }}</Link><span v-else />
            </nav>
        </Card>
    </AppLayout>
</template>
