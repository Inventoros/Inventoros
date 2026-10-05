<script setup>
import PluginSlot from '@/Components/PluginSlot.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Search, Eye, ArrowLeftRight } from 'lucide-vue-next';
import { displayDate } from '@/lib/dates';

const { t } = useI18n();

const props = defineProps({
    pluginComponents: Object,
    transfers: Object,
    filters: Object,
});

const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || '');

const applyFilters = () => {
    router.get(route('stock-transfers.index'), {
        search: search.value,
        status: selectedStatus.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    selectedStatus.value = '';
    applyFilters();
};

const statusVariant = (status) =>
    ({ pending: 'warning', in_transit: 'info', completed: 'success', cancelled: 'danger' }[status] || 'neutral');

const getStatusLabel = (status) => {
    const labels = {
        'pending': t('stockTransfers.statuses.pending'),
        'in_transit': t('stockTransfers.statuses.in_transit'),
        'completed': t('stockTransfers.statuses.completed'),
        'cancelled': t('stockTransfers.statuses.cancelled'),
    };
    return labels[status] || status;
};

const formatDate = (dateStr) => displayDate(dateStr);

const selectClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
const thClass =
    'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
</script>

<template>
    <Head :title="t('stockTransfers.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('nav.stockTransfers') }}</span>
            </div>
        </template>

        <PageHeader :title="t('stockTransfers.title')" :description="t('stockTransfers.subtitle')">
            <template #actions>
                <Button variant="default" size="sm" as="Link" :href="route('stock-transfers.create')">
                    <Plus :size="14" />
                    {{ t('stockTransfers.newTransfer') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <!-- Filters -->
        <Card class="mt-6">
            <form @submit.prevent="applyFilters" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="sm:col-span-2">
                        <label for="search" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('common.search') }}</label>
                        <div class="relative">
                            <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                            <input
                                id="search"
                                v-model="search"
                                type="text"
                                :placeholder="t('stockTransfers.index.searchPlaceholder')"
                                class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            />
                        </div>
                    </div>
                    <div>
                        <label for="status" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('common.status') }}</label>
                        <select id="status" v-model="selectedStatus" :class="selectClass" @change="applyFilters">
                            <option value="">{{ t('common.allStatuses') }}</option>
                            <option value="pending">{{ t('stockTransfers.statuses.pending') }}</option>
                            <option value="in_transit">{{ t('stockTransfers.statuses.in_transit') }}</option>
                            <option value="completed">{{ t('stockTransfers.statuses.completed') }}</option>
                            <option value="cancelled">{{ t('stockTransfers.statuses.cancelled') }}</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="submit" variant="default" size="sm">
                        <Search :size="14" />
                        {{ t('stockTransfers.index.filter') }}
                    </Button>
                    <Button type="button" variant="secondary" size="sm" @click="clearFilters">{{ t('common.clear') }}</Button>
                </div>
            </form>
        </Card>

        <!-- Transfers table -->
        <div class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">{{ t('stockTransfers.index.transferNumber') }}</th>
                        <th :class="thClass">{{ t('stockTransfers.index.from') }}</th>
                        <th :class="thClass">{{ t('stockTransfers.index.to') }}</th>
                        <th :class="thClass">{{ t('common.status') }}</th>
                        <th :class="thClass">{{ t('stockTransfers.index.items') }}</th>
                        <th :class="thClass">{{ t('common.date') }}</th>
                        <th :class="[thClass, 'text-right']">{{ t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!transfers.data?.length">
                        <td colspan="7" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <ArrowLeftRight :size="22" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">{{ t('stockTransfers.index.empty') }}</p>
                                <Button variant="default" size="sm" as="Link" :href="route('stock-transfers.create')">
                                    <Plus :size="14" />
                                    {{ t('stockTransfers.newTransfer') }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-for="transfer in transfers.data" :key="transfer.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                        <td class="px-4 py-3">
                            <Link :href="route('stock-transfers.show', transfer.id)" class="font-medium text-text-primary hover:text-brand">
                                {{ transfer.transfer_number }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-text-secondary">{{ transfer.from_location?.name || '-' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-text-secondary">{{ transfer.to_location?.name || '-' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="statusVariant(transfer.status)" size="sm" dot>{{ getStatusLabel(transfer.status) }}</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <span class="tabular-nums text-text-secondary">{{ t('stockTransfers.index.itemCount', { count: transfer.items?.length || 0 }, transfer.items?.length || 0) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-text-secondary">{{ formatDate(transfer.created_at) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Link :href="route('stock-transfers.show', transfer.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" :title="t('common.view')"><Eye :size="16" /></Link>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="transfers.links && transfers.links.length > 3" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-xs text-text-tertiary">
                {{ t('stockTransfers.index.showingTransfers', { from: transfers.from, to: transfers.to, total: transfers.total }) }}
            </p>
            <nav class="inline-flex items-center gap-1">
                <template v-for="link in transfers.links" :key="link.label">
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

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
    </AppLayout>
</template>
