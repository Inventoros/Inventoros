<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    permissions: Object,
    permissionSets: Array,
});

const form = useForm({
    name: '',
    description: '',
    permissions: [],
    permission_set_ids: [],
});

// Toggle a permission set
const togglePermissionSet = (setId) => {
    const index = form.permission_set_ids.indexOf(setId);
    if (index > -1) {
        form.permission_set_ids.splice(index, 1);
    } else {
        form.permission_set_ids.push(setId);
    }
};

// Get permissions from selected sets
const getSetPermissions = () => {
    const setPerms = [];
    props.permissionSets
        .filter(set => form.permission_set_ids.includes(set.id))
        .forEach(set => setPerms.push(...set.permissions));
    return [...new Set(setPerms)];
};

// Get total unique permissions count
const getTotalPermissions = () => {
    const direct = form.permissions;
    const fromSets = getSetPermissions();
    return [...new Set([...direct, ...fromSets])].length;
};

// Category icons
const getCategoryIcon = (category) => {
    const icons = {
        inventory: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
        orders: 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z',
        purchasing: 'M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4',
        admin: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
        reports: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
    };
    return icons[category] || icons.admin;
};

const submit = () => {
    form.post(route('roles.store'), {
        onSuccess: () => form.reset(),
    });
};

const toggleCategory = (category) => {
    const categoryPermissions = props.permissions[category].map(p => p.value);
    const allSelected = categoryPermissions.every(p => form.permissions.includes(p));

    if (allSelected) {
        // Remove all from this category
        form.permissions = form.permissions.filter(p => !categoryPermissions.includes(p));
    } else {
        // Add all from this category
        const newPermissions = [...new Set([...form.permissions, ...categoryPermissions])];
        form.permissions = newPermissions;
    }
};

const isCategorySelected = (category) => {
    const categoryPermissions = props.permissions[category].map(p => p.value);
    return categoryPermissions.every(p => form.permissions.includes(p));
};
</script>

<template>
    <Head title="Create Role" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Create Role</h2>
                <Link
                    :href="route('roles.index')"
                    class="px-4 py-2 bg-dark-bg hover:bg-gray-100 dark:hover:bg-dark-bg/80 text-gray-600 dark:text-gray-300 font-medium rounded-lg transition border border-gray-200 dark:border-dark-border"
                >
                    Back to Roles
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-dark-card shadow-sm sm:rounded-lg border border-gray-200 dark:border-dark-border overflow-hidden">
                    <form @submit.prevent="submit" class="p-6 space-y-6">
                        <!-- Name -->
                        <div>
                            <InputLabel for="name" value="Role Name" />
                            <TextInput
                                id="name"
                                v-model="form.name"
                                type="text"
                                class="mt-1 block w-full"
                                required
                                autofocus
                                placeholder="e.g., Warehouse Manager"
                            />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>

                        <!-- Description -->
                        <div>
                            <InputLabel for="description" value="Description (Optional)" />
                            <textarea
                                id="description"
                                v-model="form.description"
                                rows="3"
                                class="mt-1 block w-full border-gray-600 bg-gray-50 dark:bg-dark-bg text-gray-900 dark:text-gray-100 focus:border-primary-500 focus:ring-primary-500 rounded-md shadow-sm"
                                placeholder="Describe the purpose and responsibilities of this role..."
                            ></textarea>
                            <InputError class="mt-2" :message="form.errors.description" />
                        </div>

                        <!-- Permission Sets (Quick Templates) -->
                        <div v-if="permissionSets && permissionSets.length > 0">
                            <InputLabel value="Permission Sets (Quick Templates)" />
                            <p class="mt-1 text-sm text-gray-500 mb-4">
                                Apply pre-configured permission sets to quickly assign common permission groups.
                            </p>

                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                <button
                                    v-for="set in permissionSets"
                                    :key="set.id"
                                    type="button"
                                    @click="togglePermissionSet(set.id)"
                                    :class="[
                                        'p-4 rounded-lg border-2 text-left transition',
                                        form.permission_set_ids.includes(set.id)
                                            ? 'border-primary-400 bg-primary-400/10'
                                            : 'border-gray-200 dark:border-dark-border hover:border-primary-400/50'
                                    ]"
                                >
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0">
                                            <svg class="w-6 h-6 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="getCategoryIcon(set.category)" />
                                            </svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="font-medium text-gray-900 dark:text-gray-100">{{ set.name }}</div>
                                            <div class="text-xs text-gray-500 mt-1">{{ set.description }}</div>
                                            <div class="flex items-center gap-2 mt-2">
                                                <span class="text-xs px-2 py-0.5 bg-gray-200 dark:bg-dark-bg rounded-full text-gray-600 dark:text-gray-400">
                                                    {{ set.permission_count }} permissions
                                                </span>
                                                <span v-if="set.is_template" class="text-xs px-2 py-0.5 bg-blue-500/20 text-blue-400 rounded-full">
                                                    Template
                                                </span>
                                            </div>
                                        </div>
                                        <div v-if="form.permission_set_ids.includes(set.id)" class="flex-shrink-0">
                                            <svg class="w-5 h-5 text-primary-400" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                    </div>
                                </button>
                            </div>
                        </div>

                        <!-- Individual Permissions -->
                        <div>
                            <InputLabel value="Individual Permissions" />
                            <p class="mt-1 text-sm text-gray-500 mb-4">
                                Select additional permissions. You can click category names to select/deselect all permissions in that category.
                            </p>

                            <div class="space-y-4">
                                <div
                                    v-for="(perms, category) in permissions"
                                    :key="category"
                                    class="border border-gray-200 dark:border-dark-border rounded-lg p-4 bg-gray-50 dark:bg-dark-bg/30"
                                >
                                    <!-- Category Header -->
                                    <div class="flex items-center justify-between mb-3 pb-2 border-b border-gray-200 dark:border-dark-border">
                                        <h4 class="font-semibold text-gray-200">{{ category }}</h4>
                                        <button
                                            type="button"
                                            @click="toggleCategory(category)"
                                            class="text-xs px-3 py-1 rounded-md transition"
                                            :class="isCategorySelected(category)
                                                ? 'bg-primary-500/20 text-primary-400 hover:bg-primary-500/30'
                                                : 'bg-dark-bg text-gray-400 hover:bg-dark-bg/80'"
                                        >
                                            {{ isCategorySelected(category) ? 'Deselect All' : 'Select All' }}
                                        </button>
                                    </div>

                                    <!-- Permissions in Category -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <label
                                            v-for="permission in perms"
                                            :key="permission.value"
                                            class="flex items-start space-x-3 cursor-pointer hover:bg-gray-100 dark:hover:bg-dark-bg/50 p-2 rounded transition"
                                        >
                                            <input
                                                type="checkbox"
                                                :value="permission.value"
                                                v-model="form.permissions"
                                                class="mt-1 rounded border-gray-600 text-primary-600 shadow-sm focus:ring-primary-500 bg-gray-50 dark:bg-dark-bg"
                                            />
                                            <div class="flex-1">
                                                <div class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ permission.label }}</div>
                                                <div class="text-xs text-gray-500">{{ permission.description }}</div>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <InputError class="mt-2" :message="form.errors.permissions" />

                            <div class="mt-4 p-3 bg-primary-500/10 border border-primary-500/30 rounded-md">
                                <p class="text-sm text-primary-400">
                                    <strong>{{ getTotalPermissions() }}</strong> total permission(s)
                                    <span v-if="form.permission_set_ids.length > 0" class="text-gray-500">
                                        ({{ form.permissions.length }} direct + {{ getSetPermissions().length }} from {{ form.permission_set_ids.length }} set(s))
                                    </span>
                                </p>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-4 pt-4 border-t border-gray-200 dark:border-dark-border">
                            <PrimaryButton :disabled="form.processing">
                                Create Role
                            </PrimaryButton>

                            <Link
                                :href="route('roles.index')"
                                class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-600 dark:text-gray-300 transition"
                            >
                                Cancel
                            </Link>

                            <Transition
                                enter-active-class="transition ease-in-out"
                                enter-from-class="opacity-0"
                                leave-active-class="transition ease-in-out"
                                leave-to-class="opacity-0"
                            >
                                <p v-if="form.recentlySuccessful" class="text-sm text-green-400">
                                    Role created successfully.
                                </p>
                            </Transition>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
