<script setup>
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    productId: { type: Number, required: true },
    levels: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    productLevels: { type: Object, default: () => ({}) },
});

const fields = ['min_stock', 'reorder_point', 'reorder_quantity', 'max_stock'];

const form = useForm({
    levels: props.levels.map((level) => ({
        warehouse_id: level.warehouse_id,
        min_stock: level.min_stock,
        reorder_point: level.reorder_point,
        reorder_quantity: level.reorder_quantity,
        max_stock: level.max_stock,
    })),
});

const save = () => {
    form.transform((data) => ({
        levels: data.levels.map((level) => {
            const clean = { warehouse_id: level.warehouse_id };
            fields.forEach((field) => {
                clean[field] = level[field] === '' || level[field] === undefined ? null : level[field];
            });
            return clean;
        }),
    })).put(route('products.warehouse-levels.update', props.productId), {
        preserveScroll: true,
    });
};

const errorFor = (index, field) => form.errors[`levels.${index}.${field}`];

const thClass = 'px-3 py-2 text-left text-xs font-medium text-text-secondary';
const cellInput = 'h-8 w-24 rounded-md border border-border-subtle bg-surface-canvas px-2 text-sm tabular-nums text-text-primary placeholder:text-text-tertiary ds-focus-ring';
</script>

<template>
    <Card :padded="false">
        <div class="px-5 pt-5">
            <h3 class="text-sm font-semibold text-text-primary">{{ t('warehouses.stockLevels.title') }}</h3>
            <p class="mt-1 text-xs text-text-tertiary">{{ t('warehouses.stockLevels.hint') }}</p>
        </div>
        <div class="p-5">
            <p v-if="levels.length === 0" class="text-sm text-text-tertiary">{{ t('warehouses.stockLevels.noWarehouses') }}</p>

            <form v-else @submit.prevent="save">
                <div class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-border-subtle">
                                <th :class="thClass">{{ t('warehouses.stockLevels.warehouse') }}</th>
                                <th :class="[thClass, 'text-right']">{{ t('warehouses.stockLevels.onHand') }}</th>
                                <th v-for="field in fields" :key="field" :class="thClass">{{ t(`warehouses.stockLevels.${field}`) }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(level, index) in levels"
                                :key="level.warehouse_id"
                                class="border-b border-border-subtle align-top last:border-b-0"
                            >
                                <td class="px-3 py-2">
                                    <div class="font-medium text-text-primary">{{ level.warehouse_name }}</div>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <Badge v-if="level.is_low" variant="warning" size="sm">{{ t('warehouses.stockLevels.low') }}</Badge>
                                        <Badge v-if="level.needs_reorder" variant="danger" size="sm">{{ t('warehouses.stockLevels.reorder') }}</Badge>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-text-primary">{{ level.on_hand }}</td>
                                <td v-for="field in fields" :key="field" class="px-3 py-2">
                                    <input
                                        v-if="canEdit"
                                        v-model.number="form.levels[index][field]"
                                        type="number"
                                        min="0"
                                        :placeholder="productLevels[field] ?? ''"
                                        :aria-label="`${level.warehouse_name}: ${t(`warehouses.stockLevels.${field}`)}`"
                                        :class="cellInput"
                                    />
                                    <span v-else class="tabular-nums text-text-primary">
                                        {{ level[field] ?? productLevels[field] ?? '-' }}
                                    </span>
                                    <p v-if="errorFor(index, field)" class="mt-1 max-w-[12rem] text-xs text-status-danger">{{ errorFor(index, field) }}</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="canEdit" class="mt-3 flex justify-end">
                    <Button type="submit" variant="default" size="sm" :loading="form.processing" :disabled="form.processing">
                        {{ t('warehouses.stockLevels.save') }}
                    </Button>
                </div>
            </form>
        </div>
    </Card>
</template>
