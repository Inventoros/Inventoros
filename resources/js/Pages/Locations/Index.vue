<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    locations: Object,
    filters: Object,
});

const search = ref(props.filters?.search || '');
const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingLocation = ref(null);
const locationForm = ref({ name: '', code: '', description: '' });

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
    locationForm.value = { name: '', code: '', description: '' };
    showCreateModal.value = true;
};

const openEditModal = (location) => {
    editingLocation.value = location;
    locationForm.value = {
        name: location.name,
        code: location.code,
        description: location.description || '',
    };
    showEditModal.value = true;
};

const createLocation = () => {
    router.post(route('locations.store'), locationForm.value, {
        onSuccess: () => {
            showCreateModal.value = false;
            locationForm.value = { name: '', code: '', description: '' };
        },
    });
};

const updateLocation = () => {
    router.put(route('locations.update', editingLocation.value.id), locationForm.value, {
        onSuccess: () => {
            showEditModal.value = false;
            editingLocation.value = null;
            locationForm.value = { name: '', code: '', description: '' };
        },
    });
};

const deleteLocation = (location) => {
    if (confirm(`Are you sure you want to delete "${location.name}"? This action cannot be undone.`)) {
        router.delete(route('locations.destroy', location.id));
    }
};
</script>

<template>
    <Head title="Locations" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-100 leading-tight">
                    Warehouse Locations
                </h2>
                <button
                    @click="openCreateModal"
                    class="inline-flex items-center px-4 py-2 bg-primary-400 hover:bg-primary-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-primary-400 focus:ring-offset-2 focus:ring-offset-gray-900 transition ease-in-out duration-150"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Add Location
                </button>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Search -->
                <div class="mb-6 bg-dark-card border border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <div class="p-6">
                        <form @submit.prevent="searchLocations" class="flex gap-4">
                            <div class="flex-1">
                                <input
                                    v-model="search"
                                    type="text"
                                    placeholder="Search locations by name or code..."
                                    class="block w-full rounded-md bg-dark-bg border-dark-border text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                />
                            </div>
                            <button
                                type="submit"
                                class="inline-flex items-center px-4 py-2 bg-primary-400 hover:bg-primary-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest"
                            >
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                Search
                            </button>
                            <button
                                type="button"
                                @click="clearFilters"
                                class="inline-flex items-center px-4 py-2 bg-dark-bg border border-dark-border rounded-md font-semibold text-xs text-gray-300 uppercase tracking-widest hover:bg-dark-bg/50"
                            >
                                Clear
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Locations Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div
                        v-for="location in locations.data"
                        :key="location.id"
                        class="bg-dark-card border border-dark-border overflow-hidden shadow-lg sm:rounded-lg hover:shadow-xl transition-shadow"
                    >
                        <div class="p-6">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <h3 class="text-lg font-semibold text-gray-100">
                                            {{ location.name }}
                                        </h3>
                                        <span class="px-2 py-0.5 text-xs font-mono rounded bg-dark-bg text-gray-300">
                                            {{ location.code }}
                                        </span>
                                    </div>
                                    <p v-if="location.description" class="text-sm text-gray-400">
                                        {{ location.description }}
                                    </p>
                                </div>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-orange-900/30 text-orange-300">
                                    {{ location.products_count }} products
                                </span>
                            </div>

                            <div class="flex items-center gap-2 mt-4 pt-4 border-t border-dark-border">
                                <button
                                    @click="openEditModal(location)"
                                    class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-primary-400/20 text-primary-400 rounded-md hover:bg-primary-400/30 text-sm font-medium"
                                >
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                    Edit
                                </button>
                                <button
                                    @click="deleteLocation(location)"
                                    class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 rounded-md hover:bg-red-100 dark:hover:bg-red-900/30 text-sm font-medium"
                                    :disabled="location.products_count > 0"
                                    :class="{ 'opacity-50 cursor-not-allowed': location.products_count > 0 }"
                                >
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div v-if="locations.data.length === 0" class="col-span-full">
                        <div class="bg-dark-card border border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                            <div class="p-12 text-center">
                                <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <p class="text-gray-400 mb-4">No locations found</p>
                                <button
                                    @click="openCreateModal"
                                    class="inline-flex items-center px-4 py-2 bg-primary-400 hover:bg-primary-500 text-white text-sm font-semibold rounded-lg transition"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                    </svg>
                                    Create Your First Location
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="locations.data.length > 0" class="mt-6 bg-dark-card border border-dark-border px-4 py-3 rounded-lg shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-300">
                                Showing
                                <span class="font-medium">{{ locations.from }}</span>
                                to
                                <span class="font-medium">{{ locations.to }}</span>
                                of
                                <span class="font-medium">{{ locations.total }}</span>
                                results
                            </p>
                        </div>
                        <div>
                            <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                <template v-for="link in locations.links" :key="link.label">
                                    <Link
                                        v-if="link.url"
                                        :href="link.url"
                                        :class="[
                                            'relative inline-flex items-center px-4 py-2 border text-sm font-medium',
                                            link.active
                                                ? 'z-10 bg-primary-400/20 border-primary-400 text-primary-400'
                                                : 'bg-dark-card border-dark-border text-gray-400 hover:bg-dark-bg/50'
                                        ]"
                                        v-html="link.label"
                                    />
                                    <span
                                        v-else
                                        :class="[
                                            'relative inline-flex items-center px-4 py-2 border text-sm font-medium',
                                            'bg-dark-card border-dark-border text-gray-600 opacity-50 cursor-not-allowed'
                                        ]"
                                        v-html="link.label"
                                    />
                                </template>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Location Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 overflow-y-auto" @click="showCreateModal = false">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>

                <div class="relative bg-dark-card border border-dark-border rounded-lg shadow-xl max-w-md w-full p-6" @click.stop>
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-100">
                            Create Location
                        </h3>
                        <button
                            @click="showCreateModal = false"
                            class="text-gray-400 hover:text-gray-200"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="createLocation" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">
                                Location Name <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="locationForm.name"
                                type="text"
                                class="block w-full rounded-md bg-dark-bg border-dark-border text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                placeholder="e.g., Warehouse A"
                                required
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">
                                Location Code <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="locationForm.code"
                                type="text"
                                class="block w-full rounded-md bg-dark-bg border-dark-border text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                placeholder="e.g., WH-A"
                                required
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">
                                Description
                            </label>
                            <textarea
                                v-model="locationForm.description"
                                rows="3"
                                class="block w-full rounded-md bg-dark-bg border-dark-border text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                placeholder="Optional description..."
                            ></textarea>
                        </div>

                        <div class="flex gap-3 justify-end mt-6">
                            <button
                                type="button"
                                @click="showCreateModal = false"
                                class="px-4 py-2 bg-dark-bg border border-dark-border text-gray-300 rounded-md hover:bg-dark-bg/50"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="px-4 py-2 bg-primary-400 hover:bg-primary-500 text-white rounded-md"
                            >
                                Create Location
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Location Modal -->
        <div v-if="showEditModal" class="fixed inset-0 z-50 overflow-y-auto" @click="showEditModal = false">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>

                <div class="relative bg-dark-card border border-dark-border rounded-lg shadow-xl max-w-md w-full p-6" @click.stop>
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-100">
                            Edit Location
                        </h3>
                        <button
                            @click="showEditModal = false"
                            class="text-gray-400 hover:text-gray-200"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="updateLocation" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">
                                Location Name <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="locationForm.name"
                                type="text"
                                class="block w-full rounded-md bg-dark-bg border-dark-border text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                required
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">
                                Location Code <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="locationForm.code"
                                type="text"
                                class="block w-full rounded-md bg-dark-bg border-dark-border text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                required
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">
                                Description
                            </label>
                            <textarea
                                v-model="locationForm.description"
                                rows="3"
                                class="block w-full rounded-md bg-dark-bg border-dark-border text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                            ></textarea>
                        </div>

                        <div class="flex gap-3 justify-end mt-6">
                            <button
                                type="button"
                                @click="showEditModal = false"
                                class="px-4 py-2 bg-dark-bg border border-dark-border text-gray-300 rounded-md hover:bg-dark-bg/50"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="px-4 py-2 bg-primary-400 hover:bg-primary-500 text-white rounded-md"
                            >
                                Update Location
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
