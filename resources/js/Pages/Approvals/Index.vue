<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ClipboardList, SlidersHorizontal, ArrowLeftRight, Inbox, ExternalLink } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    pending: { type: Array, default: () => [] },
    mine: { type: Array, default: () => [] },
});

const tab = ref(props.pending.length > 0 || props.mine.length === 0 ? 'pending' : 'mine');

const items = computed(() => (tab.value === 'pending' ? props.pending : props.mine));

const typeIcon = (type) => ({
    purchase_order: ClipboardList,
    stock_adjustment: SlidersHorizontal,
    stock_transfer: ArrowLeftRight,
}[type] || ClipboardList);

const statusVariant = (status) => ({
    pending: 'warning',
    approved: 'success',
    rejected: 'danger',
}[status] || 'neutral');

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '');

// One decision form at a time, keyed by type:id.
const open = ref(null);
const mode = ref(null);
const form = useForm({ notes: '' });

const keyOf = (item) => `${item.type}:${item.id}`;

const start = (item, which) => {
    open.value = keyOf(item);
    mode.value = which;
    form.reset();
    form.clearErrors();
};

const cancel = () => {
    open.value = null;
    mode.value = null;
    form.reset();
    form.clearErrors();
};

const decide = (item) => {
    const name = mode.value === 'approve' ? 'approvals.approve' : 'approvals.reject';
    form.post(route(name, { type: item.type, id: item.id }), {
        preserveScroll: true,
        onSuccess: cancel,
    });
};
</script>

<template>
    <Head :title="t('approvals.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('approvals.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('approvals.title')" :description="t('approvals.description')" />

        <div class="mt-6 flex gap-2 border-b border-border-subtle" role="tablist">
            <button
                v-for="key in ['pending', 'mine']"
                :key="key"
                type="button"
                role="tab"
                :aria-selected="tab === key"
                class="-mb-px inline-flex min-h-11 items-center gap-2 border-b-2 px-3 text-sm font-medium transition-colors ds-focus-ring"
                :class="tab === key ? 'border-brand text-brand' : 'border-transparent text-text-tertiary hover:text-text-secondary'"
                @click="tab = key"
            >
                {{ key === 'pending' ? t('approvals.tabs.pending') : t('approvals.tabs.mine') }}
                <Badge v-if="key === 'pending' && pending.length" variant="warning" size="sm">{{ pending.length }}</Badge>
            </button>
        </div>

        <div class="mt-4 space-y-3">
            <Card v-if="items.length === 0">
                <div class="flex flex-col items-center gap-2 py-10 text-center">
                    <Inbox :size="32" class="text-text-tertiary" />
                    <p class="text-sm font-medium text-text-primary">
                        {{ tab === 'pending' ? t('approvals.emptyPending') : t('approvals.emptyMine') }}
                    </p>
                    <p class="text-xs text-text-tertiary">
                        {{ tab === 'pending' ? t('approvals.emptyPendingHint') : t('approvals.emptyMineHint') }}
                    </p>
                </div>
            </Card>

            <Card v-for="item in items" :key="keyOf(item)">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex min-w-0 items-start gap-3">
                        <component :is="typeIcon(item.type)" :size="18" class="mt-0.5 shrink-0 text-text-tertiary" />
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-text-primary">{{ item.reference }}</span>
                                <Badge size="sm">{{ t(`approvals.types.${item.type}`) }}</Badge>
                                <Badge :variant="statusVariant(item.status)" size="sm" dot>{{ t(`approvals.status.${item.status}`) }}</Badge>
                            </div>
                            <p class="mt-1 break-words text-sm text-text-secondary">{{ item.summary }}</p>
                            <p class="mt-1 text-xs text-text-tertiary">
                                <span v-if="item.requester">{{ t('approvals.requestedBy', { name: item.requester }) }}</span>
                                <span v-if="item.requested_at"> · {{ formatDate(item.requested_at) }}</span>
                            </p>
                            <p v-if="item.status !== 'pending' && item.approver" class="mt-1 text-xs text-text-tertiary">
                                {{ t(`approvals.decidedBy.${item.status}`, { name: item.approver }) }}
                            </p>
                            <p v-if="item.notes && item.status !== 'pending'" class="mt-2 rounded-md bg-surface-sunken px-3 py-2 text-sm text-text-primary">
                                {{ item.notes }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2 sm:shrink-0">
                        <Button
                            v-if="item.type !== 'stock_adjustment'"
                            as="Link"
                            :href="item.url"
                            variant="secondary"
                            size="lg"
                            class="min-h-11"
                        >
                            <ExternalLink :size="16" />
                            {{ t('approvals.open') }}
                        </Button>
                        <template v-if="tab === 'pending' && item.can_decide && open !== keyOf(item)">
                            <Button size="lg" class="min-h-11" @click="start(item, 'approve')">{{ t('approvals.approve') }}</Button>
                            <Button size="lg" variant="danger" class="min-h-11" @click="start(item, 'reject')">{{ t('approvals.reject') }}</Button>
                        </template>
                    </div>
                </div>

                <form v-if="open === keyOf(item)" class="mt-4 border-t border-border-subtle pt-4" @submit.prevent="decide(item)">
                    <label :for="`notes-${keyOf(item)}`" class="mb-1 block text-sm font-medium text-text-secondary">
                        {{ mode === 'reject' ? t('approvals.rejectReason') : t('approvals.approveNote') }}
                    </label>
                    <textarea
                        :id="`notes-${keyOf(item)}`"
                        v-model="form.notes"
                        rows="3"
                        maxlength="1000"
                        :required="mode === 'reject'"
                        class="w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                    />
                    <p v-if="form.errors.notes" class="mt-1 text-xs text-status-danger">{{ form.errors.notes }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            size="lg"
                            class="min-h-11"
                            :variant="mode === 'reject' ? 'danger' : 'default'"
                            :loading="form.processing"
                            :disabled="form.processing"
                        >
                            {{ mode === 'reject' ? t('approvals.confirmReject') : t('approvals.confirmApprove') }}
                        </Button>
                        <Button size="lg" variant="secondary" class="min-h-11" @click="cancel">{{ t('common.cancel') }}</Button>
                    </div>
                </form>
            </Card>
        </div>
    </AppLayout>
</template>
