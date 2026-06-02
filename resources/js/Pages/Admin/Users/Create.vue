<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArrowLeft } from 'lucide-vue-next';

const props = defineProps({
    roles: Array,
});


const { t } = useI18n();
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

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('admin.users.create.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('users.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('users.index')" class="text-text-tertiary hover:text-text-primary">Users</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">New</span>
            </div>
        </template>

        <PageHeader :title="t('admin.createUser')" description="Create a new user account and assign roles.">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('users.index')">
                    <ArrowLeft :size="14" />
                    Back to Users
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6 max-w-3xl">
            <Card :padded="false">
                <div class="space-y-6 p-6">
                    <!-- Name -->
                    <div>
                        <label for="name" :class="fieldLabel">Full Name</label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            :class="fieldInput"
                            required
                            autofocus
                            autocomplete="name"
                        />
                        <p v-if="form.errors.name" :class="fieldError">{{ form.errors.name }}</p>
                    </div>

                    <!-- Email -->
                    <div>
                        <label for="email" :class="fieldLabel">Email Address</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            :class="fieldInput"
                            required
                            autocomplete="username"
                        />
                        <p v-if="form.errors.email" :class="fieldError">{{ form.errors.email }}</p>
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="password" :class="fieldLabel">Password</label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            :class="fieldInput"
                            required
                            autocomplete="new-password"
                        />
                        <p v-if="form.errors.password" :class="fieldError">{{ form.errors.password }}</p>
                        <p class="mt-1 text-sm text-text-tertiary">Minimum 8 characters</p>
                    </div>

                    <!-- Confirm Password -->
                    <div>
                        <label for="password_confirmation" :class="fieldLabel">Confirm Password</label>
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            :class="fieldInput"
                            required
                            autocomplete="new-password"
                        />
                        <p v-if="form.errors.password_confirmation" :class="fieldError">{{ form.errors.password_confirmation }}</p>
                    </div>

                    <!-- Role -->
                    <div>
                        <label for="role" :class="fieldLabel">Base Role</label>
                        <select
                            id="role"
                            v-model="form.role"
                            :class="fieldInput"
                            required
                        >
                            <option value="member">Member</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Administrator</option>
                        </select>
                        <p v-if="form.errors.role" :class="fieldError">{{ form.errors.role }}</p>
                        <p class="mt-1 text-sm text-text-tertiary">
                            Base organizational role. Admins have access to all features.
                        </p>
                    </div>

                    <!-- Additional Roles (if any custom roles exist) -->
                    <div v-if="roles && roles.length > 0">
                        <label :class="fieldLabel">Additional Roles (Optional)</label>
                        <div class="mt-2 max-h-48 space-y-2 overflow-y-auto rounded-md border border-border-subtle bg-surface-canvas p-4">
                            <label
                                v-for="role in roles"
                                :key="role.id"
                                class="flex cursor-pointer items-center space-x-3 rounded p-2 hover:bg-surface-sunken"
                            >
                                <input
                                    type="checkbox"
                                    :value="role.id"
                                    v-model="form.role_ids"
                                    class="rounded border-border-subtle bg-surface-canvas text-brand ds-focus-ring"
                                />
                                <div class="flex-1">
                                    <div class="text-sm font-medium text-text-secondary">{{ role.name }}</div>
                                    <div class="text-xs text-text-tertiary" v-if="role.description">{{ role.description }}</div>
                                </div>
                                <span class="text-xs text-text-tertiary">
                                    {{ role.permissions ? role.permissions.length : 0 }} permissions
                                </span>
                            </label>
                        </div>
                        <p class="mt-1 text-sm text-text-tertiary">
                            Assign custom roles with specific permission sets.
                        </p>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-4 border-t border-border-subtle pt-4">
                        <Button type="submit" variant="default" :loading="form.processing" :disabled="form.processing">
                            {{ t('admin.createUser') }}
                        </Button>

                        <Button variant="secondary" as="Link" :href="route('users.index')">
                            {{ t('common.cancel') }}
                        </Button>

                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p v-if="form.recentlySuccessful" class="text-sm text-status-success">
                                User created successfully.
                            </p>
                        </Transition>
                    </div>
                </div>
            </Card>
        </form>
    </AppLayout>
</template>
