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
import { displayDate } from '@/lib/dates';
import { Plus, Search, Eye, Settings } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    pluginComponents: Object,
    workOrders: Object,
    filters: Object,
});

const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || '');

const applyFilters = () => {
    router.get(route('work-orders.index'), {
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
    ({
        draft: 'neutral',
        pending: 'warning',
        in_progress: 'info',
        completed: 'success',
        cancelled: 'danger',
    }[status] || 'neutral');

const getStatusLabel = (status) => {
    const labels = {
        'draft': t('workOrders.statuses.draft'),
        'pending': t('workOrders.statuses.pending'),
        'in_progress': t('workOrders.statuses.in_progress'),
        'completed': t('workOrders.statuses.completed'),
        'cancelled': t('workOrders.statuses.cancelled'),
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
    <Head :title="t('workOrders.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('nav.workOrders') }}</span>
            </div>
        </template>

        <PageHeader :title="t('workOrders.title')" :description="t('workOrders.subtitle')">
            <template #actions>
                <Button variant="default" size="sm" as="Link" :href="route('work-orders.create')">
                    <Plus :size="14" />
                    {{ t('workOrders.createWorkOrder') }}
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
                                :placeholder="t('workOrders.searchPlaceholder')"
                                class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            />
                        </div>
                    </div>
                    <div>
                        <label for="status" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('common.status') }}</label>
                        <select id="status" v-model="selectedStatus" :class="selectClass" @change="applyFilters">
                            <option value="">{{ t('common.allStatuses') }}</option>
                            <option value="draft">{{ t('workOrders.statuses.draft') }}</option>
                            <option value="pending">{{ t('workOrders.statuses.pending') }}</option>
                            <option value="in_progress">{{ t('workOrders.statuses.in_progress') }}</option>
                            <option value="completed">{{ t('workOrders.statuses.completed') }}</option>
                            <option value="cancelled">{{ t('workOrders.statuses.cancelled') }}</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="submit" variant="default" size="sm">
                        <Search :size="14" />
                        {{ t('workOrders.index.filter') }}
                    </Button>
                    <Button type="button" variant="secondary" size="sm" @click="clearFilters">{{ t('common.clear') }}</Button>
                </div>
            </form>
        </Card>

        <!-- Work orders table -->
        <div class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">{{ t('workOrders.columns.woNumber') }}</th>
                        <th :class="thClass">{{ t('workOrders.columns.assemblyProduct') }}</th>
                        <th :class="[thClass, 'text-center']">{{ t('workOrders.columns.quantity') }}</th>
                        <th :class="[thClass, 'text-center']">{{ t('workOrders.columns.produced') }}</th>
                        <th :class="thClass">{{ t('workOrders.columns.status') }}</th>
                        <th :class="thClass">{{ t('workOrders.columns.createdBy') }}</th>
                        <th :class="thClass">{{ t('workOrders.columns.date') }}</th>
                        <th :class="[thClass, 'text-right']">{{ t('workOrders.columns.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Empty State -->
                    <tr v-if="!workOrders.data || workOrders.data.length === 0">
                        <td colspan="8" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <Settings :size="22" class="text-text-tertiary" />
                                <p class="text-sm font-medium text-text-primary">{{ t('workOrders.noWorkOrders') }}</p>
                                <p class="text-sm text-text-tertiary">{{ t('workOrders.noWorkOrdersHint') }}</p>
                                <Button variant="default" size="sm" as="Link" :href="route('work-orders.create')">
                                    <Plus :size="14" />
                                    {{ t('workOrders.createWorkOrder') }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-for="wo in workOrders.data" :key="wo.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                        <td class="px-4 py-3">
                            <Link :href="route('work-orders.show', wo.id)" class="font-medium text-text-primary hover:text-brand">
                                {{ wo.wo_number }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-text-primary">{{ wo.product?.name || '-' }}</div>
                            <div class="text-xs text-text-tertiary">{{ wo.product?.sku || '' }}</div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="tabular-nums text-text-secondary">{{ wo.quantity }}</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span :class="['tabular-nums', wo.quantity_produced >= wo.quantity ? 'text-status-success' : 'text-text-secondary']">
                                {{ wo.quantity_produced || 0 }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="statusVariant(wo.status)" size="sm" dot>{{ getStatusLabel(wo.status) }}</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-text-secondary">{{ wo.created_by?.name || '-' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-text-secondary">{{ formatDate(wo.created_at) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Link :href="route('work-orders.show', wo.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" :title="t('workOrders.actions.view')"><Eye :size="16" /></Link>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="workOrders.links && workOrders.links.length > 3" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-xs text-text-tertiary">
                {{ t('common.showingResults', { from: workOrders.from, to: workOrders.to, total: workOrders.total }) }}
            </p>
            <nav class="inline-flex items-center gap-1">
                <template v-for="link in workOrders.links" :key="link.label">
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
                        preserve-scroll
                    />
                    <span v-else class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-2.5 text-xs text-text-tertiary opacity-50" v-html="link.label" />
                </template>
            </nav>
        </div>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
    </AppLayout>
</template>
