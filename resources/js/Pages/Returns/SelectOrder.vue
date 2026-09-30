<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Search, RotateCcw, PackageOpen } from 'lucide-vue-next';
import { formatMoney } from '@/lib/money';
import { formatCalendarDate } from '@/lib/dates';
import { orderStatusLabel, orderStatusVariant } from '@/lib/orderLabels';

const i18n = useI18n();
const { t, locale } = i18n;

const props = defineProps({
    orders: Object,
    filters: Object,
});

const search = ref(props.filters?.search || '');

let searchTimeout = null;
watch(search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('returns.create'), { search: search.value || undefined }, { preserveState: true, replace: true });
    }, 300);
});

const thClass = 'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
</script>

<template>
    <Head :title="t('returnPicker.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('returns.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.returns') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('common.new') }}</span>
            </div>
        </template>

        <PageHeader :title="t('returnPicker.title')" :description="t('returnPicker.description')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('returns.index')">
                    <ArrowLeft :size="14" />
                    {{ t('returnPicker.back') }}
                </Button>
            </template>
        </PageHeader>

        <Card class="mt-6">
            <label for="order-search" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('returnPicker.search') }}</label>
            <div class="relative">
                <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                <input
                    id="order-search"
                    v-model="search"
                    type="search"
                    autofocus
                    :placeholder="t('returnPicker.searchPlaceholder')"
                    class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                />
            </div>
        </Card>

        <div class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">{{ t('returnPicker.order') }}</th>
                        <th :class="thClass">{{ t('returnPicker.customer') }}</th>
                        <th :class="[thClass, 'hidden sm:table-cell']">{{ t('returnPicker.date') }}</th>
                        <th :class="thClass">{{ t('returnPicker.status') }}</th>
                        <th :class="[thClass, 'hidden text-right md:table-cell']">{{ t('returnPicker.total') }}</th>
                        <th :class="[thClass, 'text-right']"><span class="sr-only">{{ t('returnPicker.choose') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!orders.data || orders.data.length === 0">
                        <td colspan="6" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <PackageOpen :size="22" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">{{ t('returnPicker.empty') }}</p>
                            </div>
                        </td>
                    </tr>
                    <tr v-for="order in orders.data" :key="order.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                        <td class="px-4 py-3">
                            <span class="font-medium text-text-primary">{{ order.order_number }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-text-primary">{{ order.customer_name || '-' }}</div>
                            <div v-if="order.customer_email" class="text-xs text-text-tertiary">{{ order.customer_email }}</div>
                        </td>
                        <td class="hidden px-4 py-3 text-text-secondary sm:table-cell">{{ formatCalendarDate(order.order_date, undefined, locale) }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="orderStatusVariant(order.status)" size="sm" dot>{{ orderStatusLabel(order.status, i18n) }}</Badge>
                        </td>
                        <td class="hidden px-4 py-3 text-right tabular-nums text-text-primary md:table-cell">{{ formatMoney(order.total, order.currency, locale) }}</td>
                        <td class="px-4 py-3 text-right">
                            <Button variant="default" size="sm" as="Link" :href="route('returns.create', { order_id: order.id })">
                                <RotateCcw :size="14" />
                                {{ t('returnPicker.choose') }}
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="orders.links && orders.links.length > 3" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-xs text-text-tertiary">{{ t('returnPicker.showing', { from: orders.from, to: orders.to, total: orders.total }) }}</p>
            <nav class="inline-flex flex-wrap items-center gap-1">
                <template v-for="link in orders.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-colors',
                            link.active
                                ? 'border-brand bg-brand text-brand-foreground'
                                : 'border-border-subtle bg-surface-canvas text-text-secondary hover:bg-surface-overlay',
                        ]"
                        v-html="link.label"
                    />
                    <span v-else class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-2.5 text-xs text-text-tertiary opacity-50" v-html="link.label" />
                </template>
            </nav>
        </div>
    </AppLayout>
</template>
