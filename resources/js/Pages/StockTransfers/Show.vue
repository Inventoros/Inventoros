<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, defineAsyncComponent, ref } from 'vue';
import { useBarcodeWedge, useBarcodeLookup } from '@/composables/useBarcodeWedge';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, CheckCircle2, XCircle, ScanLine, RotateCcw } from 'lucide-vue-next';

const BarcodeScannerModal = defineAsyncComponent(() => import('@/Components/BarcodeScannerModal.vue'));

const { t } = useI18n();

const props = defineProps({
    transfer: Object,
});

const processing = ref(false);

const statusVariant = (status) =>
    ({
        pending: 'warning',
        in_transit: 'info',
        completed: 'success',
        cancelled: 'danger',
    }[status] || 'neutral');

const getStatusLabel = (status) => {
    const labels = {
        'pending': 'Pending',
        'in_transit': 'In Transit',
        'completed': 'Completed',
        'cancelled': 'Cancelled',
    };
    return labels[status] || status;
};

const formatDate = (dateStr) => {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const canComplete = ['pending', 'in_transit'].includes(props.transfer.status);
const canCancel = ['pending', 'in_transit'].includes(props.transfer.status);

const completeTransfer = () => {
    if (!confirm('Are you sure you want to complete this transfer? Stock levels will be adjusted.')) return;
    processing.value = true;
    router.post(route('stock-transfers.complete', props.transfer.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};

const cancelTransfer = () => {
    if (!confirm('Are you sure you want to cancel this transfer?')) return;
    processing.value = true;
    router.post(route('stock-transfers.cancel', props.transfer.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};

// --- Scan to verify ---------------------------------------------------
// A frontend-only checklist: scanning a product counts one unit against its
// line so the person receiving can confirm the shipment before completing.
// Nothing is saved; completing the transfer works exactly as before.
const verifying = ref(false);
const showScanner = ref(false);
const scanned = ref({});
const scanMessage = ref('');
const scanMessageTone = ref('info');
const { lookup } = useBarcodeLookup();

const scannedFor = (item) => scanned.value[item.id] || 0;
const lineState = (item) => {
    const count = scannedFor(item);
    if (count === item.quantity) return 'match';
    if (count > item.quantity) return 'over';
    return 'short';
};
const allLinesMatch = computed(() =>
    (props.transfer.items || []).length > 0
    && props.transfer.items.every(item => scannedFor(item) === item.quantity)
);

const onVerifyProduct = (product) => {
    showScanner.value = false;
    const items = props.transfer.items || [];
    // Prefer a line that still needs units, so duplicate lines fill in order.
    const item = items.find(i => i.product_id === product.id && scannedFor(i) < i.quantity)
        || items.find(i => i.product_id === product.id);
    if (!item) {
        scanMessage.value = t('scanning.notOnTransfer', { name: product.name });
        scanMessageTone.value = 'warning';
        return;
    }
    scanned.value = { ...scanned.value, [item.id]: scannedFor(item) + 1 };
    const over = scannedFor(item) > item.quantity;
    scanMessage.value = over
        ? t('scanning.overScanned', { name: item.product?.name || product.name, scanned: scannedFor(item), expected: item.quantity })
        : t('scanning.verifiedOne', { name: item.product?.name || product.name, scanned: scannedFor(item), expected: item.quantity });
    scanMessageTone.value = over ? 'warning' : 'success';
};

const onVerifyCode = async (code) => {
    try {
        const found = await lookup(code);
        if (!found) {
            scanMessage.value = t('scanning.notFound', { code });
            scanMessageTone.value = 'danger';
            return;
        }
        onVerifyProduct(found.product);
    } catch (error) {
        scanMessage.value = t('scanning.lookupFailed');
        scanMessageTone.value = 'danger';
    }
};

const resetVerification = () => {
    scanned.value = {};
    scanMessage.value = '';
};

useBarcodeWedge(onVerifyCode, { enabled: () => verifying.value && canComplete && !showScanner.value });

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="`Transfer ${transfer.transfer_number}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('stock-transfers.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('stock-transfers.index')" class="text-text-tertiary hover:text-text-primary">Stock Transfers</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ transfer.transfer_number }}</span>
            </div>
        </template>

        <PageHeader :title="transfer.transfer_number" description="Stock transfer details">
            <template #actions>
                <Badge :variant="statusVariant(transfer.status)" size="md" dot>{{ getStatusLabel(transfer.status) }}</Badge>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-transfers.index')">
                    <ArrowLeft :size="14" />
                    Back to List
                </Button>
            </template>
        </PageHeader>

        <div class="mt-6 space-y-4">
            <!-- Transfer Info -->
            <Card :padded="false">
                <div class="flex items-center justify-between px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">Transfer Details</h3>
                    <Badge :variant="statusVariant(transfer.status)" size="md" dot>{{ getStatusLabel(transfer.status) }}</Badge>
                </div>
                <div class="p-5">
                    <dl class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <dt class="mb-1 text-xs text-text-tertiary">Transfer Number</dt>
                            <dd class="text-sm font-medium text-text-primary">{{ transfer.transfer_number }}</dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-xs text-text-tertiary">Transferred By</dt>
                            <dd class="text-sm font-medium text-text-primary">{{ transfer.transferred_by_user?.name || '-' }}</dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-xs text-text-tertiary">From Location</dt>
                            <dd class="text-sm font-medium text-text-primary">
                                {{ transfer.from_location?.name }}
                                <span v-if="transfer.from_location?.code" class="text-text-tertiary">({{ transfer.from_location.code }})</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-xs text-text-tertiary">To Location</dt>
                            <dd class="text-sm font-medium text-text-primary">
                                {{ transfer.to_location?.name }}
                                <span v-if="transfer.to_location?.code" class="text-text-tertiary">({{ transfer.to_location.code }})</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-xs text-text-tertiary">Created</dt>
                            <dd class="text-sm font-medium text-text-primary">{{ formatDate(transfer.created_at) }}</dd>
                        </div>
                        <div v-if="transfer.completed_at">
                            <dt class="mb-1 text-xs text-text-tertiary">Completed</dt>
                            <dd class="text-sm font-medium text-text-primary">{{ formatDate(transfer.completed_at) }}</dd>
                        </div>
                    </dl>

                    <div v-if="transfer.notes" class="mt-6 border-t border-border-subtle pt-6">
                        <p class="mb-1 text-xs text-text-tertiary">Notes</p>
                        <p class="text-sm text-text-primary">{{ transfer.notes }}</p>
                    </div>
                </div>
            </Card>

            <!-- Transfer Items -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Transfer Items</h3></div>
                <div class="p-5">
                    <div class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                        <table class="min-w-full">
                            <thead>
                                <tr class="border-b border-border-subtle">
                                    <th :class="thClass">Product</th>
                                    <th :class="thClass">SKU</th>
                                    <th :class="[thClass, 'text-right']">Quantity</th>
                                    <th :class="thClass">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in transfer.items" :key="item.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                                    <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-text-primary">
                                        {{ item.product?.name || '-' }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-text-tertiary">
                                        {{ item.product?.sku || '-' }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium tabular-nums text-text-primary">
                                        {{ item.quantity }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-text-tertiary">
                                        {{ item.notes || '-' }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-border-subtle">
                                    <td colspan="2" class="px-4 py-3 text-sm font-medium text-text-primary">Total</td>
                                    <td class="px-4 py-3 text-right text-sm font-bold tabular-nums text-text-primary">
                                        {{ transfer.items?.reduce((sum, item) => sum + item.quantity, 0) || 0 }}
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </Card>

            <!-- Scan to verify (frontend-only checklist) -->
            <Card v-if="canComplete" :padded="false">
                <div class="flex flex-wrap items-center justify-between gap-2 px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('scanning.verifyTitle') }}</h3>
                    <Badge v-if="verifying" :variant="allLinesMatch ? 'success' : 'warning'" size="md" dot>
                        {{ allLinesMatch ? t('scanning.allMatch') : t('scanning.notYetMatched') }}
                    </Badge>
                </div>
                <div class="p-5">
                    <p class="text-xs text-text-tertiary">{{ t('scanning.verifyHint') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Button
                            v-if="!verifying"
                            variant="secondary"
                            size="lg"
                            class="min-h-11"
                            @click="verifying = true"
                        >
                            <ScanLine :size="16" />
                            {{ t('scanning.startVerify') }}
                        </Button>
                        <template v-else>
                            <Button size="lg" class="min-h-11" @click="showScanner = true">
                                <ScanLine :size="16" />
                                {{ t('scanning.scan') }}
                            </Button>
                            <Button variant="secondary" size="lg" class="min-h-11" @click="resetVerification">
                                <RotateCcw :size="16" />
                                {{ t('scanning.reset') }}
                            </Button>
                        </template>
                    </div>

                    <p
                        v-if="scanMessage"
                        class="mt-3 text-sm font-medium"
                        :class="{
                            'text-status-success': scanMessageTone === 'success',
                            'text-status-warning': scanMessageTone === 'warning',
                            'text-status-danger': scanMessageTone === 'danger',
                        }"
                        role="status"
                        aria-live="polite"
                    >
                        {{ scanMessage }}
                    </p>

                    <ul v-if="verifying" class="mt-4 divide-y divide-border-subtle rounded-lg border border-border-subtle">
                        <li
                            v-for="item in transfer.items"
                            :key="`verify-${item.id}`"
                            class="flex min-h-11 flex-wrap items-center justify-between gap-2 px-4 py-2"
                            :class="{
                                'bg-status-success-soft': lineState(item) === 'match',
                                'bg-status-warning-soft': lineState(item) === 'over',
                            }"
                        >
                            <span class="min-w-0 text-sm font-medium text-text-primary">
                                {{ item.product?.name || '-' }}
                                <span class="block text-xs font-normal text-text-tertiary">{{ item.product?.sku || '' }}</span>
                            </span>
                            <span class="flex items-center gap-2 text-sm tabular-nums">
                                <span :class="lineState(item) === 'match' ? 'text-status-success' : lineState(item) === 'over' ? 'text-status-warning' : 'text-text-secondary'">
                                    {{ t('scanning.scannedOfExpected', { scanned: scannedFor(item), expected: item.quantity }) }}
                                </span>
                                <CheckCircle2 v-if="lineState(item) === 'match'" :size="16" class="text-status-success" />
                            </span>
                        </li>
                    </ul>
                </div>
            </Card>

            <!-- Actions -->
            <Card v-if="canComplete || canCancel" :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Actions</h3></div>
                <div class="p-5">
                    <div class="flex flex-wrap gap-3">
                        <Button
                            v-if="canComplete"
                            variant="default"
                            :loading="processing"
                            :disabled="processing"
                            @click="completeTransfer"
                        >
                            <CheckCircle2 :size="16" />
                            {{ processing ? 'Processing...' : 'Complete Transfer' }}
                        </Button>
                        <Button
                            v-if="canCancel"
                            variant="danger"
                            :loading="processing"
                            :disabled="processing"
                            @click="cancelTransfer"
                        >
                            <XCircle :size="16" />
                            {{ processing ? 'Processing...' : 'Cancel Transfer' }}
                        </Button>
                    </div>
                    <p v-if="canComplete" class="mt-3 text-xs text-text-tertiary">
                        Completing the transfer will deduct stock from the source location and add it to the destination location.
                    </p>
                </div>
            </Card>
        </div>

        <!-- Barcode Scanner Modal (scan to verify) -->
        <BarcodeScannerModal
            :show="showScanner"
            @close="showScanner = false"
            @product-found="onVerifyProduct"
        />
    </AppLayout>
</template>
