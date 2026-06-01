<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';

/**
 * Linear-style data table.
 *
 * <DataTable :columns="cols" :rows="rows" :row-href="r => route('products.show', r.id)" />
 *
 * Columns: [{ key, label, align?: 'left'|'right'|'center', class?, width? }]
 * Slot per cell:  <template #cell-{key}="{ row, value }">...</template>
 * Empty state:    <template #empty>No products yet</template>
 */
const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    rowHref: { type: Function, default: null },
    rowKey: { type: [String, Function], default: 'id' },
    loading: { type: Boolean, default: false },
    dense: { type: Boolean, default: false },
});

const emit = defineEmits(['row-click']);

const keyOf = (row, idx) => {
    if (typeof props.rowKey === 'function') return props.rowKey(row);
    return row[props.rowKey] ?? idx;
};

const alignClass = (align) => ({
    right: 'text-right',
    center: 'text-center',
    left: 'text-left',
}[align] || 'text-left');

const cellPadding = computed(() => (props.dense ? 'px-3 py-2' : 'px-4 py-3'));
</script>

<template>
    <div class="w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-border-subtle">
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        :class="cn(
                            'font-medium text-text-secondary text-xs tracking-tight',
                            cellPadding,
                            alignClass(col.align),
                            col.class,
                        )"
                        :style="col.width ? { width: col.width } : undefined"
                    >
                        {{ col.label }}
                    </th>
                </tr>
            </thead>

            <tbody>
                <tr
                    v-for="(row, idx) in rows"
                    :key="keyOf(row, idx)"
                    :class="cn(
                        'border-b border-border-subtle last:border-b-0',
                        'transition-colors',
                        rowHref || $attrs.onRowClick
                            ? 'hover:bg-surface-overlay cursor-pointer'
                            : 'hover:bg-surface-overlay/60',
                    )"
                    @click="emit('row-click', row)"
                >
                    <td
                        v-for="col in columns"
                        :key="col.key"
                        :class="cn(cellPadding, alignClass(col.align), 'text-text-primary', col.class)"
                    >
                        <component
                            :is="rowHref ? Link : 'div'"
                            v-bind="rowHref ? { href: rowHref(row) } : {}"
                            :class="rowHref ? 'block -mx-4 -my-3 px-4 py-3' : ''"
                        >
                            <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                                {{ row[col.key] }}
                            </slot>
                        </component>
                    </td>
                </tr>

                <tr v-if="rows.length === 0">
                    <td :colspan="columns.length" class="px-4 py-12 text-center">
                        <slot name="empty">
                            <p class="text-sm text-text-tertiary">No data.</p>
                        </slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
