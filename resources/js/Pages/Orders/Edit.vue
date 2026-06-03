<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Plus, Trash2, PackageOpen } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    order: Object,
    products: Array,
});

const form = useForm({
    customer_name: props.order.customer_name,
    customer_email: props.order.customer_email,
    customer_address: props.order.customer_address,
    status: props.order.status,
    order_date: props.order.order_date ? props.order.order_date.split('T')[0] : '',
    shipping: props.order.shipping || 0,
    tax: props.order.tax || 0,
    notes: props.order.notes || '',
    items: props.order.items.map(item => ({
        id: item.id,
        product_id: item.product_id,
        quantity: item.quantity,
        unit_price: parseFloat(item.unit_price),
    })),
});

const submit = () => {
    form.put(route('orders.update', props.order.id), {
        preserveScroll: true,
    });
};

const subtotal = computed(() => {
    return form.items.reduce((sum, item) => {
        return sum + (parseFloat(item.quantity || 0) * parseFloat(item.unit_price || 0));
    }, 0);
});

const total = computed(() => {
    return subtotal.value + parseFloat(form.tax || 0) + parseFloat(form.shipping || 0);
});

const addItem = () => {
    form.items.push({
        id: null,
        product_id: '',
        quantity: 1,
        unit_price: 0,
    });
};

const removeItem = (index) => {
    form.items.splice(index, 1);
};

const updateItemPrice = (index) => {
    const item = form.items[index];
    if (item.product_id) {
        const product = props.products.find(p => p.id === item.product_id);
        if (product) {
            item.unit_price = parseFloat(product.price);
        }
    }
};

const getProductStock = (productId) => {
    const product = props.products.find(p => p.id === productId);
    return product ? product.stock : 0;
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('orders.edit.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('orders.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('orders.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('orders.edit.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('orders.edit.title')" :description="`Order #${order.order_number}`">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('orders.index')">
                    <ArrowLeft :size="14" />
                    {{ t('orders.edit.backToOrders') }}
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6">
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <!-- Left column -->
                <div class="space-y-4 lg:col-span-2">
                    <!-- Customer info -->
                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.create.customerInfo') }}</h3></div>
                        <div class="space-y-4 p-5">
                            <div>
                                <label for="customer_name" :class="fieldLabel">{{ t('orders.create.customerName') }}</label>
                                <input id="customer_name" v-model="form.customer_name" type="text" :class="fieldInput" required />
                                <p v-if="form.errors.customer_name" :class="fieldError">{{ form.errors.customer_name }}</p>
                            </div>
                            <div>
                                <label for="customer_email" :class="fieldLabel">{{ t('orders.create.customerEmail') }}</label>
                                <input id="customer_email" v-model="form.customer_email" type="email" :class="fieldInput" />
                                <p v-if="form.errors.customer_email" :class="fieldError">{{ form.errors.customer_email }}</p>
                            </div>
                            <div>
                                <label for="customer_address" :class="fieldLabel">{{ t('orders.create.shippingAddress') }}</label>
                                <textarea id="customer_address" v-model="form.customer_address" rows="3" :class="fieldArea"></textarea>
                                <p v-if="form.errors.customer_address" :class="fieldError">{{ form.errors.customer_address }}</p>
                            </div>
                        </div>
                    </Card>

                    <!-- Order items (editable) -->
                    <Card :padded="false">
                        <div class="flex items-center justify-between px-5 pt-5">
                            <h3 class="text-sm font-semibold text-text-primary">{{ t('orders.create.orderItems') }}</h3>
                            <Button type="button" variant="default" size="sm" @click="addItem">
                                <Plus :size="14" />{{ t('orders.edit.addItem') }}
                            </Button>
                        </div>
                        <div class="p-5">
                            <p v-if="form.errors.items" :class="fieldError">{{ form.errors.items }}</p>

                            <div v-if="form.items.length === 0" class="flex flex-col items-center gap-3 py-8 text-center">
                                <PackageOpen :size="22" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">{{ t('orders.edit.noItems') }}</p>
                                <Button type="button" variant="default" size="sm" @click="addItem">
                                    <Plus :size="14" />{{ t('orders.edit.addFirstItem') }}
                                </Button>
                            </div>

                            <div v-else class="space-y-3">
                                <div
                                    v-for="(item, index) in form.items"
                                    :key="index"
                                    class="rounded-lg border border-border-subtle bg-surface-canvas p-4"
                                >
                                    <div class="grid grid-cols-1 gap-3 md:grid-cols-12">
                                        <!-- Product selection -->
                                        <div class="md:col-span-5">
                                            <label :for="`product-${index}`" :class="fieldLabel">{{ t('common.product') }}</label>
                                            <select
                                                :id="`product-${index}`"
                                                v-model="item.product_id"
                                                @change="updateItemPrice(index)"
                                                :class="fieldInput"
                                                required
                                            >
                                                <option value="">{{ t('orders.edit.selectProduct') }}</option>
                                                <option v-for="product in products" :key="product.id" :value="product.id">
                                                    {{ product.name }} ({{ product.sku }}) - Stock: {{ product.stock }}
                                                </option>
                                            </select>
                                            <p v-if="form.errors[`items.${index}.product_id`]" :class="fieldError">
                                                {{ form.errors[`items.${index}.product_id`] }}
                                            </p>
                                        </div>

                                        <!-- Quantity -->
                                        <div class="md:col-span-2">
                                            <label :for="`quantity-${index}`" :class="fieldLabel">{{ t('orders.edit.qty') }}</label>
                                            <input
                                                :id="`quantity-${index}`"
                                                v-model.number="item.quantity"
                                                type="number"
                                                min="1"
                                                step="1"
                                                :class="fieldInput"
                                                required
                                            />
                                            <p v-if="form.errors[`items.${index}.quantity`]" :class="fieldError">
                                                {{ form.errors[`items.${index}.quantity`] }}
                                            </p>
                                        </div>

                                        <!-- Unit price -->
                                        <div class="md:col-span-2">
                                            <label :for="`price-${index}`" :class="fieldLabel">{{ t('orders.edit.unitPrice') }}</label>
                                            <input
                                                :id="`price-${index}`"
                                                v-model.number="item.unit_price"
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                :class="fieldInput"
                                                required
                                            />
                                            <p v-if="form.errors[`items.${index}.unit_price`]" :class="fieldError">
                                                {{ form.errors[`items.${index}.unit_price`] }}
                                            </p>
                                        </div>

                                        <!-- Subtotal -->
                                        <div class="md:col-span-2">
                                            <label :class="fieldLabel">{{ t('common.subtotal') }}</label>
                                            <div class="flex h-9 items-center rounded-md border border-border-subtle bg-surface-sunken px-3">
                                                <span class="text-sm font-semibold tabular-nums text-text-primary">
                                                    ${{ ((item.quantity || 0) * (item.unit_price || 0)).toFixed(2) }}
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Remove button -->
                                        <div class="flex items-end md:col-span-1">
                                            <button
                                                type="button"
                                                @click="removeItem(index)"
                                                class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-danger"
                                                title="Remove item"
                                            >
                                                <Trash2 :size="16" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Card>
                </div>

                <!-- Right column -->
                <div class="space-y-4">
                    <!-- Order details -->
                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.create.orderDetails') }}</h3></div>
                        <div class="space-y-4 p-5">
                            <div>
                                <label for="order_date" :class="fieldLabel">{{ t('orders.create.orderDate') }}</label>
                                <input id="order_date" v-model="form.order_date" type="date" :class="fieldInput" required />
                                <p v-if="form.errors.order_date" :class="fieldError">{{ form.errors.order_date }}</p>
                            </div>
                            <div>
                                <label for="status" :class="fieldLabel">{{ t('orders.create.statusLabel') }}</label>
                                <select id="status" v-model="form.status" :class="fieldInput" required>
                                    <option value="pending">{{ t('orders.status.pending') }}</option>
                                    <option value="processing">{{ t('orders.status.processing') }}</option>
                                    <option value="shipped">{{ t('orders.status.shipped') }}</option>
                                    <option value="delivered">{{ t('orders.status.delivered') }}</option>
                                    <option value="cancelled">{{ t('orders.status.cancelled') }}</option>
                                </select>
                                <p v-if="form.errors.status" :class="fieldError">{{ form.errors.status }}</p>
                            </div>

                            <div v-if="order.shipped_at" class="text-sm">
                                <p class="text-text-tertiary">{{ t('orders.edit.shippedAt') }}</p>
                                <p class="font-medium text-text-primary">{{ new Date(order.shipped_at).toLocaleString() }}</p>
                            </div>

                            <div v-if="order.delivered_at" class="text-sm">
                                <p class="text-text-tertiary">{{ t('orders.edit.deliveredAt') }}</p>
                                <p class="font-medium text-text-primary">{{ new Date(order.delivered_at).toLocaleString() }}</p>
                            </div>

                            <div>
                                <label for="notes" :class="fieldLabel">{{ t('common.notes') }}</label>
                                <textarea id="notes" v-model="form.notes" rows="3" :class="fieldArea" :placeholder="t('orders.create.notesPlaceholder')"></textarea>
                                <p v-if="form.errors.notes" :class="fieldError">{{ form.errors.notes }}</p>
                            </div>
                        </div>
                    </Card>

                    <!-- Order summary -->
                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.create.orderSummary') }}</h3></div>
                        <div class="space-y-3 p-5">
                            <div class="flex justify-between text-sm">
                                <span class="text-text-secondary">{{ t('common.subtotal') }}</span>
                                <span class="font-medium tabular-nums text-text-primary">${{ subtotal.toFixed(2) }}</span>
                            </div>
                            <div>
                                <label for="tax" class="mb-1 block text-sm text-text-secondary">{{ t('common.tax') }}</label>
                                <input id="tax" v-model.number="form.tax" type="number" step="0.01" min="0" :class="fieldInput" />
                                <p v-if="form.errors.tax" :class="fieldError">{{ form.errors.tax }}</p>
                            </div>
                            <div>
                                <label for="shipping" class="mb-1 block text-sm text-text-secondary">{{ t('common.shipping') }}</label>
                                <input id="shipping" v-model.number="form.shipping" type="number" step="0.01" min="0" :class="fieldInput" />
                                <p v-if="form.errors.shipping" :class="fieldError">{{ form.errors.shipping }}</p>
                            </div>
                            <div class="flex items-center justify-between border-t border-border-subtle pt-3">
                                <span class="text-sm font-semibold text-text-primary">{{ t('common.total') }}</span>
                                <span class="text-xl font-bold text-brand">${{ total.toFixed(2) }}</span>
                            </div>
                        </div>
                    </Card>

                    <!-- Form actions -->
                    <div class="flex flex-col gap-2">
                        <Button type="submit" variant="default" size="lg" class="w-full" :loading="form.processing" :disabled="form.processing">
                            {{ form.processing ? t('orders.edit.updatingOrder') : t('orders.edit.updateOrder') }}
                        </Button>
                        <Button variant="secondary" size="lg" class="w-full" as="Link" :href="route('orders.show', order.id)">{{ t('orders.edit.viewDetails') }}</Button>
                        <Button variant="secondary" size="lg" class="w-full" as="Link" :href="route('orders.index')">{{ t('common.cancel') }}</Button>
                    </div>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
