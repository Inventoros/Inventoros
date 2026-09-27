<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Button from '@/Components/ui/Button.vue';
import { Plus, Trash2, Truck } from 'lucide-vue-next';

/**
 * Edits a product's supplier links: supplier, supplier SKU, cost, lead time,
 * minimum order quantity and exactly one primary supplier. v-model is the `suppliers` array the
 * product form submits; `errors` is the Inertia form's error bag.
 */
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    // Active suppliers of the organization: [{ id, name, code }]
    suppliers: { type: Array, default: () => [] },
    // Suppliers already linked (may include inactive ones not in `suppliers`)
    linked: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    currencySymbol: { type: String, default: '$' },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();

const options = computed(() => {
    const byId = new Map();
    [...props.linked, ...props.suppliers].forEach((s) => byId.set(s.id, s));
    return [...byId.values()].sort((a, b) => a.name.localeCompare(b.name));
});

const rows = computed(() => props.modelValue || []);

const update = (next) => {
    // Keep exactly one primary whenever there is at least one row.
    if (next.length > 0 && !next.some((r) => r.is_primary)) {
        next = next.map((r, i) => ({ ...r, is_primary: i === 0 }));
    }
    emit('update:modelValue', next);
};

const addRow = () => {
    const used = new Set(rows.value.map((r) => Number(r.supplier_id)));
    const next = options.value.find((s) => !used.has(s.id));
    update([
        ...rows.value,
        {
            supplier_id: next ? next.id : '',
            supplier_sku: '',
            cost_price: '',
            lead_time_days: '',
            minimum_order_quantity: '',
            is_primary: rows.value.length === 0,
        },
    ]);
};

const removeRow = (index) => {
    update(rows.value.filter((_, i) => i !== index));
};

const setField = (index, field, value) => {
    update(rows.value.map((r, i) => (i === index ? { ...r, [field]: value } : r)));
};

const makePrimary = (index) => {
    update(rows.value.map((r, i) => ({ ...r, is_primary: i === index })));
};

const isTaken = (supplierId, index) =>
    rows.value.some((r, i) => i !== index && Number(r.supplier_id) === supplierId);

const error = (index, field) => props.errors?.[`suppliers.${index}.${field}`];

const fieldLabel = 'mb-1 block text-xs font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <div class="space-y-3">
        <p class="text-sm text-text-tertiary">{{ t('productSuppliers.hint') }}</p>

        <div
            v-for="(row, index) in rows"
            :key="index"
            class="rounded-lg border border-border-subtle bg-surface-sunken/40 p-3"
        >
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-12">
                <div class="sm:col-span-2 lg:col-span-3">
                    <label :for="`supplier-${index}`" :class="fieldLabel">{{ t('productSuppliers.supplier') }}</label>
                    <select
                        :id="`supplier-${index}`"
                        :value="row.supplier_id"
                        :class="fieldInput"
                        @change="setField(index, 'supplier_id', $event.target.value ? Number($event.target.value) : '')"
                    >
                        <option value="">{{ t('productSuppliers.selectSupplier') }}</option>
                        <option
                            v-for="supplier in options"
                            :key="supplier.id"
                            :value="supplier.id"
                            :disabled="isTaken(supplier.id, index)"
                        >
                            {{ supplier.name }}<template v-if="supplier.code"> ({{ supplier.code }})</template>
                        </option>
                    </select>
                    <p v-if="error(index, 'supplier_id')" :class="fieldError">{{ error(index, 'supplier_id') }}</p>
                </div>

                <div class="lg:col-span-2">
                    <label :for="`supplier-sku-${index}`" :class="fieldLabel">{{ t('productSuppliers.supplierSku') }}</label>
                    <input
                        :id="`supplier-sku-${index}`"
                        :value="row.supplier_sku"
                        type="text"
                        maxlength="255"
                        :class="fieldInput"
                        @input="setField(index, 'supplier_sku', $event.target.value)"
                    />
                    <p v-if="error(index, 'supplier_sku')" :class="fieldError">{{ error(index, 'supplier_sku') }}</p>
                </div>

                <div class="lg:col-span-2">
                    <label :for="`supplier-cost-${index}`" :class="fieldLabel">{{ t('productSuppliers.costPrice') }}</label>
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <span class="text-sm text-text-tertiary">{{ currencySymbol }}</span>
                        </div>
                        <input
                            :id="`supplier-cost-${index}`"
                            :value="row.cost_price"
                            type="number"
                            step="0.01"
                            min="0"
                            :class="[fieldInput, 'pl-7']"
                            @input="setField(index, 'cost_price', $event.target.value)"
                        />
                    </div>
                    <p v-if="error(index, 'cost_price')" :class="fieldError">{{ error(index, 'cost_price') }}</p>
                </div>

                <div class="lg:col-span-2">
                    <label :for="`supplier-lead-${index}`" :class="fieldLabel">{{ t('productSuppliers.leadTimeDays') }}</label>
                    <input
                        :id="`supplier-lead-${index}`"
                        :value="row.lead_time_days"
                        type="number"
                        min="0"
                        step="1"
                        :class="fieldInput"
                        @input="setField(index, 'lead_time_days', $event.target.value)"
                    />
                    <p v-if="error(index, 'lead_time_days')" :class="fieldError">{{ error(index, 'lead_time_days') }}</p>
                </div>

                <div class="lg:col-span-2">
                    <label :for="`supplier-moq-${index}`" :class="fieldLabel">{{ t('productSuppliers.minimumOrderQuantity') }}</label>
                    <input
                        :id="`supplier-moq-${index}`"
                        :value="row.minimum_order_quantity"
                        type="number"
                        min="1"
                        step="1"
                        :class="fieldInput"
                        :placeholder="t('productSuppliers.minimumOrderPlaceholder')"
                        @input="setField(index, 'minimum_order_quantity', $event.target.value)"
                    />
                    <p v-if="error(index, 'minimum_order_quantity')" :class="fieldError">{{ error(index, 'minimum_order_quantity') }}</p>
                </div>

                <div class="flex items-end justify-between gap-2 sm:col-span-2 lg:col-span-1 lg:flex-col lg:items-end lg:justify-end">
                    <label class="flex h-9 items-center gap-1.5 text-xs text-text-secondary" :title="t('productSuppliers.primary')">
                        <input
                            type="radio"
                            name="primary-supplier"
                            :checked="row.is_primary"
                            class="border-border-subtle text-brand ds-focus-ring"
                            @change="makePrimary(index)"
                        />
                        {{ t('productSuppliers.primary') }}
                    </label>
                    <button
                        type="button"
                        class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-status-danger-soft hover:text-status-danger ds-focus-ring"
                        :aria-label="t('productSuppliers.remove')"
                        :title="t('productSuppliers.remove')"
                        @click="removeRow(index)"
                    >
                        <Trash2 :size="16" />
                    </button>
                </div>
            </div>
        </div>

        <p v-if="errors?.suppliers" :class="fieldError">{{ errors.suppliers }}</p>

        <div v-if="options.length === 0" class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-border-subtle py-6 text-center">
            <Truck :size="20" class="text-text-tertiary" />
            <p class="text-sm text-text-tertiary">{{ t('productSuppliers.noSuppliersYet') }}</p>
            <Link :href="route('suppliers.create')" class="text-sm font-medium text-brand hover:underline">
                {{ t('productSuppliers.createSupplier') }}
            </Link>
        </div>
        <template v-else>
            <p v-if="rows.length === 0" class="text-sm text-text-tertiary">{{ t('productSuppliers.empty') }}</p>
            <Button
                type="button"
                variant="secondary"
                size="sm"
                :disabled="rows.length >= options.length"
                @click="addRow"
            >
                <Plus :size="14" />
                {{ t('productSuppliers.add') }}
            </Button>
        </template>
    </div>
</template>
