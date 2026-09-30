<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Plus, Search, Pencil, Trash2, MapPin, X, QrCode } from 'lucide-vue-next';

import { useI18n } from 'vue-i18n';
const props = defineProps({
    locations: Object,
    warehouses: { type: Array, default: () => [] },
    filters: Object,
    pluginComponents: Object,
});


const { t } = useI18n();
const search = ref(props.filters?.search || '');
const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingLocation = ref(null);
// A lone warehouse (e.g. a user assigned to one) is preselected for new locations.
const blankLocation = () => ({
    name: '',
    code: '',
    description: '',
    warehouse_id: props.warehouses.length === 1 ? props.warehouses[0].id : null,
    capacity: null,
});
const locationForm = ref(blankLocation());
const formMode = ref('create');

const searchLocations = () => {
    router.get(route('locations.index'), {
        search: search.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    searchLocations();
};

const openCreateModal = () => {
    locationForm.value = blankLocation();
    formMode.value = 'create';
    showCreateModal.value = true;
};

const openEditModal = (location) => {
    editingLocation.value = location;
    locationForm.value = {
        name: location.name,
        code: location.code,
        description: location.description || '',
        warehouse_id: location.warehouse_id ?? null,
        capacity: location.capacity ?? null,
    };
    formMode.value = 'edit';
    showEditModal.value = true;
};

const createLocation = () => {
    router.post(route('locations.store'), locationForm.value, {
        onSuccess: () => {
            showCreateModal.value = false;
            locationForm.value = blankLocation();
        },
    });
};

const updateLocation = () => {
    router.put(route('locations.update', editingLocation.value.id), locationForm.value, {
        onSuccess: () => {
            showEditModal.value = false;
            editingLocation.value = null;
            locationForm.value = blankLocation();
        },
    });
};

const deleteLocation = (location) => {
    if (confirm(t('locations.confirmDelete', { name: location.name }))) {
        router.delete(route('locations.destroy', location.id));
    }
};

const inputClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const labelClass = 'mb-1 block text-xs font-medium text-text-secondary';
</script>

<template>
    <Head :title="t('nav.locations')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('nav.locations') }}</span>
            </div>
        </template>

        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <PageHeader :title="t('locations.title')" :description="t('locations.subtitle')">
            <template #actions>
                <Button v-if="locations.data.length > 0" variant="secondary" size="sm" as="a" :href="route('locations.qr.bulk-print')" target="_blank">
                    <QrCode :size="14" />
                    {{ t('locations.printAllQrLabels') }}
                </Button>
                <Button variant="default" size="sm" @click="openCreateModal">
                    <Plus :size="14" />
                    {{ t('locations.addLocation') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Filters -->
        <Card class="mt-6">
            <form @submit.prevent="searchLocations" class="space-y-4">
                <div>
                    <label for="search" :class="labelClass">{{ t('common.search') }}</label>
                    <div class="relative">
                        <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                        <input
                            id="search"
                            v-model="search"
                            type="text"
                            :placeholder="t('locations.searchPlaceholder')"
                            class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                        />
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

        <!-- Locations Grid -->
        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            <Card
                v-for="location in locations.data"
                :key="location.id"
                hoverable
            >
                <div class="mb-3 flex items-start justify-between">
                    <div class="flex-1">
                        <div class="mb-1 flex items-center gap-2">
                            <h3 class="text-base font-semibold text-text-primary">
                                {{ location.name }}
                            </h3>
                            <span class="rounded bg-surface-overlay px-2 py-0.5 font-mono text-xs text-text-secondary">
                                {{ location.code }}
                            </span>
                        </div>
                        <p v-if="location.warehouse" class="text-xs text-text-secondary">
                            {{ location.warehouse.name }}
                        </p>
                        <p v-if="location.description" class="text-sm text-text-tertiary">
                            {{ location.description }}
                        </p>
                        <p v-if="location.capacity" class="mt-1 text-xs text-text-tertiary">
                            {{ t('locations.capacityValue', { capacity: location.capacity }) }}
                        </p>
                    </div>
                    <Badge variant="warning" size="sm">{{ t('locations.productsCount', { count: location.products_count }, location.products_count) }}</Badge>
                </div>

                <div class="mt-4 flex items-center gap-1 border-t border-border-subtle pt-4">
                    <a
                        :href="route('locations.qr.print', location.id)"
                        target="_blank"
                        class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-text-primary ds-focus-ring"
                        :aria-label="t('locations.printQrLabel')"
                        :title="t('locations.printQrLabel')"
                    >
                        <QrCode :size="16" />
                    </a>
                    <button
                        @click="openEditModal(location)"
                        class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-success"
                        :aria-label="t('common.edit')"
                        :title="t('common.edit')"
                    >
                        <Pencil :size="16" />
                    </button>
                    <button
                        @click="deleteLocation(location)"
                        class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-danger disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="location.products_count > 0"
                        :aria-label="t('common.delete')"
                        :title="t('common.delete')"
                    >
                        <Trash2 :size="16" />
                    </button>
                </div>
            </Card>

            <!-- Empty State -->
            <div v-if="locations.data.length === 0" class="col-span-full">
                <Card>
                    <div class="flex flex-col items-center gap-3 py-12 text-center">
                        <MapPin :size="22" class="text-text-tertiary" />
                        <p class="text-sm text-text-tertiary">{{ t('locations.noLocationsFound') }}</p>
                        <Button variant="default" size="sm" @click="openCreateModal">
                            <Plus :size="14" />
                            {{ t('locations.createFirst') }}
                        </Button>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Pagination -->
        <div v-if="locations.data.length > 0" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-xs text-text-tertiary">
                {{ t('common.showingResults', { from: locations.from, to: locations.to, total: locations.total }) }}
            </p>
            <nav class="inline-flex items-center gap-1">
                <template v-for="link in locations.links" :key="link.label">
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

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />

        <!-- Create Location Modal -->
        <Teleport to="body">
            <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="fixed inset-0 bg-black/50" @click="showCreateModal = false"></div>
                <div class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">
                            {{ t('locations.createLocation') }}
                        </h3>
                        <button
                            @click="showCreateModal = false"
                            class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-text-primary"
                            :aria-label="t('locations.close')"
                        >
                            <X :size="18" />
                        </button>
                    </div>

                    <form @submit.prevent="createLocation" class="space-y-4">
                        <div>
                            <label :class="labelClass">
                                {{ t('locations.locationName') }} <span class="text-status-danger">*</span>
                            </label>
                            <input
                                v-model="locationForm.name"
                                type="text"
                                :class="inputClass"
                                :placeholder="t('locations.namePlaceholder')"
                                required
                            />
                        </div>

                        <div>
                            <label :class="labelClass">
                                {{ t('locations.locationCode') }} <span class="text-status-danger">*</span>
                            </label>
                            <input
                                v-model="locationForm.code"
                                type="text"
                                :class="inputClass"
                                :placeholder="t('locations.codePlaceholder')"
                                required
                            />
                        </div>

                        <div>
                            <label :class="labelClass">
                                {{ t('common.description') }}
                            </label>
                            <textarea
                                v-model="locationForm.description"
                                rows="3"
                                class="w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                                :placeholder="t('locations.descPlaceholder')"
                            ></textarea>
                        </div>

                        <div>
                            <label :for="`location-warehouse-${formMode}`" :class="labelClass">{{ t('locations.warehouse') }}</label>
                            <select :id="`location-warehouse-${formMode}`" v-model="locationForm.warehouse_id" :class="inputClass">
                                <option :value="null">{{ t('locations.noWarehouse') }}</option>
                                <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">
                                    {{ warehouse.name }}{{ warehouse.code ? ` (${warehouse.code})` : '' }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label :for="`location-capacity-${formMode}`" :class="labelClass">{{ t('locations.capacity') }}</label>
                            <input
                                :id="`location-capacity-${formMode}`"
                                v-model.number="locationForm.capacity"
                                type="number"
                                min="0"
                                :class="inputClass"
                            />
                            <p class="mt-1 text-xs text-text-tertiary">{{ t('locations.capacityHint') }}</p>
                        </div>

                        <div class="mt-6 flex justify-end gap-2">
                            <Button type="button" variant="secondary" size="sm" @click="showCreateModal = false">{{ t('common.cancel') }}</Button>
                            <Button type="submit" variant="default" size="sm">{{ t('locations.createLocation') }}</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Edit Location Modal -->
        <Teleport to="body">
            <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="fixed inset-0 bg-black/50" @click="showEditModal = false"></div>
                <div class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">
                            {{ t('locations.editLocation') }}
                        </h3>
                        <button
                            @click="showEditModal = false"
                            class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-text-primary"
                            :aria-label="t('locations.close')"
                        >
                            <X :size="18" />
                        </button>
                    </div>

                    <form @submit.prevent="updateLocation" class="space-y-4">
                        <div>
                            <label :class="labelClass">
                                {{ t('locations.locationName') }} <span class="text-status-danger">*</span>
                            </label>
                            <input
                                v-model="locationForm.name"
                                type="text"
                                :class="inputClass"
                                required
                            />
                        </div>

                        <div>
                            <label :class="labelClass">
                                {{ t('locations.locationCode') }} <span class="text-status-danger">*</span>
                            </label>
                            <input
                                v-model="locationForm.code"
                                type="text"
                                :class="inputClass"
                                required
                            />
                        </div>

                        <div>
                            <label :class="labelClass">
                                {{ t('common.description') }}
                            </label>
                            <textarea
                                v-model="locationForm.description"
                                rows="3"
                                class="w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            ></textarea>
                        </div>

                        <div>
                            <label :for="`location-warehouse-${formMode}`" :class="labelClass">{{ t('locations.warehouse') }}</label>
                            <select :id="`location-warehouse-${formMode}`" v-model="locationForm.warehouse_id" :class="inputClass">
                                <option :value="null">{{ t('locations.noWarehouse') }}</option>
                                <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">
                                    {{ warehouse.name }}{{ warehouse.code ? ` (${warehouse.code})` : '' }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label :for="`location-capacity-${formMode}`" :class="labelClass">{{ t('locations.capacity') }}</label>
                            <input
                                :id="`location-capacity-${formMode}`"
                                v-model.number="locationForm.capacity"
                                type="number"
                                min="0"
                                :class="inputClass"
                            />
                            <p class="mt-1 text-xs text-text-tertiary">{{ t('locations.capacityHint') }}</p>
                        </div>

                        <div class="mt-6 flex justify-end gap-2">
                            <Button type="button" variant="secondary" size="sm" @click="showEditModal = false">{{ t('common.cancel') }}</Button>
                            <Button type="submit" variant="default" size="sm">{{ t('locations.updateLocation') }}</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
