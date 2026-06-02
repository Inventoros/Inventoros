<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { usePermissions } from '@/composables/usePermissions';
import { Pencil, ArrowLeft, MapPin, Boxes, Users, Trash2 } from 'lucide-vue-next';

const { t } = useI18n();
const { hasPermission } = usePermissions();

const props = defineProps({
    warehouse: Object,
    locations: Array,
    assignedUsers: Array,
    stats: Object,
});

const deleteWarehouse = () => {
    if (confirm(`Are you sure you want to delete "${props.warehouse.name}"? This action cannot be undone.`)) {
        router.delete(route('warehouses.destroy', props.warehouse.id));
    }
};

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="warehouse.name" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('warehouses.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('warehouses.index')" class="text-text-tertiary hover:text-text-primary">Warehouses</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ warehouse.name }}</span>
            </div>
        </template>

        <PageHeader :title="warehouse.name" :description="`Code: ${warehouse.code}`">
            <template #actions>
                <Badge :variant="warehouse.is_active ? 'success' : 'neutral'" size="sm" dot>
                    {{ warehouse.is_active ? 'Active' : 'Inactive' }}
                </Badge>
                <Badge v-if="warehouse.is_default" variant="info" size="sm">
                    Default Warehouse
                </Badge>
                <Button
                    v-if="hasPermission('edit_warehouses')"
                    variant="default"
                    size="sm"
                    as="Link"
                    :href="route('warehouses.edit', warehouse.id)"
                >
                    <Pencil :size="14" />
                    Edit
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('warehouses.index')">
                    <ArrowLeft :size="14" />
                    Back
                </Button>
            </template>
        </PageHeader>

        <!-- Key metrics -->
        <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <StatTile
                label="Total Locations"
                :value="stats?.locations_count || 0"
                icon-tone="brand"
            >
                <template #icon><MapPin :size="18" /></template>
            </StatTile>
            <StatTile
                label="Total Products Stored"
                :value="stats?.products_count || 0"
                icon-tone="success"
            >
                <template #icon><Boxes :size="18" /></template>
            </StatTile>
            <StatTile
                label="Assigned Users"
                :value="assignedUsers?.length || 0"
                icon-tone="violet"
            >
                <template #icon><Users :size="18" /></template>
            </StatTile>
        </section>

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Main Info -->
            <div class="space-y-4 lg:col-span-2">
                <!-- Warehouse Details -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Warehouse Details</h3></div>
                    <div class="p-5">
                        <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-text-tertiary">Manager</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ warehouse.manager_name || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">Email</dt>
                                <dd class="mt-1 text-sm text-text-primary">
                                    <a v-if="warehouse.email" :href="`mailto:${warehouse.email}`" class="text-brand hover:underline">
                                        {{ warehouse.email }}
                                    </a>
                                    <span v-else>-</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">Phone</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ warehouse.phone || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">Timezone</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ warehouse.timezone || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">Currency</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ warehouse.currency || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">Priority</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ warehouse.priority ?? 0 }}</dd>
                            </div>
                            <div v-if="warehouse.description" class="sm:col-span-2">
                                <dt class="text-xs text-text-tertiary">Description</dt>
                                <dd class="mt-1 whitespace-pre-wrap text-sm text-text-primary">{{ warehouse.description }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Address -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Address</h3></div>
                    <div class="p-5">
                        <p class="text-sm text-text-primary">
                            <template v-if="warehouse.address_line_1 || warehouse.city || warehouse.province || warehouse.postal_code || warehouse.country">
                                <span v-if="warehouse.address_line_1">{{ warehouse.address_line_1 }}<br></span>
                                <span v-if="warehouse.address_line_2">{{ warehouse.address_line_2 }}<br></span>
                                <span v-if="warehouse.city">{{ warehouse.city }}, </span>
                                <span v-if="warehouse.province">{{ warehouse.province }} </span>
                                <span v-if="warehouse.postal_code">{{ warehouse.postal_code }}<br></span>
                                <span v-if="warehouse.country">{{ warehouse.country }}</span>
                            </template>
                            <template v-else>
                                <span class="text-text-tertiary">No address provided</span>
                            </template>
                        </p>
                    </div>
                </Card>

                <!-- Locations -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Locations ({{ locations?.length || 0 }})</h3></div>
                    <div class="p-5">
                        <div v-if="locations && locations.length > 0" class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-border-subtle">
                                        <th :class="thClass">Name</th>
                                        <th :class="thClass">Code</th>
                                        <th :class="thClass">Products</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="location in locations"
                                        :key="location.id"
                                        class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                                    >
                                        <td class="px-4 py-3 text-sm font-medium text-text-primary">
                                            {{ location.name }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="rounded bg-surface-canvas px-2 py-0.5 font-mono text-xs text-text-secondary">
                                                {{ location.code }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm tabular-nums text-text-secondary">
                                            {{ location.products_count || 0 }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
                            <MapPin :size="22" class="text-text-tertiary" />
                            <p class="text-sm text-text-tertiary">No locations in this warehouse</p>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Sidebar -->
            <div class="space-y-4">
                <!-- Assigned Users -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Assigned Users ({{ assignedUsers?.length || 0 }})</h3></div>
                    <div class="p-5">
                        <div v-if="assignedUsers && assignedUsers.length > 0" class="space-y-3">
                            <div
                                v-for="user in assignedUsers"
                                :key="user.id"
                                class="flex items-center gap-3 border-b border-border-subtle py-2 last:border-0"
                            >
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-soft">
                                    <span class="text-xs font-medium text-brand">{{ user.name.charAt(0).toUpperCase() }}</span>
                                </div>
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium text-text-primary">{{ user.name }}</div>
                                    <div class="truncate text-xs text-text-tertiary">{{ user.email }}</div>
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-sm text-text-tertiary">No users assigned to this warehouse.</p>
                    </div>
                </Card>

                <!-- Actions -->
                <Card v-if="hasPermission('edit_warehouses') || (hasPermission('delete_warehouses') && !warehouse.is_default)" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Actions</h3></div>
                    <div class="space-y-3 p-5">
                        <Button
                            v-if="hasPermission('edit_warehouses')"
                            variant="default"
                            class="w-full"
                            as="Link"
                            :href="route('warehouses.edit', warehouse.id)"
                        >
                            <Pencil :size="16" />
                            Edit Warehouse
                        </Button>
                    </div>
                </Card>

                <!-- Danger Zone -->
                <Card v-if="hasPermission('delete_warehouses') && !warehouse.is_default" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Danger Zone</h3></div>
                    <div class="p-5">
                        <Button variant="danger" class="w-full" @click="deleteWarehouse">
                            <Trash2 :size="16" />
                            Delete Warehouse
                        </Button>
                        <p class="mt-2 text-xs text-text-tertiary">
                            This action cannot be undone.
                        </p>
                    </div>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
