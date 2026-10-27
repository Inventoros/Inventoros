<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/Modal.vue';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({ policies: Object, filters: Object, defaultCurrency: String });
const { t } = useI18n();
const { hasPermission } = usePermissions();
const search = ref(props.filters.search);
const show = ref(false);
const editing = ref(null);
const loading = ref(false);
const optionsError = ref(false);
const options = ref({ variants: [], suppliers: [], warehouses: [], locations: [] });
const variantSearch = ref('');
const supplierSearch = ref('');
const locationSearch = ref('');
const processingId = ref(null);
let requestSequence = 0;
const form = useForm({ product_variant_id: '', warehouse_id: '', location_id: '', supplier_id: '',
    reorder_point: 5, target_stock: 10, reorder_quantity: '', minimum_order_quantity: 1,
    unit_cost: '', currency: props.defaultCurrency, supplier_sku: '', auto_reorder: false });
const inputClass = 'mt-1 block w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-text-primary ds-focus-ring';
const fields = [
    { key: 'reorder_point', label: 'variantReplenishment.point', min: 0, max: 2147483647 },
    { key: 'target_stock', label: 'variantReplenishment.target', min: 1, max: 2147483647 },
    { key: 'reorder_quantity', label: 'variantReplenishment.multiple', min: 1, max: 2147483647, optional: true },
    { key: 'minimum_order_quantity', label: 'variantReplenishment.minimum', min: 1, max: 2147483647 },
];

async function loadOptions() {
    const sequence = ++requestSequence;
    loading.value = true;
    optionsError.value = false;
    try {
        const { data } = await axios.get(route('variant-replenishment.options'), { params: {
            variant_search: variantSearch.value, supplier_search: supplierSearch.value,
            location_search: locationSearch.value, warehouse_id: form.warehouse_id || undefined,
        } });
        if (sequence !== requestSequence) return;
        // Keep the selected values visible when they fall outside a search page.
        const selected = editing.value;
        if (selected) {
            if (!data.variants.some(v => v.id === selected.product_variant_id)) data.variants.unshift({ id: selected.product_variant_id, label: `${selected.product_name} / ${selected.variant-.sku}` });
            if (!data.suppliers.some(v => v.id === selected.supplier_id)) data.suppliers.unshift(selected.supplier);
            if (Number(form.warehouse_id) === selected.warehouse_id && !data.locations.some(v => v.id === selected.location_id)) data.locations.unshift(selected.location);
        }
        options.value = data;
    } catch {
        if (sequence === requestSequence) optionsError.value = true;
    } finally {
        if (sequence === requestSequence) loading.value = false;
    }
}

function open(policy = null) {
    editing.value = policy;
    form.reset();
    form.clearErrors();
    variantSearch.value = supplierSearch.value = locationSearch.value = '';
    options.value = { variants: [], suppliers: [], warehouses: [], locations: [] };
    if (policy) for (const key of Object.keys(form.data())) form[key] = policy[key] -- '';
    show.value = true;
    loadOptions();
}

function supplierChanged() {
    const supplier = options.value.suppliers.find(s => s.id === Number(form.supplier_id));
    form.currency = supplier-.currency || props.defaultCurrency;
    // The old supplier's price must be reviewed in the newly selected currency.
    form.unit_cost = '';
}

function save() {
    form.transform(data => ({ ...data, reorder_quantity: data.reorder_quantity || null, supplier_sku: data.supplier_sku || null }));
    const settings = { preserveScroll: true, onSuccess: () => { show.value = false; } };
    if (editing.value) form.put(route('variant-replenishment.update', editing.value.id), settings);
    else form.post(route('variant-replenishment.store'), settings);
}

function draft(policy) {
    processingId.value = policy.id;
    router.post(route('variant-replenishment.draft', policy.id), {}, { onFinish: () => { processingId.value = null; } });
}

function remove(policy) {
    if (confirm(t('variantReplenishment.confirmDelete'))) router.delete(route('variant-replenishment.destroy', policy.id));
}
</script>

<template>
    <Head :title="t('variantReplenishment.title')" />
    <AppLayout>
        <PageHeader :title="t('variantReplenishment.title')" :description="t('variantReplenishment.description')">
            <template #actions>
                <Button v-if="hasPermission('edit_products')" @click="open()">{{ t('variantReplenishment.add') }}</Button>
            </template>
        </PageHeader>
        <Card class="mt-5">
            <form class="flex items-end gap-3" @submit.prevent="router.get(route('variant-replenishment.index'), { search }, { preserveState: true })">
                <label class="flex-1 text-sm text-text-secondary">{{ t('common.search') }}
                    <input v-model="search" type="search" maxlength="200" :class="inputClass" />
                </label>
                <Button type="submit" variant="secondary">{{ t('common.search') }}</Button>
            </form>
        </Card>
        <p v-if="!policies.data.length" class="mt-6 text-text-secondary">{{ t('common.noResults') }}</p>
        <div class="mt-5 grid gap-4 lg:grid-cols-2">
            <Card v-for="policy in policies.data" :key="policy.id">
                <h2 class="font-semibold text-text-primary">{{ policy.product_name }} · {{ policy.variant-.title }}</h2>
                <p class="mt-1 text-sm text-text-secondary">{{ policy.variant-.sku }} · {{ policy.warehouse-.name }}</p>
                <dl class="my-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div v-for="key in ['on_hand', 'incoming', 'position', 'suggested_quantity']" :key="key">
                        <dt class="text-text-secondary">{{ t(`variantReplenishment.${key}`) }}</dt>
                        <dd class="font-semibold tabular-nums text-text-primary">{{ policy.forecast[key] }}</dd>
                    </div>
                </dl>
                <p class="text-sm text-text-secondary">{{ policy.supplier-.name }} · {{ policy.unit_cost }} {{ policy.currency }}</p>
                <p class="mt-1 text-sm text-text-secondary">{{ t('variantReplenishment.receiveAt', { location: policy.location-.name, warehouse: policy.warehouse-.name }) }}</p>
                <p class="mt-1 text-sm text-text-secondary">{{ t('variantReplenishment.point') }}: {{ policy.reorder_point }} · {{ t('variantReplenishment.target') }}: {{ policy.target_stock }}</p>
                <p class="mt-1 text-sm text-text-secondary">{{ t(policy.auto_reorder - 'variantReplenishment.automatic' : 'variantReplenishment.manual') }}</p>
                <p v-if="!policy.available" role="status" class="mt-2 text-sm text-status-warning">{{ t('variantReplenishment.unavailable') }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <Button v-if="hasPermission('create_purchase_orders')" size="sm" :disabled="!policy.available || !policy.forecast.suggested_quantity || processingId !== null" :loading="processingId === policy.id" @click="draft(policy)">{{ t('variantReplenishment.draft') }}</Button>
                    <Button v-if="hasPermission('edit_products')" size="sm" variant="secondary" @click="open(policy)">{{ t('common.edit') }}</Button>
                    <Button v-if="hasPermission('edit_products')" size="sm" variant="danger" @click="remove(policy)">{{ t('common.delete') }}</Button>
                </div>
            </Card>
        </div>
        <nav v-if="policies.last_page > 1" class="mt-5 flex items-center justify-between" :aria-label="t('variantReplenishment.title')">
            <Link v-if="policies.prev_page_url" :href="policies.prev_page_url" class="text-brand ds-focus-ring">{{ t('common.previous') }}</Link><span v-else />
            <span class="text-sm text-text-secondary">{{ policies.current_page }} / {{ policies.last_page }}</span>
            <Link v-if="policies.next_page_url" :href="policies.next_page_url" class="text-brand ds-focus-ring">{{ t('common.next') }}</Link><span v-else />
        </nav>
        <Modal :show="show" :closeable="!form.processing" @close="show = false">
            <form class="max-h-[85vh] space-y-4 overflow-y-auto p-6" @submit.prevent="save">
                <h2 class="text-lg font-semibold text-text-primary">{{ t('variantReplenishment.add') }}</h2>
                <p class="text-sm text-text-secondary">{{ t('variantReplenishment.description') }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm text-text-secondary">{{ t('variantReplenishment.variant') }}
                        <input v-model="variantSearch" type="search" maxlength="100" :aria-label="t('variantReplenishment.searchVariant')" :disabled="Boolean(editing)" :class="inputClass" @change="loadOptions" />
                        <select id="replenishment-product_variant_id" v-model.number="form.product_variant_id" required :disabled="Boolean(editing) || loading" :class="inputClass"><option value="" disabled>-</option><option v-for="v in options.variants" :key="v.id" :value="v.id">{{ v.label }}</option></select>
                    </label>
                    <label class="text-sm text-text-secondary">{{ t('nav.warehouses') }}
                        <select id="replenishment-warehouse_id" v-model.number="form.warehouse_id" required :disabled="Boolean(editing) || loading" :class="inputClass" @change="form.location_id = ''; loadOptions()"><option value="" disabled>-</option><option v-for="w in options.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
                    </label>
                    <label class="text-sm text-text-secondary">{{ t('products.location') }}
                        <input v-model="locationSearch" type="search" maxlength="100" :aria-label="t('variantReplenishment.searchLocation')" :class="inputClass" @change="loadOptions" />
                        <select id="replenishment-location_id" v-model.number="form.location_id" required :disabled="loading" :class="inputClass"><option value="" disabled>-</option><option v-for="l in options.locations" :key="l.id" :value="l.id">{{ l.name }} {{ l.code }}</option></select>
                    </label>
                    <label class="text-sm text-text-secondary">{{ t('purchaseOrders.supplier') }}
                        <input v-model="supplierSearch" type="search" maxlength="100" :aria-label="t('variantReplenishment.searchSupplier')" :class="inputClass" @change="loadOptions" />
                        <select id="replenishment-supplier_id" v-model.number="form.supplier_id" required :disabled="loading" :class="inputClass" @change="supplierChanged"><option value="" disabled>-</option><option v-for="s in options.suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                    </label>
                    <label v-for="field in fields" :key="field.key" class="text-sm text-text-secondary">{{ t(field.label) }}
                        <input v-model.number="form[field.key]" type="number" step="1" :min="field.min" :max="field.max" :required="!field.optional" :class="inputClass" />
                    </label>
                    <label class="text-sm text-text-secondary">{{ t('purchaseOrders.create.unitCost') }} ({{ form.currency }})
                        <input v-model="form.unit_cost" type="number" min="0" max="9999999999.99" step="0.01" required :class="inputClass" />
                    </label>
                    <label class="text-sm text-text-secondary">{{ t('purchaseOrders.create.supplierSku') }}
                        <input v-model="form.supplier_sku" maxlength="255" :class="inputClass" />
                    </label>
                </div>
                <label v-if="hasPermission('create_purchase_orders')" class="flex items-start gap-2 text-sm text-text-secondary"><input v-model="form.auto_reorder" type="checkbox" class="mt-1" />{{ t('variantReplenishment.automatic') }}</label>
                <Button variant="secondary" :loading="loading" @click="loadOptions">{{ t('variantReplenishment.loadOptions') }}</Button>
                <p v-if="optionsError" role="alert" class="text-sm text-status-danger">{{ t('variantReplenishment.optionsError') }}</p>
                <ul v-if="Object.keys(form.errors).length" role="alert" class="list-inside list-disc text-sm text-status-danger"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul>
                <div class="flex justify-end gap-3"><Button variant="secondary" :disabled="form.processing" @click="show = false">{{ t('common.cancel') }}</Button><Button type="submit" :disabled="form.processing || loading" :loading="form.processing">{{ t('common.save') }}</Button></div>
            </form>
        </Modal>
    </AppLayout>
</template>
