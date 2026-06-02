<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Search, Eye, Pencil, Trash2, Users } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    customers: Object,
    filters: Object,
});

const search = ref(props.filters?.search || '');

const searchCustomers = () => {
    router.get(route('customers.index'), {
        search: search.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    searchCustomers();
};

const deleteCustomer = (customer) => {
    if (confirm(t('products.confirmDelete', { name: customer.name }))) {
        router.delete(route('customers.destroy', customer.id));
    }
};

const thClass =
    'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
</script>

<template>
    <Head :title="t('customers.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('customers.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('customers.title')" description="Your customers and the orders they place.">
            <template #actions>
                <Button variant="default" size="sm" as="Link" :href="route('customers.create')">
                    <Plus :size="14" />
                    {{ t('customers.addCustomer') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Filters -->
        <Card class="mt-6">
            <form @submit.prevent="searchCustomers" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div class="md:col-span-2">
                        <label for="search" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('common.search') }} {{ t('customers.title') }}</label>
                        <div class="relative">
                            <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                            <input
                                id="search"
                                v-model="search"
                                type="text"
                                :placeholder="t('customers.searchPlaceholder')"
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

        <!-- Customers table -->
        <div class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">{{ t('common.name') }}</th>
                        <th :class="thClass">{{ t('customers.company') }}</th>
                        <th :class="thClass">{{ t('common.email') }}</th>
                        <th :class="thClass">{{ t('common.phone') }}</th>
                        <th :class="thClass">{{ t('nav.orders') }}</th>
                        <th :class="thClass">{{ t('common.status') }}</th>
                        <th :class="[thClass, 'text-right']">{{ t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="customers.data.length === 0">
                        <td colspan="7" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <Users :size="22" class="text-text-tertiary" />
                                <p class="text-sm font-medium text-text-primary">{{ t('customers.noCustomersFound') }}</p>
                                <p class="text-sm text-text-tertiary">{{ t('customers.getStarted') }}</p>
                                <Button variant="default" size="sm" as="Link" :href="route('customers.create')">
                                    <Plus :size="14" />
                                    {{ t('customers.addCustomer') }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-for="customer in customers.data" :key="customer.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                        <td class="px-4 py-3 text-text-primary">
                            <div class="font-medium text-text-primary">{{ customer.name }}</div>
                            <div v-if="customer.code" class="text-xs text-text-tertiary">{{ customer.code }}</div>
                        </td>
                        <td class="px-4 py-3 text-text-primary">
                            <span class="text-text-secondary">{{ customer.company_name || '-' }}</span>
                        </td>
                        <td class="px-4 py-3 text-text-primary">
                            <a v-if="customer.email" :href="`mailto:${customer.email}`" class="text-brand hover:underline">{{ customer.email }}</a>
                            <span v-else class="text-text-secondary">-</span>
                        </td>
                        <td class="px-4 py-3 text-text-primary">
                            <span class="text-text-secondary">{{ customer.phone || '-' }}</span>
                        </td>
                        <td class="px-4 py-3 text-text-primary">
                            <span class="tabular-nums text-text-secondary">{{ customer.orders_count || 0 }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="customer.is_active ? 'success' : 'neutral'" size="sm">
                                {{ customer.is_active ? t('common.active') : t('common.inactive') }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Link :href="route('customers.show', customer.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" :title="t('common.view')"><Eye :size="16" /></Link>
                                <Link :href="route('customers.edit', customer.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-success" :title="t('common.edit')"><Pencil :size="16" /></Link>
                                <button @click="deleteCustomer(customer)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-danger" :title="t('common.delete')"><Trash2 :size="16" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="customers.data.length > 0" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-xs text-text-tertiary">
                {{ t('common.showingResults', { from: customers.from, to: customers.to, total: customers.total }) }}
            </p>
            <nav class="inline-flex items-center gap-1">
                <template v-for="link in customers.links" :key="link.label">
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
    </AppLayout>
</template>
