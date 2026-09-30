<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Eye } from 'lucide-vue-next';

import { useI18n } from 'vue-i18n';
import { permissionCategoryLabel, permissionDescription, permissionLabel, roleName } from '@/lib/permissionLabels';
const props = defineProps({
    role: Object,
    permissions: Object,
});


const { t, te } = useI18n();
const i18n = { t, te };
const form = useForm({
    name: props.role.name,
    description: props.role.description || '',
    permissions: props.role.permissions || [],
});

const submit = () => {
    form.put(route('roles.update', props.role.id));
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

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('common.editWithName', { name: roleName(role, i18n) })" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('roles.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('roles.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.roles') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('common.editWithName', { name: roleName(role, i18n) }) }}</span>
            </div>
        </template>

        <PageHeader
            :title="t('admin.roles.edit.titleWithName', { name: roleName(role, i18n) })"
            :description="role.is_system ? t('admin.roles.edit.systemHint') : t('admin.roles.edit.subtitle')"
        >
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('roles.show', role.id)">
                    <Eye :size="14" />
                    {{ t('admin.roles.edit.viewRole') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('roles.index')">
                    <ArrowLeft :size="14" />
                    {{ t('admin.roles.edit.backToRoles') }}
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6 space-y-4">
            <!-- Role Details -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('admin.roles.create.roleDetails') }}</h3></div>
                <div class="space-y-4 p-5">
                    <!-- Name (disabled for system roles) -->
                    <div>
                        <label for="name" :class="fieldLabel">{{ t('admin.roles.create.roleName') }}</label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            :class="[fieldInput, { 'opacity-50 cursor-not-allowed': role.is_system }]"
                            :disabled="role.is_system"
                            required
                        />
                        <p v-if="form.errors.name" :class="fieldError">{{ form.errors.name }}</p>
                        <p v-if="role.is_system" class="mt-1 text-xs text-text-tertiary">
                            {{ t('admin.roles.edit.systemNameHint') }}
                        </p>
                    </div>

                    <!-- Description (disabled for system roles) -->
                    <div>
                        <label for="description" :class="fieldLabel">{{ t('common.description') }}</label>
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="3"
                            :class="[fieldArea, { 'opacity-50 cursor-not-allowed': role.is_system }]"
                            :disabled="role.is_system"
                        ></textarea>
                        <p v-if="form.errors.description" :class="fieldError">{{ form.errors.description }}</p>
                        <p v-if="role.is_system" class="mt-1 text-xs text-text-tertiary">
                            {{ t('admin.roles.edit.systemDescHint') }}
                        </p>
                    </div>
                </div>
            </Card>

            <!-- Permissions (editable for all roles) -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('admin.roles.edit.permissions') }}</h3></div>
                <div class="p-5">
                    <p class="mb-4 text-sm text-text-secondary">
                        {{ t('admin.roles.edit.permissionsHint') }}
                    </p>

                    <div class="space-y-6">
                        <div
                            v-for="(perms, category) in permissions"
                            :key="category"
                        >
                            <!-- Category Header -->
                            <div class="mb-3 flex items-center justify-between">
                                <h4 class="text-xs font-medium uppercase tracking-wider text-text-tertiary">{{ permissionCategoryLabel(category, i18n) }}</h4>
                                <button
                                    type="button"
                                    @click="toggleCategory(category)"
                                    class="rounded-md px-3 py-1 text-xs transition-colors ds-focus-ring"
                                    :class="isCategorySelected(category)
                                        ? 'bg-brand-soft text-brand hover:opacity-90'
                                        : 'bg-surface-overlay text-text-secondary hover:bg-surface-sunken'"
                                >
                                    {{ isCategorySelected(category) ? t('admin.roles.create.deselectAll') : t('admin.roles.create.selectAll') }}
                                </button>
                            </div>

                            <!-- Permissions in Category -->
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <label
                                    v-for="permission in perms"
                                    :key="permission.value"
                                    class="flex cursor-pointer items-start gap-3 rounded-lg border border-border-subtle bg-surface-canvas p-3 transition-colors hover:border-border-strong"
                                >
                                    <input
                                        type="checkbox"
                                        :value="permission.value"
                                        v-model="form.permissions"
                                        class="mt-0.5 rounded border-border-strong bg-surface-canvas text-brand ds-focus-ring"
                                    />
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-text-primary">{{ permissionLabel(permission, i18n) }}</p>
                                        <p class="text-xs text-text-tertiary">{{ permissionDescription(permission, i18n) }}</p>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <p v-if="form.errors.permissions" :class="fieldError">{{ form.errors.permissions }}</p>

                    <div class="mt-4 rounded-lg border border-brand/20 bg-brand-soft p-3">
                        <p class="text-sm text-brand">
                            {{ t('admin.roles.edit.permissionsSelected', { count: form.permissions.length }) }}
                        </p>
                    </div>
                </div>
            </Card>

            <!-- Actions -->
            <div class="flex items-center gap-3">
                <Button type="submit" variant="default" :loading="form.processing" :disabled="form.processing">
                    {{ t('admin.roles.edit.updateRole') }}
                </Button>
                <Button variant="secondary" as="Link" :href="route('roles.index')">
                    {{ t('common.cancel') }}
                </Button>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-status-success">
                        {{ t('admin.roles.edit.success') }}
                    </p>
                </Transition>
            </div>
        </form>
    </AppLayout>
</template>
