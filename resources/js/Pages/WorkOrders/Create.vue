<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, AlertTriangle } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    assemblyProducts: Array,
    warehouses: Array,
    preselectedProductId: [Number, String],
});

const form = useForm({
    product_id: props.preselectedProductId || '',
    quantity: 1,
    warehouse_id: '',
    notes: '',
});

const selectedProduct = computed(() => {
    if (!form.product_id) return null;
    return props.assemblyProducts.find(p => p.id == form.product_id);
});

const requiredComponents = computed(() => {
    if (!selectedProduct.value || !selectedProduct.value.components) return [];
    return selectedProduct.value.components.map(comp => ({
        ...comp,
        required_qty: comp.quantity * form.quantity,
        available: comp.component?.stock || 0,
        sufficient: (comp.component?.stock || 0) >= comp.quantity * form.quantity,
    }));
});

const allComponentsSufficient = computed(() => {
    return requiredComponents.value.length > 0 && requiredComponents.value.every(c => c.sufficient);
});

const submit = () => {
    form.post(route('work-orders.store'), {
        preserveScroll: true,
    });
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head title="Create Work Order" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('work-orders.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('work-orders.index')" class="text-text-tertiary hover:text-text-primary">Work Orders</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">New</span>
            </div>
        </template>

        <PageHeader title="Create Work Order" description="Start a new production work order for an assembly product">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('work-orders.index')">
                    <ArrowLeft :size="14" />
                    Back to Work Orders
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6">
            <Card :padded="false">
                <div class="space-y-6 p-5">
                    <!-- Assembly Product -->
                    <div>
                        <label for="product_id" :class="fieldLabel">
                            Assembly Product <span class="text-status-danger">*</span>
                        </label>
                        <select
                            id="product_id"
                            v-model="form.product_id"
                            :class="fieldInput"
                            required
                        >
                            <option value="">Select an assembly product...</option>
                            <option v-for="product in assemblyProducts" :key="product.id" :value="product.id">
                                {{ product.name }} ({{ product.sku }})
                            </option>
                        </select>
                        <p v-if="form.errors.product_id" :class="fieldError">
                            {{ form.errors.product_id }}
                        </p>
                    </div>

                    <!-- Quantity -->
                    <div>
                        <label for="quantity" :class="fieldLabel">
                            Quantity to Produce <span class="text-status-danger">*</span>
                        </label>
                        <input
                            id="quantity"
                            v-model.number="form.quantity"
                            type="number"
                            min="1"
                            :class="fieldInput"
                            required
                        />
                        <p v-if="form.errors.quantity" :class="fieldError">
                            {{ form.errors.quantity }}
                        </p>
                    </div>

                    <!-- Warehouse -->
                    <div v-if="warehouses && warehouses.length > 0">
                        <label for="warehouse_id" :class="fieldLabel">
                            Warehouse
                        </label>
                        <select
                            id="warehouse_id"
                            v-model="form.warehouse_id"
                            :class="fieldInput"
                        >
                            <option value="">No specific warehouse</option>
                            <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">
                                {{ warehouse.name }}
                            </option>
                        </select>
                        <p v-if="form.errors.warehouse_id" :class="fieldError">
                            {{ form.errors.warehouse_id }}
                        </p>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label for="notes" :class="fieldLabel">
                            Notes
                        </label>
                        <textarea
                            id="notes"
                            v-model="form.notes"
                            rows="3"
                            :class="fieldArea"
                            placeholder="Optional production notes..."
                        ></textarea>
                        <p v-if="form.errors.notes" :class="fieldError">
                            {{ form.errors.notes }}
                        </p>
                    </div>

                    <!-- Required Components Preview -->
                    <div v-if="selectedProduct && requiredComponents.length > 0" class="overflow-hidden rounded-lg border border-border-subtle">
                        <div class="border-b border-border-subtle bg-surface-canvas px-4 py-3">
                            <h3 class="text-sm font-semibold text-text-primary">
                                Required Components
                            </h3>
                            <p class="mt-0.5 text-xs text-text-tertiary">
                                Materials needed to produce {{ form.quantity }} unit{{ form.quantity !== 1 ? 's' : '' }} of {{ selectedProduct.name }}
                            </p>
                        </div>
                        <table class="min-w-full divide-y divide-border-subtle">
                            <thead class="bg-surface-canvas">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-text-tertiary">Component</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-text-tertiary">SKU</th>
                                    <th class="px-4 py-2 text-center text-xs font-medium uppercase tracking-wider text-text-tertiary">Required</th>
                                    <th class="px-4 py-2 text-center text-xs font-medium uppercase tracking-wider text-text-tertiary">Available</th>
                                    <th class="px-4 py-2 text-center text-xs font-medium uppercase tracking-wider text-text-tertiary">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-subtle">
                                <tr
                                    v-for="comp in requiredComponents"
                                    :key="comp.id"
                                    :class="!comp.sufficient ? 'bg-status-danger/10' : ''"
                                >
                                    <td class="px-4 py-3 text-sm text-text-primary">
                                        {{ comp.component?.name || 'Unknown' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-text-tertiary">
                                        {{ comp.component?.sku || '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-center text-sm font-medium text-text-primary">
                                        {{ comp.required_qty }}
                                    </td>
                                    <td class="px-4 py-3 text-center text-sm" :class="comp.sufficient ? 'text-status-success' : 'font-semibold text-status-danger'">
                                        {{ comp.available }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="comp.sufficient ? 'bg-status-success/15 text-status-success' : 'bg-status-danger/15 text-status-danger'"
                                        >
                                            {{ comp.sufficient ? 'OK' : 'Insufficient' }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Insufficient Stock Warning -->
                        <div v-if="!allComponentsSufficient" class="border-t border-border-subtle bg-status-danger/10 px-4 py-3">
                            <p class="flex items-center text-sm text-status-danger">
                                <AlertTriangle :size="16" class="mr-2 flex-shrink-0" />
                                Some components have insufficient stock. You can still create the work order, but production cannot begin until stock is replenished.
                            </p>
                        </div>
                    </div>

                    <!-- No components warning -->
                    <div v-if="selectedProduct && requiredComponents.length === 0" class="rounded-lg border border-status-warning/40 bg-status-warning/10 p-4">
                        <p class="flex items-center text-sm text-status-warning">
                            <AlertTriangle :size="16" class="mr-2" />
                            This assembly product has no components defined. Add components in the product detail page before creating a work order.
                        </p>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex items-center justify-end gap-3 border-t border-border-subtle px-5 py-4">
                    <Button variant="secondary" as="Link" :href="route('work-orders.index')">
                        Cancel
                    </Button>
                    <Button type="submit" variant="default" :loading="form.processing" :disabled="form.processing">
                        Create Work Order
                    </Button>
                </div>
            </Card>
        </form>
    </AppLayout>
</template>
