<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePermissions } from '@/composables/usePermissions';
import { Plus, Search, Eye, Pencil, Trash2, Warehouse } from '@lucide/vue';

const { t } = useI18n();
const { hasPermission } = usePermissions();

const props = defineProps({
    warehouses: Object,
    filters: Object,
});

const search = ref(props.filters?.search || '');

const searchWarehouses = () => {
    router.get(route('warehouses.index'), {
        search: search.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    searchWarehouses();
};

const deleteWarehouse = (warehouse) => {
    if (confirm(`Are you sure you want to delete "${warehouse.name}"? This action cannot be undone.`)) {
        router.delete(route('warehouses.destroy', warehouse.id));
    }
};

const thClass =
    'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
</script>

<template>
    <Head title="Warehouses" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">Warehouses</span>
            </div>
        </template>

        <PageHeader title="Warehouses" description="Your storage locations and stock distribution.">
            <template #actions>
                <Button v-if="hasPermission('create_warehouses')" variant="default" size="sm" as="Link" :href="route('warehouses.create')">
                    <Plus :size="14" />
                    Add Warehouse
                </Button>
            </template>
        </PageHeader>

        <!-- Filters -->
        <Card class="mt-6">
            <form @submit.prevent="searchWarehouses" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div class="md:col-span-2">
                        <label for="search" class="mb-1 block text-xs font-medium text-text-secondary">Search Warehouses</label>
                        <div class="relative">
                            <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                            <input
                                id="search"
                                v-model="search"
                                type="text"
                                placeholder="Search warehouses by name, code, or city..."
                                class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            />
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="submit" variant="default" size="sm">
                        <Search :size="14" />
                        Search
                    </Button>
                    <Button type="button" variant="secondary" size="sm" @click="clearFilters">Clear</Button>
                </div>
            </form>
        </Card>

        <!-- Warehouses table -->
        <div class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">Name</th>
                        <th :class="thClass">Code</th>
                        <th :class="thClass">City / Province</th>
                        <th :class="thClass">Locations</th>
                        <th :class="thClass">Status</th>
                        <th :class="thClass">Default</th>
                        <th :class="[thClass, 'text-right']">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="warehouses.data.length === 0">
                        <td colspan="7" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <Warehouse :size="22" class="text-text-tertiary" />
                                <p class="text-sm font-medium text-text-primary">No warehouses found</p>
                                <p class="text-sm text-text-tertiary">Get started by creating your first warehouse.</p>
                                <Button v-if="hasPermission('create_warehouses')" variant="default" size="sm" as="Link" :href="route('warehouses.create')">
                                    <Plus :size="14" />
                                    Add Warehouse
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-for="warehouse in warehouses.data" :key="warehouse.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                        <td class="px-4 py-3 text-text-primary">
                            <div class="font-medium text-text-primary">{{ warehouse.name }}</div>
                            <div v-if="warehouse.description" class="max-w-xs truncate text-xs text-text-tertiary">{{ warehouse.description }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded bg-surface-overlay px-2 py-0.5 font-mono text-xs text-text-secondary">{{ warehouse.code }}</span>
                        </td>
                        <td class="px-4 py-3 text-text-primary">
                            <span class="text-text-secondary">
                                <template v-if="warehouse.city || warehouse.province">
                                    {{ [warehouse.city, warehouse.province].filter(Boolean).join(', ') }}
                                </template>
                                <template v-else>-</template>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-text-primary">
                            <span class="tabular-nums text-text-secondary">{{ warehouse.locations_count || 0 }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="warehouse.is_active ? 'success' : 'neutral'" size="sm">
                                {{ warehouse.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <Badge v-if="warehouse.is_default" variant="info" size="sm">Default</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Link :href="route('warehouses.show', warehouse.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" title="View"><Eye :size="16" /></Link>
                                <Link v-if="hasPermission('edit_warehouses')" :href="route('warehouses.edit', warehouse.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-success" title="Edit"><Pencil :size="16" /></Link>
                                <button
                                    v-if="hasPermission('delete_warehouses')"
                                    @click="deleteWarehouse(warehouse)"
                                    class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-danger"
                                    title="Delete"
                                    :disabled="warehouse.is_default"
                                    :class="{ 'cursor-not-allowed opacity-50': warehouse.is_default }"
                                >
                                    <Trash2 :size="16" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="warehouses.data.length > 0" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-xs text-text-tertiary">
                Showing <span class="font-medium text-text-secondary">{{ warehouses.from }}</span>
                to <span class="font-medium text-text-secondary">{{ warehouses.to }}</span>
                of <span class="font-medium text-text-secondary">{{ warehouses.total }}</span> results
            </p>
            <nav class="inline-flex items-center gap-1">
                <template v-for="link in warehouses.links" :key="link.label">
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

