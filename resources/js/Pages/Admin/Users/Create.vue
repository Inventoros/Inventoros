<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    roles: Array,
});

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'member',
    role_ids: [],
});

const submit = () => {
    form.post(route('users.store'), {
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Create User" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Create User</h2>
                <Link
                    :href="route('users.index')"
                    class="px-4 py-2 bg-dark-bg hover:bg-gray-100 dark:hover:bg-dark-bg/80 text-gray-600 dark:text-gray-300 font-medium rounded-lg transition border border-gray-200 dark:border-dark-border"
                >
                    Back to Users
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-dark-card shadow-sm sm:rounded-lg border border-gray-200 dark:border-dark-border overflow-hidden">
                    <form @submit.prevent="submit" class="p-6 space-y-6">
                        <!-- Name -->
                        <div>
                            <InputLabel for="name" value="Full Name" />
                            <TextInput
                                id="name"
                                v-model="form.name"
                                type="text"
                                class="mt-1 block w-full"
                                required
                                autofocus
                                autocomplete="name"
                            />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>

                        <!-- Email -->
                        <div>
                            <InputLabel for="email" value="Email Address" />
                            <TextInput
                                id="email"
                                v-model="form.email"
                                type="email"
                                class="mt-1 block w-full"
                                required
                                autocomplete="username"
                            />
                            <InputError class="mt-2" :message="form.errors.email" />
                        </div>

                        <!-- Password -->
                        <div>
                            <InputLabel for="password" value="Password" />
                            <TextInput
                                id="password"
                                v-model="form.password"
                                type="password"
                                class="mt-1 block w-full"
                                required
                                autocomplete="new-password"
                            />
                            <InputError class="mt-2" :message="form.errors.password" />
                            <p class="mt-1 text-sm text-gray-500">Minimum 8 characters</p>
                        </div>

                        <!-- Confirm Password -->
                        <div>
                            <InputLabel for="password_confirmation" value="Confirm Password" />
                            <TextInput
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                type="password"
                                class="mt-1 block w-full"
                                required
                                autocomplete="new-password"
                            />
                            <InputError class="mt-2" :message="form.errors.password_confirmation" />
                        </div>

                        <!-- Role -->
                        <div>
                            <InputLabel for="role" value="Base Role" />
                            <select
                                id="role"
                                v-model="form.role"
                                class="mt-1 block w-full border-gray-600 bg-gray-50 dark:bg-dark-bg text-gray-900 dark:text-gray-100 focus:border-primary-500 focus:ring-primary-500 rounded-md shadow-sm"
                                required
                            >
                                <option value="member">Member</option>
                                <option value="manager">Manager</option>
                                <option value="admin">Administrator</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.role" />
                            <p class="mt-1 text-sm text-gray-500">
                                Base organizational role. Admins have access to all features.
                            </p>
                        </div>

                        <!-- Additional Roles (if any custom roles exist) -->
                        <div v-if="roles && roles.length > 0">
                            <InputLabel value="Additional Roles (Optional)" />
                            <div class="mt-2 space-y-2 max-h-48 overflow-y-auto border border-gray-200 dark:border-dark-border rounded-md p-4 bg-gray-50 dark:bg-dark-bg/50">
                                <label
                                    v-for="role in roles"
                                    :key="role.id"
                                    class="flex items-center space-x-3 cursor-pointer hover:bg-gray-100 dark:hover:bg-dark-bg/50 p-2 rounded"
                                >
                                    <input
                                        type="checkbox"
                                        :value="role.id"
                                        v-model="form.role_ids"
                                        class="rounded border-gray-600 text-primary-600 shadow-sm focus:ring-primary-500 bg-gray-50 dark:bg-dark-bg"
                                    />
                                    <div class="flex-1">
                                        <div class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ role.name }}</div>
                                        <div class="text-xs text-gray-500" v-if="role.description">{{ role.description }}</div>
                                    </div>
                                    <span class="text-xs text-gray-500">
                                        {{ role.permissions ? role.permissions.length : 0 }} permissions
                                    </span>
                                </label>
                            </div>
                            <p class="mt-1 text-sm text-gray-500">
                                Assign custom roles with specific permission sets.
                            </p>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-4 pt-4 border-t border-gray-200 dark:border-dark-border">
                            <PrimaryButton :disabled="form.processing">
                                Create User
                            </PrimaryButton>

                            <Link
                                :href="route('users.index')"
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
                                    User created successfully.
                                </p>
                            </Transition>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
