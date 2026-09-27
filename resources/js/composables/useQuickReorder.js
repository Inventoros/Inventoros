import { computed, ref, unref } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePermissions } from '@/composables/usePermissions';

/**
 * Selection + submit for the "Create PO" quick reorder action shown on the
 * dashboard reorder suggestions and the low-stock report.
 *
 * `rows` is a ref/array of rows carrying `id` and `supplier_id` (the primary
 * supplier, null when none). Only rows with a supplier can be selected; the
 * server groups the selection into one draft PO per supplier.
 */
export function useQuickReorder(rows) {
    const { hasPermission } = usePermissions();

    const canCreatePo = computed(() => hasPermission('create_purchase_orders'));
    const selected = ref([]);
    const submitting = ref(false);

    const orderable = computed(() => (unref(rows) || []).filter((row) => row.supplier_id));

    const isSelected = (id) => selected.value.includes(id);

    const toggle = (id) => {
        selected.value = isSelected(id)
            ? selected.value.filter((x) => x !== id)
            : [...selected.value, id];
    };

    const allSelected = computed(
        () => orderable.value.length > 0 && orderable.value.every((row) => isSelected(row.id))
    );

    const toggleAll = () => {
        selected.value = allSelected.value ? [] : orderable.value.map((row) => row.id);
    };

    const createPurchaseOrders = (ids = selected.value) => {
        if (!ids.length || submitting.value) return;

        router.post(
            route('purchase-orders.quick-reorder'),
            { product_ids: ids },
            {
                preserveScroll: true,
                onStart: () => (submitting.value = true),
                onFinish: () => (submitting.value = false),
                onSuccess: () => (selected.value = []),
            }
        );
    };

    return {
        canCreatePo,
        selected,
        submitting,
        orderable,
        isSelected,
        toggle,
        allSelected,
        toggleAll,
        createPurchaseOrders,
    };
}
