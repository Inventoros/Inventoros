<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Search, Eye, Pencil, Trash2, Truck } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    suppliers: Object,
    filters: Object,
    pluginComponents: Object,
});

const search = ref(props.filters?.search || '');

const searchSuppliers = () => {
    router.get(route('suppliers.index'), {
        search: search.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    searchSuppliers();
};

const deleteSupplier = (supplier) => {
    if (confirm(t('products.confirmDelete', { name: supplier.name }))) {
        router.delete(route('suppliers.destroy', supplier.id));
    }
};

const thClass =
    'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
</script>

<template>
    <Head :title="t('suppliers.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('suppliers.title') }}</span>
            </div>
        </template>

        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <PageHeader :title="t('suppliers.title')" description="Your vendors and the products they supply.">
            <template #actions>
                <Button variant="default" size="sm" as="Link" :href="route('suppliers.create')">
                    <Plus :size="14" />
                    {{ t('suppliers.addSupplier') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Filters -->
        <Card class="mt-6">
            <form @submit.prevent="searchSuppliers" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div class="md:col-span-2">
                        <label for="search" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('common.search') }} {{ t('suppliers.title') }}</label>
                        <div class="relative">
                            <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                            <input
                                id="search"
                                v-model="search"
                                type="text"
                                :placeholder="t('suppliers.searchPlaceholder')"
                                class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            />
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="submit" variant="default" size="sm">
                        <Search :size="14" />
                        {{ t('common.search') }}
                    </Button>
                    <Button type="button" variant="secondary" size="sm" @click="clearFilters">{{ t('common.clear') }}</Button>
                </div>
            </form>
        </Card>

        <PluginSlot slot="beforeTable" :components="pluginComponents?.beforeTable" />

        <!-- Suppliers table -->
        <div class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">{{ t('common.name') }}</th>
                        <th :class="thClass">{{ t('suppliers.contact') }}</th>
                        <th :class="thClass">{{ t('common.email') }}</th>
                        <th :class="thClass">{{ t('common.phone') }}</th>
                        <th :class="thClass">{{ t('common.products') }}</th>
                        <th :class="thClass">{{ t('common.status') }}</th>
                        <th :class="[thClass, 'text-right']">{{ t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="suppliers.data.length === 0">
                        <td colspan="7" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <Truck :size="22" class="text-text-tertiary" />
                                <p class="text-sm font-medium text-text-primary">{{ t('suppliers.noSuppliersFound') }}</p>
                                <p class="text-sm text-text-tertiary">{{ t('suppliers.getStarted') }}</p>
                                <Button variant="default" size="sm" as="Link" :href="route('suppliers.create')">
                                    <Plus :size="14" />
                                    {{ t('suppliers.addSupplier') }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-for="supplier in suppliers.data" :key="supplier.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                        <td class="px-4 py-3 text-text-primary">
                            <div class="font-medium text-text-primary">{{ supplier.name }}</div>
                            <div v-if="supplier.code" class="text-xs text-text-tertiary">{{ supplier.code }}</div>
                        </td>
                        <td class="px-4 py-3 text-text-primary">
                            <span class="text-text-secondary">{{ supplier.contact_name || '-' }}</span>
                        </td>
                        <td class="px-4 py-3 text-text-primary">
                            <a v-if="supplier.email" :href="`mailto:${supplier.email}`" class="text-brand hover:underline">{{ supplier.email }}</a>
                            <span v-else class="text-text-secondary">-</span>
                        </td>
                        <td class="px-4 py-3 text-text-primary">
                            <span class="text-text-secondary">{{ supplier.phone || '-' }}</span>
                        </td>
                        <td class="px-4 py-3 text-text-primary">
                            <span class="tabular-nums text-text-secondary">{{ supplier.products_count || 0 }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="supplier.is_active ? 'success' : 'neutral'" size="sm">
                                {{ supplier.is_active ? t('common.active') : t('common.inactive') }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Link :href="route('suppliers.show', supplier.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" :title="t('common.view')"><Eye :size="16" /></Link>
                                <Link :href="route('suppliers.edit', supplier.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-success" :title="t('common.edit')"><Pencil :size="16" /></Link>
                                <button @click="deleteSupplier(supplier)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-danger" :title="t('common.delete')"><Trash2 :size="16" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="suppliers.data.length > 0" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-xs text-text-tertiary">
                {{ t('common.showingResults', { from: suppliers.from, to: suppliers.to, total: suppliers.total }) }}
            </p>
            <nav class="inline-flex items-center gap-1">
                <template v-for="link in suppliers.links" :key="link.label">
                    <Link v-if="link.url" :href="link.url"
                        :class="[
                            'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-colors',
                            link.active ? 'border-brand bg-brand text-brand-foreground' : 'border-border-subtle bg-surface-canvas text-text-secondary hover:bg-surface-overlay',
                        ]"
                        v-html="link.label" />
                    <span v-else class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-2.5 text-xs text-text-tertiary opacity-50" v-html="link.label" />
                </template>
            </nav>
        </div>

        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
    </AppLayout>
</template>

