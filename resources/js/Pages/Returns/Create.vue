<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    order: Object,
    returnedQuantities: Object,
});

const form = useForm({
    order_id: props.order.id,
    type: 'return',
    reason: '',
    notes: '',
    items: props.order.items.map(item => ({
        order_item_id: item.id,
        product_id: item.product_id,
        product_name: item.product_name,
        sku: item.sku,
        ordered_quantity: item.quantity,
        already_returned: props.returnedQuantities?.[item.id] || 0,
        quantity: 0,
        condition: 'new',
        restock: true,
        selected: false,
    })),
});

const selectedItems = computed(() => form.items.filter(item => item.selected && item.quantity > 0));

const maxReturnable = (item) => {
    return item.ordered_quantity - item.already_returned;
};

const toggleItem = (item) => {
    if (!item.selected) {
        item.quantity = 0;
        item.condition = 'new';
        item.restock = true;
    } else {
        item.quantity = Math.min(1, maxReturnable(item));
    }
};

const updateCondition = (item) => {
    if (item.condition === 'damaged') {
        item.restock = false;
    } else if (item.condition === 'new') {
        item.restock = true;
    }
};

const estimatedRefund = computed(() => {
    let total = 0;
    for (const item of form.items) {
        if (item.selected && item.quantity > 0) {
            const orderItem = props.order.items.find(oi => oi.id === item.order_item_id);
            if (orderItem) {
                total += item.quantity * parseFloat(orderItem.unit_price);
            }
        }
    }
    return total;
});

const submit = () => {
    const payload = {
        order_id: form.order_id,
        type: form.type,
        reason: form.reason,
        notes: form.notes,
        items: selectedItems.value.map(item => ({
            order_item_id: item.order_item_id,
            product_id: item.product_id,
            quantity: item.quantity,
            condition: item.condition,
            restock: item.restock,
        })),
    };

    form.transform(() => payload).post(route('returns.store'));
};
</script>

<template>
    <Head :title="`Create Return - Order #${order.order_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                        Create Return / Exchange
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        From Order #{{ order.order_number }}
                    </p>
                </div>
                <Link
                    :href="route('orders.show', order.id)"
                    class="inline-flex items-center px-4 py-2 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Order
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <form @submit.prevent="submit" class="space-y-6">
                    <!-- Return Type & Reason -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Return Details</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Type</label>
                                <select
                                    v-model="form.type"
                                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 sm:text-sm"
                                >
                                    <option value="return">Return (Refund)</option>
                                    <option value="exchange">Exchange</option>
                                </select>
                                <p v-if="form.errors.type" class="mt-1 text-sm text-red-400">{{ form.errors.type }}</p>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Reason *</label>
                            <textarea
                                v-model="form.reason"
                                rows="2"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400 sm:text-sm"
                                placeholder="Reason for return or exchange..."
                            ></textarea>
                            <p v-if="form.errors.reason" class="mt-1 text-sm text-red-400">{{ form.errors.reason }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Notes (optional)</label>
                            <textarea
                                v-model="form.notes"
                                rows="2"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400 sm:text-sm"
                                placeholder="Additional notes..."
                            ></textarea>
                        </div>
                    </div>

                    <!-- Select Items -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Select Items to Return</h3>

                        <div class="space-y-4">
                            <div
                                v-for="(item, index) in form.items"
                                :key="item.order_item_id"
                                class="border border-gray-200 dark:border-dark-border rounded-lg p-4"
                                :class="item.selected ? 'bg-primary-400/5 border-primary-400/30' : 'bg-gray-50 dark:bg-dark-bg'"
                            >
                                <div class="flex items-start gap-4">
                                    <!-- Checkbox -->
                                    <div class="pt-1">
                                        <input
                                            type="checkbox"
                                            v-model="item.selected"
                                            @change="toggleItem(item)"
                                            :disabled="maxReturnable(item) <= 0"
                                            class="rounded border-gray-300 dark:border-dark-border text-primary-400 focus:ring-primary-400"
                                        />
                                    </div>

                                    <!-- Product Info -->
                                    <div class="flex-1">
                                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ item.product_name }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">SKU: {{ item.sku }}</p>
                                        <p class="text-xs text-gray-400 mt-1">
                                            Ordered: {{ item.ordered_quantity }}
                                            <span v-if="item.already_returned > 0" class="text-yellow-400">
                                                ({{ item.already_returned }} already returned)
                                            </span>
                                            | Max returnable: {{ maxReturnable(item) }}
                                        </p>
                                    </div>

                                    <!-- Return Options (shown when selected) -->
                                    <div v-if="item.selected" class="flex items-center gap-4">
                                        <!-- Quantity -->
                                        <div>
                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Qty</label>
                                            <input
                                                type="number"
                                                v-model.number="item.quantity"
                                                :min="1"
                                                :max="maxReturnable(item)"
                                                class="block w-20 rounded-md bg-white dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 sm:text-sm"
                                            />
                                        </div>

                                        <!-- Condition -->
                                        <div>
                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Condition</label>
                                            <select
                                                v-model="item.condition"
                                                @change="updateCondition(item)"
                                                class="block w-32 rounded-md bg-white dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400 sm:text-sm"
                                            >
                                                <option value="new">New (Unopened)</option>
                                                <option value="used">Used (Opened)</option>
                                                <option value="damaged">Damaged</option>
                                            </select>
                                        </div>

                                        <!-- Restock -->
                                        <div class="text-center">
                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Restock</label>
                                            <input
                                                type="checkbox"
                                                v-model="item.restock"
                                                class="rounded border-gray-300 dark:border-dark-border text-primary-400 focus:ring-primary-400"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <!-- Validation errors for this item -->
                                <p v-if="form.errors[`items.${index}.quantity`]" class="mt-2 text-sm text-red-400">
                                    {{ form.errors[`items.${index}.quantity`] }}
                                </p>
                            </div>
                        </div>

                        <p v-if="form.errors.items" class="mt-2 text-sm text-red-400">{{ form.errors.items }}</p>
                    </div>

                    <!-- Summary & Submit -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ selectedItems.length }} item(s) selected for {{ form.type }}
                                </p>
                                <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                    Estimated Refund: <span class="text-primary-400">${{ estimatedRefund.toFixed(2) }}</span>
                                </p>
                            </div>
                            <div class="flex gap-3">
                                <Link
                                    :href="route('orders.show', order.id)"
                                    class="px-4 py-2 bg-white dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-600 dark:text-gray-300 rounded-md font-semibold text-sm hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                                >
                                    Cancel
                                </Link>
                                <button
                                    type="submit"
                                    :disabled="form.processing || selectedItems.length === 0"
                                    class="px-6 py-2 bg-primary-400 text-white rounded-md font-semibold text-sm uppercase tracking-widest hover:bg-primary-500 disabled:opacity-50 transition"
                                >
                                    <span v-if="form.processing">Processing...</span>
                                    <span v-else>Submit Return Request</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
