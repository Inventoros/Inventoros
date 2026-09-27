<script setup>
/**
 * Approval state and actions for one request (a purchase order or a stock
 * transfer). Shows where the request stands, lets an allowed approver
 * approve (optional note) or reject (reason required), and, for a PO that
 * needs approval, lets its owner submit it.
 *
 * Who may decide is decided server-side (ApprovalService); `canDecide`
 * only controls whether the buttons are offered.
 */
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ShieldCheck, ShieldAlert, ShieldQuestion, Send } from 'lucide-vue-next';
import Card from '@/Components/ui/Card.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';

const props = defineProps({
    type: { type: String, required: true },
    id: { type: Number, required: true },
    status: { type: String, default: null },
    needsApproval: { type: Boolean, default: false },
    requester: { type: String, default: null },
    approver: { type: String, default: null },
    approvedAt: { type: String, default: null },
    notes: { type: String, default: null },
    canDecide: { type: Boolean, default: false },
    canSubmit: { type: Boolean, default: false },
    submitUrl: { type: String, default: null },
});

const { t } = useI18n();

const mode = ref(null); // null | 'approve' | 'reject'
const decision = useForm({ notes: '' });
const submission = useForm({});

const variant = computed(() => ({
    pending: 'warning',
    approved: 'success',
    rejected: 'danger',
}[props.status] || 'neutral'));

const icon = computed(() => ({
    approved: ShieldCheck,
    rejected: ShieldAlert,
}[props.status] || ShieldQuestion));

const headline = computed(() => {
    if (props.status === 'pending') return t('approvals.panel.pending');
    if (props.status === 'approved') return t('approvals.panel.approved', { name: props.approver || '' });
    if (props.status === 'rejected') return t('approvals.panel.rejected', { name: props.approver || '' });
    return t('approvals.panel.needed');
});

const visible = computed(() => props.status !== null || props.needsApproval);

const decide = () => {
    const name = mode.value === 'approve' ? 'approvals.approve' : 'approvals.reject';
    decision.post(route(name, { type: props.type, id: props.id }), {
        preserveScroll: true,
        onSuccess: () => {
            mode.value = null;
            decision.reset();
        },
    });
};

const submit = () => {
    if (!props.submitUrl) return;
    submission.post(props.submitUrl, { preserveScroll: true });
};

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '');
</script>

<template>
    <Card v-if="visible">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <component :is="icon" :size="20" class="mt-0.5 shrink-0 text-text-tertiary" />
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('approvals.panel.title') }}</h3>
                        <Badge :variant="variant" dot>{{ t(`approvals.status.${status || 'required'}`) }}</Badge>
                    </div>
                    <p class="mt-1 text-sm text-text-secondary">{{ headline }}</p>
                    <p v-if="requester && status" class="mt-1 text-xs text-text-tertiary">
                        {{ t('approvals.requestedBy', { name: requester }) }}
                    </p>
                    <p v-if="approvedAt && status !== 'pending'" class="mt-1 text-xs text-text-tertiary">
                        {{ formatDate(approvedAt) }}
                    </p>
                    <p v-if="notes && status !== 'pending'" class="mt-2 rounded-md bg-surface-sunken px-3 py-2 text-sm text-text-primary">
                        {{ notes }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 sm:shrink-0">
                <Button
                    v-if="canSubmit && submitUrl"
                    size="lg"
                    class="min-h-11"
                    :loading="submission.processing"
                    :disabled="submission.processing"
                    @click="submit"
                >
                    <Send :size="16" />
                    {{ t('approvals.submit') }}
                </Button>
                <template v-if="canDecide && status === 'pending' && !mode">
                    <Button size="lg" class="min-h-11" @click="mode = 'approve'">{{ t('approvals.approve') }}</Button>
                    <Button size="lg" variant="danger" class="min-h-11" @click="mode = 'reject'">{{ t('approvals.reject') }}</Button>
                </template>
            </div>
        </div>

        <form v-if="mode" class="mt-4 border-t border-border-subtle pt-4" @submit.prevent="decide">
            <label :for="`approval-notes-${type}-${id}`" class="mb-1 block text-sm font-medium text-text-secondary">
                {{ mode === 'reject' ? t('approvals.rejectReason') : t('approvals.approveNote') }}
            </label>
            <textarea
                :id="`approval-notes-${type}-${id}`"
                v-model="decision.notes"
                rows="3"
                :required="mode === 'reject'"
                maxlength="1000"
                class="w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
            />
            <p v-if="decision.errors.notes" class="mt-1 text-xs text-status-danger">{{ decision.errors.notes }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <Button
                    type="submit"
                    size="lg"
                    class="min-h-11"
                    :variant="mode === 'reject' ? 'danger' : 'default'"
                    :loading="decision.processing"
                    :disabled="decision.processing"
                >
                    {{ mode === 'reject' ? t('approvals.confirmReject') : t('approvals.confirmApprove') }}
                </Button>
                <Button size="lg" variant="secondary" class="min-h-11" @click="mode = null; decision.reset(); decision.clearErrors()">
                    {{ t('common.cancel') }}
                </Button>
            </div>
        </form>
    </Card>
</template>
