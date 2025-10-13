<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    user: Object,
    roles: Array,
});

const form = useForm({
    name: props.user.name,
    email: props.user.email,
    password: '',
    password_confirmation: '',
    role: props.user.role,
    role_ids: props.user.roles ? props.user.roles.map(r => r.id) : [],
});

const submit = () => {
    form.put(route('users.update', props.user.id));
};
</script>

<template>
    <Head :title="`Edit ${user.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-2xl text-gray-100">Edit User: {{ user.name }}</h2>
                <Link
                    :href="route('users.index')"
                    class="px-4 py-2 bg-dark-bg hover:bg-dark-bg/80 text-gray-300 font-medium rounded-lg transition border border-dark-border"
                >
                    Back to Users
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-dark-card shadow-sm sm:rounded-lg border border-dark-border overflow-hidden">
                    <form @submit.prevent="submit" class="p-6 space-y-6">
                        <!-- Name -->
                        <div>
                            <InputLabel for="name" value="Name" />
                            <TextInput
                                id="name"
                                v-model="form.name"
                                type="text"
                                class="mt-1 block w-full"
                                required
                                autofocus
                            />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>

                        <!-- Email -->
                        <div>
                            <InputLabel for="email" value="Email" />
                            <TextInput
                                id="email"
                                v-model="form.email"
                                type="email"
                                class="mt-1 block w-full"
                                required
                            />
                            <InputError class="mt-2" :message="form.errors.email" />
                        </div>

                        <!-- Password -->
                        <div>
                            <InputLabel for="password" value="New Password (leave blank to keep current)" />
                            <TextInput
                                id="password"
                                v-model="form.password"
                                type="password"
                                class="mt-1 block w-full"
                                autocomplete="new-password"
                            />
                            <InputError class="mt-2" :message="form.errors.password" />
                            <p class="mt-1 text-xs text-gray-500">
                                Only fill this in if you want to change the user's password
                            </p>
                        </div>

                        <!-- Password Confirmation -->
                        <div v-if="form.password">
                            <InputLabel for="password_confirmation" value="Confirm New Password" />
                            <TextInput
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                type="password"
                                class="mt-1 block w-full"
                                autocomplete="new-password"
                            />
                            <InputError class="mt-2" :message="form.errors.password_confirmation" />
                        </div>

                        <!-- Base Role -->
                        <div>
                            <InputLabel for="role" value="Base Role" />
                            <select
                                id="role"
                                v-model="form.role"
                                class="mt-1 block w-full border-gray-600 bg-dark-bg text-gray-100 focus:border-primary-500 focus:ring-primary-500 rounded-md shadow-sm"
                                required
                            >
                                <option value="">Select a role</option>
                                <option value="admin">Admin</option>
                                <option value="manager">Manager</option>
                                <option value="member">Member</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.role" />
                            <p class="mt-1 text-xs text-gray-500">
                                The base role determines core access level. Admins have full access.
                            </p>
                        </div>

                        <!-- Additional Custom Roles -->
                        <div v-if="roles && roles.length > 0">
                            <InputLabel value="Additional Custom Roles (Optional)" />
                            <p class="mt-1 text-sm text-gray-500 mb-4">
                                Assign custom roles created for your organization to grant specific permissions.
                            </p>

                            <div class="space-y-2 max-h-64 overflow-y-auto border border-dark-border rounded-lg p-4 bg-dark-bg/30">
                                <label
                                    v-for="role in roles"
                                    :key="role.id"
                                    class="flex items-start space-x-3 cursor-pointer hover:bg-dark-bg/50 p-2 rounded transition"
                                >
                                    <input
                                        type="checkbox"
                                        :value="role.id"
                                        v-model="form.role_ids"
                                        class="mt-1 rounded border-gray-600 text-primary-600 shadow-sm focus:ring-primary-500 bg-dark-bg"
                                    />
                                    <div class="flex-1">
                                        <div class="text-sm font-medium text-gray-300">{{ role.name }}</div>
                                        <div class="text-xs text-gray-500" v-if="role.description">{{ role.description }}</div>
                                        <div class="text-xs text-gray-600 mt-1">
                                            {{ role.permissions ? role.permissions.length : 0 }} permissions
                                        </div>
                                    </div>
                                </label>
                            </div>

                            <InputError class="mt-2" :message="form.errors.role_ids" />

                            <div class="mt-3 p-3 bg-primary-500/10 border border-primary-500/30 rounded-md">
                                <p class="text-sm text-primary-400">
                                    <strong>{{ form.role_ids.length }}</strong> custom role(s) selected
                                </p>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-4 pt-4 border-t border-dark-border">
                            <PrimaryButton :disabled="form.processing">
                                Update User
                            </PrimaryButton>

                            <Link
                                :href="route('users.index')"
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
                                    User updated successfully.
                                </p>
                            </Transition>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
