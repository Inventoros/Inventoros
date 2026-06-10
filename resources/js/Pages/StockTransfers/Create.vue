<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, ArrowRight, Plus, Trash2 } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    locations: Array,
    products: Array,
});

const form = useForm({
    from_location_id: '',
    to_location_id: '',
    notes: '',
    items: [
        { product_id: '', quantity: 1, notes: '' },
    ],
});

const addItem = () => {
    form.items.push({ product_id: '', quantity: 1, notes: '' });
};

const removeItem = (index) => {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
    }
};

const availableToLocations = computed(() => {
    return props.locations.filter(loc => loc.id !== parseInt(form.from_location_id));
});

const getProduct = (productId) => {
    return props.products.find(p => p.id === parseInt(productId));
};

const totalItems = computed(() => {
    return form.items.reduce((sum, item) => sum + (parseInt(item.quantity) || 0), 0);
});

const hasValidItems = computed(() => {
    return form.items.some(item => item.product_id && item.quantity > 0);
});

const submit = () => {
    form.post(route('stock-transfers.store'), {
        preserveScroll: true,
    });
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head title="Create Stock Transfer" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('stock-transfers.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('stock-transfers.index')" class="text-text-tertiary hover:text-text-primary">Stock Transfers</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">New</span>
            </div>
        </template>

        <PageHeader title="Create Stock Transfer" description="Transfer inventory between locations.">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-transfers.index')">
                    <ArrowLeft :size="14" />
                    Back to List
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6 space-y-4">
            <!-- Location Selection -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Transfer Details</h3></div>
                <div class="p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <!-- From Location -->
                        <div>
                            <label :class="fieldLabel">From Location <span class="text-status-danger">*</span></label>
                            <select
                                v-model="form.from_location_id"
                                required
                                :class="fieldInput"
                            >
                                <option value="">Select source location</option>
                                <option v-for="location in locations" :key="location.id" :value="location.id">
                                    {{ location.name }} {{ location.code ? `(${location.code})` : '' }}
                                </option>
                            </select>
                            <p v-if="form.errors.from_location_id" :class="fieldError">
                                {{ form.errors.from_location_id }}
                            </p>
                        </div>

                        <!-- To Location -->
                        <div>
                            <label :class="fieldLabel">To Location <span class="text-status-danger">*</span></label>
                            <select
                                v-model="form.to_location_id"
                                required
                                :class="fieldInput"
                            >
                                <option value="">Select destination location</option>
                                <option v-for="location in availableToLocations" :key="location.id" :value="location.id">
                                    {{ location.name }} {{ location.code ? `(${location.code})` : '' }}
                                </option>
                            </select>
                            <p v-if="form.errors.to_location_id" :class="fieldError">
                                {{ form.errors.to_location_id }}
                            </p>
                        </div>
                    </div>

                    <!-- Arrow indicator between locations -->
                    <div v-if="form.from_location_id && form.to_location_id" class="mt-4 rounded-lg border border-border-subtle bg-surface-canvas p-3">
                        <div class="flex items-center justify-center gap-3 text-sm text-text-secondary">
                            <span class="font-medium text-text-primary">{{ locations.find(l => l.id === parseInt(form.from_location_id))?.name }}</span>
                            <ArrowRight :size="18" class="text-text-tertiary" />
                            <span class="font-medium text-text-primary">{{ locations.find(l => l.id === parseInt(form.to_location_id))?.name }}</span>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="mt-4">
                        <label :class="fieldLabel">Notes (Optional)</label>
                        <textarea
                            v-model="form.notes"
                            rows="3"
                            :class="fieldArea"
                            placeholder="Add any notes about this transfer..."
                        ></textarea>
                        <p v-if="form.errors.notes" :class="fieldError">
                            {{ form.errors.notes }}
                        </p>
                    </div>
                </div>
            </Card>

            <!-- Transfer Items -->
            <Card :padded="false">
                <div class="flex items-center justify-between px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">Transfer Items</h3>
                    <Button type="button" variant="default" size="sm" @click="addItem">
                        <Plus :size="14" />
                        Add Item
                    </Button>
                </div>
                <div class="p-5">
                    <p v-if="form.errors.items" :class="fieldError" class="mb-4">
                        {{ form.errors.items }}
                    </p>

                    <div class="space-y-3">
                        <div
                            v-for="(item, index) in form.items"
                            :key="index"
                            class="flex items-start gap-4 rounded-lg border border-border-subtle bg-surface-canvas p-4"
                        >
                            <!-- Product -->
                            <div class="min-w-[200px] flex-1">
                                <label :class="fieldLabel">Product <span class="text-status-danger">*</span></label>
                                <select
                                    v-model="item.product_id"
                                    required
                                    :class="fieldInput"
                                >
                                    <option value="">Select product</option>
                                    <option v-for="product in products" :key="product.id" :value="product.id">
                                        {{ product.name }} ({{ product.sku }}) - Stock: {{ product.stock }}
                                    </option>
                                </select>
                                <p v-if="form.errors[`items.${index}.product_id`]" :class="fieldError">
                                    {{ form.errors[`items.${index}.product_id`] }}
                                </p>
                            </div>

                            <!-- Quantity -->
                            <div class="w-32">
                                <label :class="fieldLabel">Qty <span class="text-status-danger">*</span></label>
                                <input
                                    v-model="item.quantity"
                                    type="number"
                                    min="1"
                                    required
                                    :class="fieldInput"
                                />
                                <p v-if="item.product_id && getProduct(item.product_id)" class="mt-1 text-xs text-text-tertiary">
                                    Available: {{ getProduct(item.product_id).stock }}
                                </p>
                                <p v-if="form.errors[`items.${index}.quantity`]" :class="fieldError">
                                    {{ form.errors[`items.${index}.quantity`] }}
                                </p>
                            </div>

                            <!-- Notes -->
                            <div class="min-w-[150px] flex-1">
                                <label :class="fieldLabel">Notes</label>
                                <input
                                    v-model="item.notes"
                                    type="text"
                                    :class="fieldInput"
                                    placeholder="Item notes..."
                                />
                            </div>

                            <!-- Remove button -->
                            <div class="pt-7">
                                <button
                                    type="button"
                                    @click="removeItem(index)"
                                    :disabled="form.items.length <= 1"
                                    class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-danger disabled:cursor-not-allowed disabled:opacity-30"
                                >
                                    <Trash2 :size="16" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Summary -->
                    <div v-if="hasValidItems" class="mt-4 rounded-lg border border-border-subtle bg-surface-canvas p-4">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-text-secondary">
                                Total items: {{ form.items.filter(i => i.product_id).length }} product(s)
                            </span>
                            <span class="text-sm font-medium text-text-secondary">
                                Total quantity: {{ totalItems }} unit(s)
                            </span>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Actions -->
            <div class="flex justify-end gap-3">
                <Button variant="secondary" as="Link" :href="route('stock-transfers.index')">Cancel</Button>
                <Button
                    type="submit"
                    variant="default"
                    :loading="form.processing"
                    :disabled="form.processing || !form.from_location_id || !form.to_location_id || !hasValidItems"
                >
                    {{ form.processing ? 'Creating...' : 'Create Transfer' }}
                </Button>
            </div>
        </form>
    </AppLayout>
</template>

