<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

import { useI18n } from 'vue-i18n';
import { roleDescription, roleName } from '@/lib/permissionLabels';
import { ArrowLeft } from 'lucide-vue-next';

const props = defineProps({
    user: Object,
    roles: Array,
});


const { t, te } = useI18n();
const i18n = { t, te };
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

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('common.editWithName', { name: user.name })" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('users.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('users.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.users') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('common.editWithName', { name: user.name }) }}</span>
            </div>
        </template>

        <PageHeader :title="t('admin.users.edit.titleWithName', { name: user.name })" :description="t('admin.users.edit.subtitle')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('users.index')">
                    <ArrowLeft :size="14" />
                    {{ t('admin.users.create.backToUsers') }}
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6 max-w-3xl">
            <Card :padded="false">
                <div class="space-y-6 p-5">
                    <!-- Name -->
                    <div>
                        <label for="name" :class="fieldLabel">{{ t('common.name') }}</label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            :class="fieldInput"
                            required
                            autofocus
                        />
                        <p v-if="form.errors.name" :class="fieldError">{{ form.errors.name }}</p>
                    </div>

                    <!-- Email -->
                    <div>
                        <label for="email" :class="fieldLabel">{{ t('common.email') }}</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            :class="fieldInput"
                            required
                        />
                        <p v-if="form.errors.email" :class="fieldError">{{ form.errors.email }}</p>
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="password" :class="fieldLabel">{{ t('admin.users.edit.newPassword') }}</label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            :class="fieldInput"
                            autocomplete="new-password"
                        />
                        <p v-if="form.errors.password" :class="fieldError">{{ form.errors.password }}</p>
                        <p class="mt-1 text-xs text-text-tertiary">
                            {{ t('admin.users.edit.passwordHint') }}
                        </p>
                    </div>

                    <!-- Password Confirmation -->
                    <div v-if="form.password">
                        <label for="password_confirmation" :class="fieldLabel">{{ t('admin.users.edit.confirmNewPassword') }}</label>
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            :class="fieldInput"
                            autocomplete="new-password"
                        />
                        <p v-if="form.errors.password_confirmation" :class="fieldError">{{ form.errors.password_confirmation }}</p>
                    </div>

                    <!-- Base Role -->
                    <div>
                        <label for="role" :class="fieldLabel">{{ t('admin.users.create.baseRole') }}</label>
                        <select
                            id="role"
                            v-model="form.role"
                            :class="fieldInput"
                            required
                        >
                            <option value="">{{ t('admin.users.edit.selectRole') }}</option>
                            <option value="admin">{{ t('admin.users.edit.admin') }}</option>
                            <option value="manager">{{ t('admin.users.create.manager') }}</option>
                            <option value="member">{{ t('admin.users.create.member') }}</option>
                        </select>
                        <p v-if="form.errors.role" :class="fieldError">{{ form.errors.role }}</p>
                        <p class="mt-1 text-xs text-text-tertiary">
                            {{ t('admin.users.edit.roleHint') }}
                        </p>
                    </div>

                    <!-- Additional Custom Roles -->
                    <div v-if="roles && roles.length > 0">
                        <label :class="fieldLabel">{{ t('admin.users.edit.customRoles') }}</label>
                        <p class="mb-4 mt-1 text-sm text-text-tertiary">
                            {{ t('admin.users.edit.customRolesHint') }}
                        </p>

                        <div class="max-h-64 space-y-2 overflow-y-auto rounded-lg border border-border-subtle bg-surface-canvas p-4">
                            <label
                                v-for="role in roles"
                                :key="role.id"
                                class="flex cursor-pointer items-start space-x-3 rounded p-2 transition-colors hover:bg-surface-sunken"
                            >
                                <input
                                    type="checkbox"
                                    :value="role.id"
                                    v-model="form.role_ids"
                                    class="mt-1 rounded border-border-subtle bg-surface-canvas text-brand ds-focus-ring"
                                />
                                <div class="flex-1">
                                    <div class="text-sm font-medium text-text-primary">{{ roleName(role, i18n) }}</div>
                                    <div class="text-xs text-text-tertiary" v-if="role.description">{{ roleDescription(role, i18n) }}</div>
                                    <div class="mt-1 text-xs text-text-tertiary">
                                        {{ t('admin.roles.permissionsCount', { count: role.permissions ? role.permissions.length : 0 }, role.permissions ? role.permissions.length : 0) }}
                                    </div>
                                </div>
                            </label>
                        </div>

                        <p v-if="form.errors.role_ids" :class="fieldError">{{ form.errors.role_ids }}</p>

                        <div class="mt-3 rounded-md border border-brand/20 bg-brand-soft p-3">
                            <p class="text-sm text-brand">
                                {{ t('admin.users.edit.customRolesSelected', { count: form.role_ids.length }, form.role_ids.length) }}
                            </p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-4 border-t border-border-subtle pt-4">
                        <Button type="submit" variant="default" :loading="form.processing" :disabled="form.processing">
                            {{ t('admin.users.edit.updateUser') }}
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
                                {{ t('admin.users.edit.success') }}
                            </p>
                        </Transition>
                    </div>
                </div>
            </Card>
        </form>
    </AppLayout>
</template>
