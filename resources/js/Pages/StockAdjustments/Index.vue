<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Search, Eye, ClipboardList } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    adjustments: Object,
    filters: Object,
    products: Array,
    users: Array,
    types: Object,
});

const search = ref(props.filters?.search || '');
const selectedType = ref(props.filters?.type || '');
const selectedProduct = ref(props.filters?.product_id || '');
const selectedUser = ref(props.filters?.user_id || '');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');

const applyFilters = () => {
    router.get(route('stock-adjustments.index'), {
        search: search.value,
        type: selectedType.value,
        product_id: selectedProduct.value,
        user_id: selectedUser.value,
        date_from: dateFrom.value,
        date_to: dateTo.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    selectedType.value = '';
    selectedProduct.value = '';
    selectedUser.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    applyFilters();
};

const adjustmentClass = (quantity) => {
    if (quantity > 0) return 'text-status-success';
    if (quantity < 0) return 'text-status-danger';
    return 'text-text-secondary';
};

const typeVariant = (type) =>
    ({
        manual: 'info',
        recount: 'brand',
        damage: 'danger',
        loss: 'warning',
        return: 'success',
        correction: 'warning',
        order: 'neutral',
    }[type] || 'info');

const selectClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
const thClass =
    'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
</script>

<template>
    <Head title="Stock Adjustments" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">Stock Adjustments</span>
            </div>
        </template>

        <PageHeader title="Stock Adjustments" description="Track all stock changes and adjustments.">
            <template #actions>
                <Button variant="default" size="sm" as="Link" :href="route('stock-adjustments.create')">
                    <Plus :size="14" />
                    New Adjustment
                </Button>
            </template>
        </PageHeader>

        <!-- Filters -->
        <Card class="mt-6">
            <form @submit.prevent="applyFilters" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label for="search" class="mb-1 block text-xs font-medium text-text-secondary">Search</label>
                        <div class="relative">
                            <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                            <input
                                id="search"
                                v-model="search"
                                type="text"
                                placeholder="Search by product name or SKU..."
                                class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            />
                        </div>
                    </div>

                    <div>
                        <label for="type" class="mb-1 block text-xs font-medium text-text-secondary">Type</label>
                        <select id="type" v-model="selectedType" :class="selectClass">
                            <option value="">All Types</option>
                            <option v-for="(label, value) in types" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>

                    <div>
                        <label for="product" class="mb-1 block text-xs font-medium text-text-secondary">Product</label>
                        <select id="product" v-model="selectedProduct" :class="selectClass">
                            <option value="">All Products</option>
                            <option v-for="product in products" :key="product.id" :value="product.id">
                                {{ product.name }} ({{ product.sku }})
                            </option>
                        </select>
                    </div>

                    <div>
                        <label for="user" class="mb-1 block text-xs font-medium text-text-secondary">User</label>
                        <select id="user" v-model="selectedUser" :class="selectClass">
                            <option value="">All Users</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                    </div>

                    <div>
                        <label for="date-from" class="mb-1 block text-xs font-medium text-text-secondary">Date From</label>
                        <input id="date-from" v-model="dateFrom" type="date" :class="selectClass" />
                    </div>

                    <div>
                        <label for="date-to" class="mb-1 block text-xs font-medium text-text-secondary">Date To</label>
                        <input id="date-to" v-model="dateTo" type="date" :class="selectClass" />
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="submit" variant="default" size="sm">
                        <Search :size="14" />
                        Apply Filters
                    </Button>
                    <Button type="button" variant="secondary" size="sm" @click="clearFilters">Clear Filters</Button>
                </div>
            </form>
        </Card>

        <!-- Adjustments table -->
        <div class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">Date</th>
                        <th :class="thClass">Product</th>
                        <th :class="thClass">Type</th>
                        <th :class="[thClass, 'text-right']">Before</th>
                        <th :class="[thClass, 'text-right']">Change</th>
                        <th :class="[thClass, 'text-right']">After</th>
                        <th :class="thClass">User</th>
                        <th :class="[thClass, 'text-right']">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!adjustments.data || adjustments.data.length === 0">
                        <td colspan="8" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <ClipboardList :size="22" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">No stock adjustments found</p>
                                <Button variant="default" size="sm" as="Link" :href="route('stock-adjustments.create')">
                                    <Plus :size="14" />
                                    Create First Adjustment
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr
                        v-for="adjustment in adjustments.data"
                        :key="adjustment.id"
                        class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                    >
                        <td class="px-4 py-3 whitespace-nowrap text-text-secondary">
                            {{ new Date(adjustment.created_at).toLocaleDateString() }}
                            <br />
                            <span class="text-xs text-text-tertiary">
                                {{ new Date(adjustment.created_at).toLocaleTimeString() }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-text-primary">{{ adjustment.product.name }}</div>
                            <div class="text-xs text-text-tertiary">SKU: {{ adjustment.product.sku }}</div>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <Badge :variant="typeVariant(adjustment.type)" size="sm">
                                {{ types[adjustment.type] || adjustment.type }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-right tabular-nums text-text-secondary">
                            {{ adjustment.quantity_before }}
                        </td>
                        <td :class="['px-4 py-3 whitespace-nowrap text-right font-semibold tabular-nums', adjustmentClass(adjustment.adjustment_quantity)]">
                            {{ adjustment.adjustment_quantity > 0 ? '+' : '' }}{{ adjustment.adjustment_quantity }}
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-right font-medium tabular-nums text-text-primary">
                            {{ adjustment.quantity_after }}
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-text-secondary">
                            {{ adjustment.user?.name || 'System' }}
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <div class="flex items-center justify-end gap-1">
                                <Link :href="route('stock-adjustments.show', adjustment.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" title="View"><Eye :size="16" /></Link>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="adjustments.data && adjustments.data.length > 0 && adjustments.links && adjustments.links.length > 3" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p v-if="adjustments.from" class="text-xs text-text-tertiary">
                {{ t('common.showing') }} <span class="font-medium text-text-secondary">{{ adjustments.from }}</span>
                {{ t('common.to') }} <span class="font-medium text-text-secondary">{{ adjustments.to }}</span>
                {{ t('common.of') }} <span class="font-medium text-text-secondary">{{ adjustments.total }}</span> {{ t('common.results') }}
            </p>
            <nav class="inline-flex items-center gap-1">
                <template v-for="link in adjustments.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-colors',
                            link.active
                                ? 'border-brand bg-brand text-brand-foreground'
                                : 'border-border-subtle bg-surface-canvas text-text-secondary hover:bg-surface-overlay',
                        ]"
                        v-html="link.label"
                    />
                    <span v-else class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-2.5 text-xs text-text-tertiary opacity-50" v-html="link.label" />
                </template>
            </nav>
        </div>
    </AppLayout>
</template>

