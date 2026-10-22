<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';

const props = defineProps({ items: { type: Array, required: true }, modelValue: [Number, String] });
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();
const page = usePage();
const canTransfer = computed(() => page.props.auth?.permissions?.includes('transfer_stock'));
const plan = ref(null);
const loading = ref(false);
const error = ref('');
let revision = 0;
const signature = computed(() => JSON.stringify(props.items.map(({ product_id, product_variant_id, quantity }) => ({ product_id, product_variant_id, quantity }))));
watch(signature, () => { revision++; plan.value = null; loading.value = false; error.value = ''; });
onBeforeUnmount(() => { revision++; });

async function refresh() {
    const current = ++revision;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(route('orders.fulfilment-plan'), { items: JSON.parse(signature.value) });
        if (current === revision) plan.value = data;
    } catch {
        if (current === revision) error.value = t('orderPlanning.failed');
    } finally {
        if (current === revision) loading.value = false;
    }
}

function transferUrl(transfer) {
    return route('stock-transfers.create', {
        from_location_id: transfer.from_location_id, to_location_id: transfer.to_location_id,
        product_id: transfer.product_id, product_variant_id: transfer.product_variant_id,
        quantity: transfer.quantity,
    });
}
</script>

<template>
    <Card>
        <h3 class="text-sm font-semibold text-text-primary">{{ t('orderPlanning.title') }}</h3>
        <p class="mt-2 text-xs text-text-secondary">{{ t('orderPlanning.hint') }}</p>
        <Button type="button" class="mt-3" variant="secondary" :disabled="loading" @click="refresh">{{ loading ? t('common.loading') : t('orderPlanning.check') }}</Button>
        <p v-if="error" role="alert" class="mt-3 text-sm text-status-danger">{{ error }}</p>
        <div v-if="plan" class="mt-4 space-y-4" aria-live="polite">
            <p v-if="!plan.candidates.length" class="text-sm text-text-secondary">{{ t('orderPlanning.empty') }}</p>
            <section v-for="candidate in plan.candidates" :key="candidate.warehouse_id" class="rounded-md border border-border-subtle p-3">
                <h4 class="text-sm font-semibold text-text-primary">{{ candidate.warehouse_name }}</h4>
                <p class="mt-1 text-xs" :class="candidate.can_fulfil ? 'text-status-success' : 'text-text-secondary'">{{ candidate.can_fulfil ? t('orderPlanning.ready') : t('orderPlanning.shortfall', { count: candidate.missing_units }) }}</p>
                <Button v-if="candidate.can_fulfil" type="button" variant="secondary" class="mt-2" :disabled="Number(modelValue) === candidate.warehouse_id" @click="emit('update:modelValue', candidate.warehouse_id)">{{ t('orderPlanning.use') }}</Button>
                <ul v-else class="mt-2 space-y-1 text-xs text-text-secondary">
                    <li v-for="line in candidate.lines.filter(line => line.shortfall > 0)" :key="`${line.product_id}:${line.product_variant_id}`">
                        {{ line.sku }}: {{ t('orderPlanning.line', { available: line.available, requested: line.quantity, incoming: line.in_transit }) }}
                    </li>
                </ul>
                <ul v-if="canTransfer && candidate.transfers.length" class="mt-3 space-y-2 text-xs">
                    <li v-for="transfer in candidate.transfers" :key="`${transfer.product_id}:${transfer.product_variant_id}:${transfer.from_location_id}`">
                        <a :href="transferUrl(transfer)" target="_blank" rel="noopener" class="text-brand underline ds-focus-ring">{{ t('orderPlanning.transfer', { sku: transfer.sku, count: transfer.quantity, warehouse: transfer.from_warehouse_name }) }}</a>
                    </li>
                </ul>
            </section>
        </div>
    </Card>
</template>
