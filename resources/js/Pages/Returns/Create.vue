<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Trash2 } from 'lucide-vue-next';

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

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="`Create Return - Order #${order.order_number}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('orders.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('returns.index')" class="text-text-tertiary hover:text-text-primary">Returns</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">New</span>
            </div>
        </template>

        <PageHeader title="Create Return / Exchange" :description="`From Order #${order.order_number}`">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('orders.show', order.id)">
                    <ArrowLeft :size="14" />
                    Back to Order
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6 space-y-4">
            <!-- Return Type & Reason -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Return Details</h3></div>
                <div class="space-y-4 p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label :class="fieldLabel">Type</label>
                            <select v-model="form.type" :class="fieldInput">
                                <option value="return">Return (Refund)</option>
                                <option value="exchange">Exchange</option>
                            </select>
                            <p v-if="form.errors.type" :class="fieldError">{{ form.errors.type }}</p>
                        </div>
                    </div>

                    <div>
                        <label :class="fieldLabel">Reason *</label>
                        <textarea
                            v-model="form.reason"
                            rows="2"
                            :class="fieldArea"
                            placeholder="Reason for return or exchange..."
                        ></textarea>
                        <p v-if="form.errors.reason" :class="fieldError">{{ form.errors.reason }}</p>
                    </div>

                    <div>
                        <label :class="fieldLabel">Notes (optional)</label>
                        <textarea
                            v-model="form.notes"
                            rows="2"
                            :class="fieldArea"
                            placeholder="Additional notes..."
                        ></textarea>
                    </div>
                </div>
            </Card>

            <!-- Select Items -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Select Items to Return</h3></div>
                <div class="p-5">
                    <div class="space-y-3">
                        <div
                            v-for="(item, index) in form.items"
                            :key="item.order_item_id"
                            class="rounded-lg border border-border-subtle bg-surface-canvas p-4"
                        >
                            <div class="flex items-start gap-4">
                                <!-- Checkbox -->
                                <div class="pt-1">
                                    <input
                                        type="checkbox"
                                        v-model="item.selected"
                                        @change="toggleItem(item)"
                                        :disabled="maxReturnable(item) <= 0"
                                        class="rounded border-border-subtle text-brand ds-focus-ring"
                                    />
                                </div>

                                <!-- Product Info -->
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-text-primary">{{ item.product_name }}</p>
                                    <p class="text-sm text-text-tertiary">SKU: {{ item.sku }}</p>
                                    <p class="text-xs text-text-tertiary mt-1">
                                        Ordered: {{ item.ordered_quantity }}
                                        <span v-if="item.already_returned > 0" class="text-status-warning">
                                            ({{ item.already_returned }} already returned)
                                        </span>
                                        | Max returnable: {{ maxReturnable(item) }}
                                    </p>
                                </div>

                                <!-- Return Options (shown when selected) -->
                                <div v-if="item.selected" class="flex items-center gap-4">
                                    <!-- Quantity -->
                                    <div class="w-20">
                                        <label :class="fieldLabel">Qty</label>
                                        <input
                                            type="number"
                                            v-model.number="item.quantity"
                                            :min="1"
                                            :max="maxReturnable(item)"
                                            :class="fieldInput"
                                        />
                                    </div>

                                    <!-- Condition -->
                                    <div class="w-36">
                                        <label :class="fieldLabel">Condition</label>
                                        <select
                                            v-model="item.condition"
                                            @change="updateCondition(item)"
                                            :class="fieldInput"
                                        >
                                            <option value="new">New (Unopened)</option>
                                            <option value="used">Used (Opened)</option>
                                            <option value="damaged">Damaged</option>
                                        </select>
                                    </div>

                                    <!-- Restock -->
                                    <div class="text-center">
                                        <label :class="fieldLabel">Restock</label>
                                        <input
                                            type="checkbox"
                                            v-model="item.restock"
                                            class="rounded border-border-subtle text-brand ds-focus-ring"
                                        />
                                    </div>
                                </div>
                            </div>

                            <!-- Validation errors for this item -->
                            <p v-if="form.errors[`items.${index}.quantity`]" :class="fieldError">
                                {{ form.errors[`items.${index}.quantity`] }}
                            </p>
                        </div>
                    </div>

                    <p v-if="form.errors.items" :class="fieldError">{{ form.errors.items }}</p>
                </div>
            </Card>

            <!-- Summary & Submit -->
            <Card :padded="false">
                <div class="p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-text-tertiary">
                                {{ selectedItems.length }} item(s) selected for {{ form.type }}
                            </p>
                            <p class="text-lg font-semibold text-text-primary">
                                Estimated Refund: <span class="text-brand">${{ estimatedRefund.toFixed(2) }}</span>
                            </p>
                        </div>
                        <div class="flex gap-3">
                            <Button variant="secondary" as="Link" :href="route('orders.show', order.id)">
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                variant="default"
                                :loading="form.processing"
                                :disabled="form.processing || selectedItems.length === 0"
                            >
                                {{ form.processing ? 'Processing...' : 'Submit Return Request' }}
                            </Button>
                        </div>
                    </div>
                </div>
            </Card>
        </form>
    </AppLayout>
</template>
