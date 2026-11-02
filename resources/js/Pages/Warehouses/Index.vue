<script setup>
import PluginSlot from '@/Components/PluginSlot.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TableFilters from '@/Components/ui/TableFilters.vue';
import TableViewport from '@/Components/ui/TableViewport.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePermissions } from '@/composables/usePermissions';
import { Plus, Eye, Pencil, Trash2, Warehouse, Star } from '@lucide/vue';

const { t } = useI18n();
const { hasPermission } = usePermissions();

const props = defineProps({
    pluginComponents: Object,
    warehouses: Object,
    filters: Object,
    restrictToAssigned: { type: Boolean, default: false },
});

const updateAccessPolicy = (event) => {
    router.post(route('warehouses.access-policy'), {
        restrict_to_assigned: event.target.checked,
    }, {
        preserveScroll: true,
    });
};

const search = ref(props.filters?.search || '');

const searchWarehouses = () => {
    router.get(route('warehouses.index'), {
        search: search.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const makeDefault = (warehouse) => {
    if (confirm(t('warehouses.default.confirm', { name: warehouse.name }))) {
        router.post(route('warehouses.set-default', warehouse.id), {}, { preserveScroll: true });
    }
};

const deleteWarehouse = (warehouse) => {
    if (confirm(t('warehouses.confirmDelete', { name: warehouse.name }))) {
        router.delete(route('warehouses.destroy', warehouse.id));
    }
};

const thClass =
    'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
</script>

<template>
    <Head :title="t('warehouses.title')" />

    <AppLayout table-page>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('nav.warehouses') }}</span>
            </div>
        </template>

        <PageHeader :title="t('warehouses.title')" :description="t('warehouses.subtitle')">
            <template #actions>
                <Button v-if="hasPermission('create_warehouses')" variant="default" size="sm" as="Link" :href="route('warehouses.create')">
                    <Plus :size="14" />
                    {{ t('warehouses.addWarehouse') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <!-- Filters -->
        <TableFilters class="mt-4" :label="t('warehouses.access.title')" :enabled="hasPermission('manage_warehouse_users')">
            <template #quick>
                <form class="table-quick-search" @submit.prevent="searchWarehouses">
                    <label for="search" class="sr-only">{{ t('warehouses.index.searchLabel') }}</label>
                    <input id="search" v-model="search" type="text" :placeholder="t('warehouses.searchPlaceholder')" />
                    <button type="submit" class="ds-focus-ring">{{ t('common.search') }}</button>
                </form>
            </template>
            <label class="flex items-start gap-3">
                <input type="checkbox" :checked="restrictToAssigned" @change="updateAccessPolicy" class="mt-0.5 rounded border-border-subtle bg-surface-canvas text-brand ds-focus-ring" />
                <span>
                    <span class="block text-sm font-medium text-text-primary">{{ t('warehouses.access.restrictLabel') }}</span>
                    <span class="mt-1 block text-xs text-text-tertiary">{{ t('warehouses.access.restrictHint') }}</span>
                </span>
            </label>
        </TableFilters>

        <!-- Warehouses table -->
        <TableViewport class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">{{ t('warehouses.columns.name') }}</th>
                        <th :class="thClass">{{ t('warehouses.columns.code') }}</th>
                        <th :class="thClass">{{ t('warehouses.columns.cityProvince') }}</th>
                        <th :class="thClass">{{ t('warehouses.columns.locations') }}</th>
                        <th :class="thClass">{{ t('warehouses.columns.status') }}</th>
                        <th :class="thClass">{{ t('warehouses.columns.default') }}</th>
                        <th :class="[thClass, 'text-right']">{{ t('warehouses.columns.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="warehouses.data.length === 0">
                        <td colspan="7" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <Warehouse :size="22" class="text-text-tertiary" />
                                <p class="text-sm font-medium text-text-primary">{{ t('warehouses.noWarehouses') }}</p>
                                <p class="text-sm text-text-tertiary">{{ t('warehouses.noWarehousesHint') }}</p>
                                <Button v-if="hasPermission('create_warehouses')" variant="default" size="sm" as="Link" :href="route('warehouses.create')">
                                    <Plus :size="14" />
                                    {{ t('warehouses.addWarehouse') }}
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
                                {{ warehouse.is_active ? t('common.active') : t('common.inactive') }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <Badge v-if="warehouse.is_default" variant="info" size="sm">{{ t('warehouses.default.label') }}</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Link :href="route('warehouses.show', warehouse.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" :title="t('common.view')"><Eye :size="16" /></Link>
                                <Link v-if="hasPermission('edit_warehouses')" :href="route('warehouses.edit', warehouse.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-success" :title="t('common.edit')"><Pencil :size="16" /></Link>
                                <button
                                    v-if="hasPermission('edit_warehouses') && !warehouse.is_default"
                                    type="button"
                                    @click="makeDefault(warehouse)"
                                    class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand"
                                    :title="t('warehouses.default.makeDefault')"
                                    :aria-label="t('warehouses.default.makeDefault')"
                                >
                                    <Star :size="16" />
                                </button>
                                <button
                                    v-if="hasPermission('delete_warehouses')"
                                    @click="deleteWarehouse(warehouse)"
                                    class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-danger"
                                    :title="t('common.delete')"
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

            <template #footer>
                <div v-if="warehouses.data.length > 0" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row table-pagination">
                    <p class="text-xs text-text-tertiary">
                        {{ t('common.showingResults', { from: warehouses.from, to: warehouses.to, total: warehouses.total }) }}
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
            </template>
        </TableViewport>

        <!-- Pagination -->

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
    </AppLayout>
</template>
