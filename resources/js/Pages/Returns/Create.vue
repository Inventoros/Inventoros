<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Trash2 } from 'lucide-vue-next';
import { formatMoney } from '@/lib/money';
import { usePermissions } from '@/composables/usePermissions';

const { canVisit } = usePermissions();

const { t } = useI18n();

// Back to the order, or to the returns list for a user who cannot open orders.
const backHref = computed(() => (canVisit('orders.show') ? route('orders.show', props.order.id) : route('returns.index')));

const props = defineProps({
    order: Object,
    returnedQuantities: Object,
    // Per order item id: what was paid for the whole line after line and
    // order discounts. The refund is that share, as the server computes it.
    paidNets: { type: Object, default: () => ({}) },
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
        // A variant line shows its variant, as it was sold.
        variant_title: item.variant?.title ?? null,
        sku: item.variant?.sku || item.sku,
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
                const paid = props.paidNets?.[orderItem.id];
                const lineRefund = paid !== undefined && paid !== null && orderItem.quantity > 0
                    ? (parseFloat(paid) * item.quantity) / orderItem.quantity
                    : item.quantity * parseFloat(orderItem.unit_price);
                total += Math.round(lineRefund * 100) / 100;
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
    <Head :title="t('returns.create.headTitle', { number: order.order_number })" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('returns.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.returns') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('common.new') }}</span>
            </div>
        </template>

        <PageHeader :title="t('returns.create.title')" :description="t('returns.create.fromOrder', { number: order.order_number })">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="backHref">
                    <ArrowLeft :size="14" />
                    {{ t('returns.create.backToOrder') }}
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6 space-y-4">
            <!-- Return Type & Reason -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('returns.create.details') }}</h3></div>
                <div class="space-y-4 p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label :class="fieldLabel">{{ t('common.type') }}</label>
                            <select v-model="form.type" :class="fieldInput">
                                <option value="return">{{ t('returns.create.typeReturn') }}</option>
                                <option value="exchange">{{ t('portal.returns.types.exchange') }}</option>
                            </select>
                            <p v-if="form.errors.type" :class="fieldError">{{ form.errors.type }}</p>
                        </div>
                    </div>

                    <div>
                        <label :class="fieldLabel">{{ t('returns.create.reasonRequired') }}</label>
                        <textarea
                            v-model="form.reason"
                            rows="2"
                            :class="fieldArea"
                            :placeholder="t('returns.create.reasonPlaceholder')"
                        ></textarea>
                        <p v-if="form.errors.reason" :class="fieldError">{{ form.errors.reason }}</p>
                    </div>

                    <div>
                        <label :class="fieldLabel">{{ t('returns.create.notesOptional') }}</label>
                        <textarea
                            v-model="form.notes"
                            rows="2"
                            :class="fieldArea"
                            :placeholder="t('returns.create.notesPlaceholder')"
                        ></textarea>
                    </div>
                </div>
            </Card>

            <!-- Select Items -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('returns.create.selectItems') }}</h3></div>
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
                                    <p class="font-medium text-text-primary">{{ item.product_name }}<span v-if="item.variant_title" class="text-text-secondary"> ({{ item.variant_title }})</span></p>
                                    <p class="text-sm text-text-tertiary">SKU: {{ item.sku }}</p>
                                    <p class="text-xs text-text-tertiary mt-1">
                                        {{ t('returns.create.ordered', { count: item.ordered_quantity }) }}
                                        <span v-if="item.already_returned > 0" class="text-status-warning">
                                            {{ t('returns.create.alreadyReturned', { count: item.already_returned }) }}
                                        </span>
                                        | {{ t('returns.create.maxReturnable', { count: maxReturnable(item) }) }}
                                    </p>
                                </div>

                                <!-- Return Options (shown when selected) -->
                                <div v-if="item.selected" class="flex items-center gap-4">
                                    <!-- Quantity -->
                                    <div class="w-20">
                                        <label :class="fieldLabel">{{ t('returns.columns.qty') }}</label>
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
                                        <label :class="fieldLabel">{{ t('returns.columns.condition') }}</label>
                                        <select
                                            v-model="item.condition"
                                            @change="updateCondition(item)"
                                            :class="fieldInput"
                                        >
                                            <option value="new">{{ t('returns.conditions.new') }}</option>
                                            <option value="used">{{ t('returns.conditions.used') }}</option>
                                            <option value="damaged">{{ t('returns.conditions.damaged') }}</option>
                                        </select>
                                    </div>

                                    <!-- Restock -->
                                    <div class="text-center">
                                        <label :class="fieldLabel">{{ t('returns.columns.restock') }}</label>
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
                                {{ t('returns.create.selectedSummary', { count: selectedItems.length, type: t(`portal.returns.types.${form.type}`) }, selectedItems.length) }}
                            </p>
                            <p class="text-lg font-semibold text-text-primary">
                                {{ t('returns.create.estimatedRefund') }} <span class="text-brand">{{ formatMoney(estimatedRefund, order.currency) }}</span>
                            </p>
                        </div>
                        <div class="flex gap-3">
                            <Button variant="secondary" as="Link" :href="backHref">
                                {{ t('common.cancel') }}
                            </Button>
                            <Button
                                type="submit"
                                variant="default"
                                :loading="form.processing"
                                :disabled="form.processing || selectedItems.length === 0"
                            >
                                {{ form.processing ? t('returns.create.processing') : t('returns.create.submit') }}
                            </Button>
                        </div>
                    </div>
                </div>
            </Card>
        </form>
    </AppLayout>
</template>
