<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft } from 'lucide-vue-next';

const props = defineProps({
    order: { type: Object, required: true },
});

const { t } = useI18n();

const returnable = computed(() => props.order.items.filter((item) => item.returnable_quantity > 0));

// Per-line selections, keyed by order item id. Quantity 0 means "not returning".
const lines = reactive(
    Object.fromEntries(props.order.items.map((item) => [item.id, { quantity: 0, condition: 'new' }]))
);

const form = useForm({
    type: 'return',
    reason: '',
    notes: '',
    items: [],
});

const selectionError = ref(null);

const submit = () => {
    const items = returnable.value
        .filter((item) => Number(lines[item.id].quantity) > 0)
        .map((item) => ({
            order_item_id: item.id,
            quantity: Number(lines[item.id].quantity),
            condition: lines[item.id].condition,
        }));

    if (items.length === 0) {
        selectionError.value = t('portal.returnForm.nothingSelected');
        return;
    }

    selectionError.value = null;
    form.items = items;
    form.post(route('portal.returns.store', { order: props.order.id }));
};

// Validation errors come back keyed by position in the submitted items.
const lineError = (itemId) => {
    const index = form.items.findIndex((line) => line.order_item_id === itemId);
    if (index === -1) {
        return null;
    }
    return form.errors[`items.${index}.quantity`] || form.errors[`items.${index}.order_item_id`] || form.errors[`items.${index}.condition`] || null;
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="t('portal.returnForm.title')" />

    <PortalLayout>
        <div class="mb-4">
            <Link :href="route('portal.orders.show', { order: order.id })" class="inline-flex items-center gap-1 text-sm text-text-secondary hover:text-text-primary">
                <ArrowLeft :size="14" />
                {{ order.order_number }}
            </Link>
        </div>

        <PageHeader :title="t('portal.returnForm.title')" :description="t('portal.returnForm.description', { number: order.order_number })" />

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <Card :padded="false">
                <div class="p-5">
                    <p v-if="returnable.length === 0" class="text-sm text-text-tertiary">{{ t('portal.returnForm.noneReturnable') }}</p>
                    <div v-else class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-border-subtle">
                                    <th :class="thClass">{{ t('portal.order.product') }}</th>
                                    <th :class="thClass">{{ t('portal.returnForm.quantity') }}</th>
                                    <th :class="thClass">{{ t('portal.returnForm.condition') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in returnable" :key="item.id" class="border-b border-border-subtle align-top last:border-b-0">
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-text-primary">{{ item.product_name }}</div>
                                        <div v-if="item.variant_title" class="text-xs text-text-secondary">{{ item.variant_title }}</div>
                                        <div class="text-xs text-text-tertiary">
                                            {{ t('portal.order.sku') }} {{ item.sku }} · {{ t('portal.returnForm.available', { count: item.returnable_quantity }) }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <input
                                            v-model.number="lines[item.id].quantity"
                                            type="number"
                                            min="0"
                                            :max="item.returnable_quantity"
                                            :aria-label="`${t('portal.returnForm.quantity')} ${item.product_name}`"
                                            :class="[fieldInput, 'w-24']"
                                        />
                                        <InputError class="mt-1" :message="lineError(item.id)" />
                                    </td>
                                    <td class="px-4 py-3">
                                        <select
                                            v-model="lines[item.id].condition"
                                            :aria-label="`${t('portal.returnForm.condition')} ${item.product_name}`"
                                            :class="[fieldInput, 'w-44']"
                                        >
                                            <option value="new">{{ t('portal.returnForm.conditions.new') }}</option>
                                            <option value="used">{{ t('portal.returnForm.conditions.used') }}</option>
                                            <option value="damaged">{{ t('portal.returnForm.conditions.damaged') }}</option>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <InputError class="mt-2" :message="selectionError || form.errors.items" />
                </div>
            </Card>

            <Card :padded="false">
                <div class="grid grid-cols-1 gap-5 p-5 md:grid-cols-2">
                    <div>
                        <label for="type" :class="fieldLabel">{{ t('portal.returnForm.type') }}</label>
                        <select id="type" v-model="form.type" :class="fieldInput">
                            <option value="return">{{ t('portal.returns.types.return') }}</option>
                            <option value="exchange">{{ t('portal.returns.types.exchange') }}</option>
                        </select>
                        <InputError class="mt-1" :message="form.errors.type" />
                    </div>
                    <div class="md:col-span-2">
                        <label for="reason" :class="fieldLabel">{{ t('portal.returnForm.reason') }}</label>
                        <textarea
                            id="reason"
                            v-model="form.reason"
                            rows="3"
                            maxlength="1000"
                            required
                            :placeholder="t('portal.returnForm.reasonPlaceholder')"
                            :class="fieldArea"
                        />
                        <InputError class="mt-1" :message="form.errors.reason" />
                    </div>
                    <div class="md:col-span-2">
                        <label for="notes" :class="fieldLabel">{{ t('portal.returnForm.notes') }}</label>
                        <textarea
                            id="notes"
                            v-model="form.notes"
                            rows="2"
                            maxlength="2000"
                            :placeholder="t('portal.returnForm.notesPlaceholder')"
                            :class="fieldArea"
                        />
                        <InputError class="mt-1" :message="form.errors.notes" />
                    </div>
                </div>
            </Card>

            <div class="flex justify-end gap-2">
                <Button variant="secondary" as="Link" :href="route('portal.orders.show', { order: order.id })">
                    {{ t('portal.returnForm.cancel') }}
                </Button>
                <Button type="submit" :loading="form.processing" :disabled="form.processing || returnable.length === 0">
                    {{ t('portal.returnForm.submit') }}
                </Button>
            </div>
        </form>
    </PortalLayout>
</template>
