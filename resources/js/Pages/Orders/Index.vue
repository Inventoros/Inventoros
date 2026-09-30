<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import DataTable from '@/Components/ui/DataTable.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { displayCalendarDate, todayIsoDate } from '@/lib/dates';
import { formatMoney } from '@/lib/money';
import { orderSourceLabel, orderStatusLabel, orderStatusVariant } from '@/lib/orderLabels';
import { Plus, Search, Eye, Pencil, Trash2, ShoppingCart, CheckCheck, X } from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';

const { hasPermission, canVisit } = usePermissions();

const { t, te } = useI18n();
const statusText = (status) => orderStatusLabel(status, { t, te });
const sourceText = (source) => orderSourceLabel(source, { t, te });

const props = defineProps({
    orders: Object,
    filters: Object,
    statuses: Array,
    sources: Array,
    canViewPayments: Boolean,
    paymentStatuses: { type: Array, default: () => [] },
    canRecordPayments: Boolean,
    untrackedOrderCount: { type: Number, default: 0 },
    // The day after the latest untracked order, so the default date covers them all.
    markPaidBefore: { type: String, default: null },
    pluginComponents: Object,
});

// Orders placed before payment tracking can be marked paid in bulk.
const showMarkPaidModal = ref(false);
const markPaidForm = useForm({ before: props.markPaidBefore || todayIsoDate() });
const submitMarkPaid = () => {
    markPaidForm.post(route('orders.payments.mark-pre-tracking-paid'), {
        preserveScroll: true,
        onSuccess: () => { showMarkPaidModal.value = false; },
    });
};

const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');
const source = ref(props.filters?.source || '');
const paymentStatus = ref(props.filters?.payment_status || '');

const formatCurrency = (value, currency) =>
    formatMoney(value, currency);

const searchOrders = () => {
    router.get(route('orders.index'), {
        search: search.value,
        status: status.value,
        source: source.value,
        ...(props.canViewPayments ? { payment_status: paymentStatus.value } : {}),
    }, { preserveState: true, preserveScroll: true });
};

const clearFilters = () => {
    search.value = '';
    status.value = '';
    source.value = '';
    paymentStatus.value = '';
    searchOrders();
};

const deleteOrder = (order) => {
    if (confirm(t('orders.show.confirmDelete', { number: order.order_number }))) {
        router.delete(route('orders.destroy', order.id));
    }
};

const statusVariant = orderStatusVariant;

const paymentStatusVariant = (s) =>
    ({ unpaid: 'warning', partial: 'info', paid: 'success', overpaid: 'brand', refunded: 'neutral' }[s] || 'neutral');

const columns = [
    { key: 'order_number', label: t('orders.orderCol') },
    { key: 'customer_name', label: t('orders.customer') },
    { key: 'items', label: t('orders.itemsCol'), align: 'right' },
    { key: 'total', label: t('common.total'), align: 'right' },
    { key: 'status', label: t('common.status') },
    // Only for users who may see payments; the rows carry no payment data otherwise.
    ...(props.canViewPayments ? [{ key: 'payment_status', label: t('payments.paymentStatus') }] : []),
    { key: 'source', label: t('orders.source') },
    { key: 'order_date', label: t('common.date') },
    { key: 'actions', label: t('common.actions'), align: 'right' },
];

const selectClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
</script>

<template>
    <Head :title="t('orders.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('orders.title') }}</span>
            </div>
        </template>

        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <PageHeader :title="t('orders.title')" :description="t('orders.index.description')">
            <template #actions>
                <Button
                    v-if="canRecordPayments && untrackedOrderCount > 0"
                    variant="secondary"
                    size="sm"
                    @click="showMarkPaidModal = true"
                >
                    <CheckCheck :size="14" />
                    {{ t('payments.markPreTracking.button') }}
                </Button>
                <Button v-if="canVisit('orders.create')" variant="default" size="sm" as="Link" :href="route('orders.create')">
                    <Plus :size="14" />
                    {{ t('orders.createOrder') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Filters -->
        <Card class="mt-6">
            <form @submit.prevent="searchOrders" class="space-y-4">
                <div :class="['grid grid-cols-1 gap-4 sm:grid-cols-2', canViewPayments ? 'lg:grid-cols-3 xl:grid-cols-5' : 'lg:grid-cols-4']">
                    <div class="sm:col-span-2">
                        <label for="search" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('orders.searchOrders') }}</label>
                        <div class="relative">
                            <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                            <input
                                id="search"
                                v-model="search"
                                type="text"
                                :placeholder="t('orders.searchPlaceholder')"
                                class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            />
                        </div>
                    </div>
                    <div>
                        <label for="status" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('common.status') }}</label>
                        <select id="status" v-model="status" :class="selectClass">
                            <option value="">{{ t('common.allStatuses') }}</option>
                            <option v-for="stat in statuses" :key="stat" :value="stat">{{ statusText(stat) }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="source" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('orders.source') }}</label>
                        <select id="source" v-model="source" :class="selectClass">
                            <option value="">{{ t('orders.allSources') }}</option>
                            <option v-for="src in sources" :key="src" :value="src">{{ sourceText(src) }}</option>
                        </select>
                    </div>
                    <div v-if="canViewPayments">
                        <label for="payment_status" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('payments.paymentStatus') }}</label>
                        <select id="payment_status" v-model="paymentStatus" :class="selectClass">
                            <option value="">{{ t('payments.allPaymentStatuses') }}</option>
                            <option v-for="ps in paymentStatuses" :key="ps" :value="ps">{{ t(`payments.status.${ps}`) }}</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="submit" variant="default" size="sm">
                        <Search :size="14" />
                        {{ t('common.search') }}
                    </Button>
                    <Button type="button" variant="secondary" size="sm" @click="clearFilters">{{ t('common.clearFilters') }}</Button>
                </div>
            </form>
        </Card>

        <PluginSlot slot="before-table" :components="pluginComponents?.beforeTable" />

        <!-- Orders table -->
        <div class="mt-4">
            <DataTable :columns="columns" :rows="orders.data" dense>
                <template #cell-order_number="{ row }">
                    <Link :href="route('orders.show', row.id)" class="font-mono text-xs font-medium text-text-primary hover:text-brand">{{ row.order_number }}</Link>
                </template>
                <template #cell-customer_name="{ row }">
                    <div class="flex flex-col">
                        <span class="text-text-primary">{{ row.customer_name }}</span>
                        <span v-if="row.customer_email" class="text-xs text-text-tertiary">{{ row.customer_email }}</span>
                    </div>
                </template>
                <template #cell-items="{ row }">
                    <span class="tabular-nums text-text-secondary">{{ row.items.length }}</span>
                </template>
                <template #cell-total="{ row }">
                    <span class="font-medium tabular-nums text-text-primary">{{ formatCurrency(row.total, row.currency) }}</span>
                </template>
                <template #cell-status="{ row }">
                    <Badge :variant="statusVariant(row.status)" size="sm" dot>{{ statusText(row.status) }}</Badge>
                </template>
                <template #cell-payment_status="{ row }">
                    <Badge v-if="row.payment_status" :variant="paymentStatusVariant(row.payment_status)" size="sm" dot>{{ t(`payments.status.${row.payment_status}`) }}</Badge>
                </template>
                <template #cell-source="{ row }">
                    <Badge variant="neutral" size="sm">{{ sourceText(row.source) }}</Badge>
                </template>
                <template #cell-order_date="{ row }">
                    <span class="text-text-secondary">{{ displayCalendarDate(row.order_date) }}</span>
                </template>
                <template #cell-actions="{ row }">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="route('orders.show', row.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" :aria-label="t('common.view')"><Eye :size="16" /></Link>
                        <Link v-if="canVisit('orders.edit')" :href="route('orders.edit', row.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-success" :aria-label="t('common.edit')"><Pencil :size="16" /></Link>
                        <button v-if="hasPermission('delete_orders')" type="button" @click="deleteOrder(row)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-danger" :aria-label="t('common.delete')"><Trash2 :size="16" /></button>
                    </div>
                </template>
                <template #empty>
                    <div class="flex flex-col items-center gap-3 py-10">
                        <ShoppingCart :size="22" class="text-text-tertiary" />
                        <p class="text-sm text-text-tertiary">{{ t('orders.noOrdersFound') }}</p>
                        <Button v-if="canVisit('orders.create')" variant="default" size="sm" as="Link" :href="route('orders.create')">
                            <Plus :size="14" />
                            {{ t('orders.createFirstOrder') }}
                        </Button>
                    </div>
                </template>
            </DataTable>

            <!-- Pagination -->
            <div v-if="orders.data.length > 0" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
                <p class="text-xs text-text-tertiary">
                    {{ t('common.showing') }} <span class="font-medium text-text-secondary">{{ orders.from }}</span>
                    {{ t('common.to') }} <span class="font-medium text-text-secondary">{{ orders.to }}</span>
                    {{ t('common.of') }} <span class="font-medium text-text-secondary">{{ orders.total }}</span> {{ t('common.results') }}
                </p>
                <nav class="inline-flex items-center gap-1">
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
        </div>

        <PluginSlot slot="footer" :components="pluginComponents?.footer" />

        <!-- Mark pre-tracking orders paid -->
        <Teleport to="body">
            <div v-if="showMarkPaidModal" class="fixed inset-0 z-50 flex items-center justify-center" @click="showMarkPaidModal = false">
                <div class="fixed inset-0 bg-black/50"></div>

                <form class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg" @click.stop @submit.prevent="submitMarkPaid">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">{{ t('payments.markPreTracking.title') }}</h3>
                        <button type="button" class="text-text-tertiary transition-colors hover:text-text-primary ds-focus-ring" @click="showMarkPaidModal = false">
                            <X :size="18" />
                        </button>
                    </div>
                    <p class="mb-4 text-sm text-text-secondary">{{ t('payments.markPreTracking.description', untrackedOrderCount) }}</p>
                    <label for="mark_paid_before" class="mb-1 block text-sm font-medium text-text-secondary">{{ t('payments.markPreTracking.before') }}</label>
                    <input
                        id="mark_paid_before"
                        v-model="markPaidForm.before"
                        type="date"
                        required
                        class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring"
                    />
                    <p v-if="markPaidForm.errors.before" class="mt-1 text-xs text-status-danger">{{ markPaidForm.errors.before }}</p>

                    <div class="mt-6 flex justify-end gap-3">
                        <Button type="button" variant="secondary" :disabled="markPaidForm.processing" @click="showMarkPaidModal = false">{{ t('common.cancel') }}</Button>
                        <Button type="submit" variant="default" :loading="markPaidForm.processing" :disabled="markPaidForm.processing">{{ t('payments.markPreTracking.submit') }}</Button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
