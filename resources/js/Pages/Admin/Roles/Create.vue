<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    permissions: Object,
});

const form = useForm({
    name: '',
    description: '',
    permissions: [],
});

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
                <h2 class="font-semibold text-2xl text-gray-100">Create Role</h2>
                <Link
                    :href="route('roles.index')"
                    class="px-4 py-2 bg-dark-bg hover:bg-dark-bg/80 text-gray-300 font-medium rounded-lg transition border border-dark-border"
                >
                    Back to Roles
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-dark-card shadow-sm sm:rounded-lg border border-dark-border overflow-hidden">
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
                                class="mt-1 block w-full border-gray-600 bg-dark-bg text-gray-100 focus:border-primary-500 focus:ring-primary-500 rounded-md shadow-sm"
                                placeholder="Describe the purpose and responsibilities of this role..."
                            ></textarea>
                            <InputError class="mt-2" :message="form.errors.description" />
                        </div>

                        <!-- Permissions -->
                        <div>
                            <InputLabel value="Permissions" />
                            <p class="mt-1 text-sm text-gray-500 mb-4">
                                Select the permissions this role should have. You can click category names to select/deselect all permissions in that category.
                            </p>

                            <div class="space-y-4">
                                <div
                                    v-for="(perms, category) in permissions"
                                    :key="category"
                                    class="border border-dark-border rounded-lg p-4 bg-dark-bg/30"
                                >
                                    <!-- Category Header -->
                                    <div class="flex items-center justify-between mb-3 pb-2 border-b border-dark-border">
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
                                            class="flex items-start space-x-3 cursor-pointer hover:bg-dark-bg/50 p-2 rounded transition"
                                        >
                                            <input
                                                type="checkbox"
                                                :value="permission.value"
                                                v-model="form.permissions"
                                                class="mt-1 rounded border-gray-600 text-primary-600 shadow-sm focus:ring-primary-500 bg-dark-bg"
                                            />
                                            <div class="flex-1">
                                                <div class="text-sm font-medium text-gray-300">{{ permission.label }}</div>
                                                <div class="text-xs text-gray-500">{{ permission.description }}</div>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <InputError class="mt-2" :message="form.errors.permissions" />

                            <div class="mt-4 p-3 bg-primary-500/10 border border-primary-500/30 rounded-md">
                                <p class="text-sm text-primary-400">
                                    <strong>{{ form.permissions.length }}</strong> permission(s) selected
                                </p>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-4 pt-4 border-t border-dark-border">
                            <PrimaryButton :disabled="form.processing">
                                Create Role
                            </PrimaryButton>

                            <Link
                                :href="route('roles.index')"
                                class="text-sm text-gray-400 hover:text-gray-300 transition"
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
