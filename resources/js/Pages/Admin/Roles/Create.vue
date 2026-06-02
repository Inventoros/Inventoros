<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Check } from 'lucide-vue-next';

const props = defineProps({
    permissions: Object,
    permissionSets: Array,
});


const { t } = useI18n();
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

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('admin.roles.createRole')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('roles.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('roles.index')" class="text-text-tertiary hover:text-text-primary">Roles</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">New</span>
            </div>
        </template>

        <PageHeader :title="t('admin.createRole')" description="Define a role and assign its permissions.">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('roles.index')">
                    <ArrowLeft :size="14" />
                    Back to Roles
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6 space-y-4">
            <!-- Name & Description -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Role Details</h3></div>
                <div class="space-y-4 p-5">
                    <div>
                        <label for="name" :class="fieldLabel">Role Name</label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            :class="fieldInput"
                            required
                            autofocus
                            placeholder="e.g., Warehouse Manager"
                        />
                        <p v-if="form.errors.name" :class="fieldError">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label for="description" :class="fieldLabel">Description (Optional)</label>
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="3"
                            :class="fieldArea"
                            placeholder="Describe the purpose and responsibilities of this role..."
                        ></textarea>
                        <p v-if="form.errors.description" :class="fieldError">{{ form.errors.description }}</p>
                    </div>
                </div>
            </Card>

            <!-- Permission Sets (Quick Templates) -->
            <Card v-if="permissionSets && permissionSets.length > 0" :padded="false">
                <div class="px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">Permission Sets (Quick Templates)</h3>
                    <p class="mt-1 text-sm text-text-tertiary">
                        Apply pre-configured permission sets to quickly assign common permission groups.
                    </p>
                </div>
                <div class="p-5">
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                        <button
                            v-for="set in permissionSets"
                            :key="set.id"
                            type="button"
                            @click="togglePermissionSet(set.id)"
                            :class="[
                                'rounded-lg border p-4 text-left transition-colors',
                                form.permission_set_ids.includes(set.id)
                                    ? 'border-brand bg-brand-soft'
                                    : 'border-border-subtle bg-surface-canvas hover:border-border-strong'
                            ]"
                        >
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0">
                                    <svg class="h-6 w-6 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="getCategoryIcon(set.category)" />
                                    </svg>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="font-medium text-text-primary">{{ set.name }}</div>
                                    <div class="mt-1 text-xs text-text-tertiary">{{ set.description }}</div>
                                    <div class="mt-2 flex items-center gap-2">
                                        <Badge variant="neutral" size="sm">{{ set.permission_count }} permissions</Badge>
                                        <Badge v-if="set.is_template" variant="info" size="sm">Template</Badge>
                                    </div>
                                </div>
                                <div v-if="form.permission_set_ids.includes(set.id)" class="flex-shrink-0">
                                    <Check :size="18" class="text-brand" />
                                </div>
                            </div>
                        </button>
                    </div>
                </div>
            </Card>

            <!-- Individual Permissions -->
            <Card :padded="false">
                <div class="px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">Individual Permissions</h3>
                    <p class="mt-1 text-sm text-text-tertiary">
                        Select additional permissions. You can click category names to select/deselect all permissions in that category.
                    </p>
                </div>
                <div class="p-5">
                    <div class="space-y-6">
                        <div v-for="(perms, category) in permissions" :key="category">
                            <!-- Category Header -->
                            <div class="mb-3 flex items-center justify-between">
                                <h4 class="text-xs font-medium uppercase tracking-wider text-text-tertiary">{{ category }}</h4>
                                <button
                                    type="button"
                                    @click="toggleCategory(category)"
                                    :class="[
                                        'rounded-md px-3 py-1 text-xs transition-colors',
                                        isCategorySelected(category)
                                            ? 'bg-brand-soft text-brand'
                                            : 'bg-surface-overlay text-text-secondary hover:text-text-primary'
                                    ]"
                                >
                                    {{ isCategorySelected(category) ? 'Deselect All' : 'Select All' }}
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
                                        class="mt-0.5 h-4 w-4 rounded border-border-subtle bg-surface-canvas text-brand ds-focus-ring"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium text-text-primary">{{ permission.label }}</div>
                                        <div class="text-xs text-text-tertiary">{{ permission.description }}</div>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <p v-if="form.errors.permissions" :class="fieldError">{{ form.errors.permissions }}</p>

                    <div class="mt-4 rounded-lg border border-brand/20 bg-brand-soft p-3">
                        <p class="text-sm text-brand">
                            <strong>{{ getTotalPermissions() }}</strong> total permission(s)
                            <span v-if="form.permission_set_ids.length > 0" class="text-text-tertiary">
                                ({{ form.permissions.length }} direct + {{ getSetPermissions().length }} from {{ form.permission_set_ids.length }} set(s))
                            </span>
                        </p>
                    </div>
                </div>
            </Card>

            <!-- Actions -->
            <div class="flex items-center gap-3">
                <Button type="submit" variant="default" :loading="form.processing" :disabled="form.processing">
                    {{ t('admin.createRole') }}
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
                        Role created successfully.
                    </p>
                </Transition>
            </div>
        </form>
    </AppLayout>
</template>
