<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Search, Eye, ClipboardList } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    audits: Object,
    filters: Object,
    statuses: Object,
    auditTypes: Object,
});

const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || '');
const selectedType = ref(props.filters?.audit_type || '');

const applyFilters = () => {
    router.get(route('stock-audits.index'), {
        search: search.value,
        status: selectedStatus.value,
        audit_type: selectedType.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    selectedStatus.value = '';
    selectedType.value = '';
    applyFilters();
};

const statusVariant = (status) =>
    ({ draft: 'neutral', in_progress: 'info', completed: 'success', cancelled: 'danger' }[status] || 'neutral');

const typeVariant = (type) =>
    ({ full: 'brand', cycle: 'info', spot: 'warning' }[type] || 'brand');

const formatDate = (dateStr) => {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const selectClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
const thClass =
    'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head title="Stock Audits" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">Stock Audits</span>
            </div>
        </template>

        <PageHeader title="Stock Audits" description="Manage stock audits and cycle counts.">
            <template #actions>
                <Button variant="default" size="sm" as="Link" :href="route('stock-audits.create')">
                    <Plus :size="14" />
                    New Audit
                </Button>
            </template>
        </PageHeader>

        <!-- Filters -->
        <Card class="mt-6">
            <form @submit.prevent="applyFilters" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div class="md:col-span-2">
                        <label for="search" class="mb-1 block text-xs font-medium text-text-secondary">Search</label>
                        <div class="relative">
                            <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                            <input
                                id="search"
                                v-model="search"
                                type="text"
                                placeholder="Search by audit number or name..."
                                class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            />
                        </div>
                    </div>
                    <div>
                        <label for="status" class="mb-1 block text-xs font-medium text-text-secondary">Status</label>
                        <select id="status" v-model="selectedStatus" :class="selectClass">
                            <option value="">All Statuses</option>
                            <option v-for="(label, value) in statuses" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="audit_type" class="mb-1 block text-xs font-medium text-text-secondary">Audit Type</label>
                        <select id="audit_type" v-model="selectedType" :class="selectClass">
                            <option value="">All Types</option>
                            <option v-for="(label, value) in auditTypes" :key="value" :value="value">{{ label }}</option>
                        </select>
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

        <!-- Audits table -->
        <div class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">Audit #</th>
                        <th :class="thClass">Name</th>
                        <th :class="thClass">Type</th>
                        <th :class="thClass">Status</th>
                        <th :class="thClass">Location</th>
                        <th :class="[thClass, 'text-right']">Items</th>
                        <th :class="thClass">Created By</th>
                        <th :class="thClass">Date</th>
                        <th :class="[thClass, 'text-right']">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!audits.data || audits.data.length === 0">
                        <td colspan="9" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <ClipboardList :size="22" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">No stock audits found</p>
                                <Button variant="default" size="sm" as="Link" :href="route('stock-audits.create')">
                                    <Plus :size="14" />
                                    Create First Audit
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-for="audit in audits.data" :key="audit.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                        <td class="px-4 py-3 font-medium text-text-primary">
                            {{ audit.audit_number }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-text-primary">{{ audit.name }}</div>
                            <div v-if="audit.description" class="max-w-xs truncate text-xs text-text-tertiary">{{ audit.description }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="typeVariant(audit.audit_type)" size="sm">
                                {{ auditTypes[audit.audit_type] || audit.audit_type }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="statusVariant(audit.status)" size="sm" dot>
                                {{ statuses[audit.status] || audit.status }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3 text-text-secondary">
                            {{ audit.warehouse_location?.name || 'All Locations' }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-text-secondary">
                            {{ audit.items_count || 0 }}
                        </td>
                        <td class="px-4 py-3 text-text-secondary">
                            {{ audit.creator?.name || '-' }}
                        </td>
                        <td class="px-4 py-3 text-text-secondary">
                            {{ formatDate(audit.created_at) }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Link :href="route('stock-audits.show', audit.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" title="View"><Eye :size="16" /></Link>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="audits.data && audits.data.length > 0 && audits.links && audits.links.length > 3" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-xs text-text-tertiary">
                {{ t('common.showing') }} <span class="font-medium text-text-secondary">{{ audits.from }}</span>
                {{ t('common.to') }} <span class="font-medium text-text-secondary">{{ audits.to }}</span>
                {{ t('common.of') }} <span class="font-medium text-text-secondary">{{ audits.total }}</span> {{ t('common.results') }}
            </p>
            <nav class="inline-flex items-center gap-1">
                <template v-for="link in audits.links" :key="link.label">
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
