<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Pencil, ArrowLeft, Trash2, CheckCircle2, ShieldAlert, Info, X, AlertTriangle } from 'lucide-vue-next';

const props = defineProps({
    role: Object,
    rolePermissions: Array,
});

const { t } = useI18n();
const showDeleteModal = ref(false);
const deleting = ref(false);

const isProtected = computed(() => props.role.is_system || props.role.slug === 'system-administrator');

const groupedPermissions = computed(() => {
    const groups = {};
    props.rolePermissions.forEach(permission => {
        const category = permission.category || 'Other';
        if (!groups[category]) {
            groups[category] = [];
        }
        groups[category].push(permission);
    });
    return groups;
});

const formatDate = (date) =>
    new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });

const deleteRole = () => {
    deleting.value = true;
    router.delete(route('roles.destroy', props.role.id), {
        onFinish: () => {
            deleting.value = false;
            showDeleteModal.value = false;
        },
    });
};
</script>

<template>
    <Head :title="`Role: ${role.name}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('roles.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('roles.index')" class="text-text-tertiary hover:text-text-primary">Roles</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ role.name }}</span>
            </div>
        </template>

        <PageHeader :title="role.name" description="Role Details & Permissions">
            <template #actions>
                <Badge v-if="role.is_system" variant="brand" size="sm">System Role</Badge>
                <Button
                    v-if="!isProtected"
                    variant="default"
                    size="sm"
                    as="Link"
                    :href="route('roles.edit', role.id)"
                >
                    <Pencil :size="14" />
                    Edit Role
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('roles.index')">
                    <ArrowLeft :size="14" />
                    Back to Roles
                </Button>
            </template>
        </PageHeader>

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Main Info -->
            <div class="space-y-4 lg:col-span-2">
                <!-- Role Information -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Role Information</h3></div>
                    <div class="p-5">
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-xs text-text-tertiary">Role Name</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ role.name }}</dd>
                            </div>

                            <div v-if="role.description">
                                <dt class="text-xs text-text-tertiary">{{ t('common.description') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ role.description }}</dd>
                            </div>

                            <div>
                                <dt class="text-xs text-text-tertiary">Role Type</dt>
                                <dd class="mt-1">
                                    <Badge :variant="role.is_system ? 'brand' : 'neutral'" size="sm">
                                        {{ role.is_system ? 'System Role' : 'Custom Role' }}
                                    </Badge>
                                </dd>
                            </div>

                            <div>
                                <dt class="mb-2 text-xs text-text-tertiary">Users with this Role</dt>
                                <dd v-if="role.users && role.users.length > 0" class="space-y-2">
                                    <div
                                        v-for="user in role.users"
                                        :key="user.id"
                                        class="flex items-center gap-3 rounded-lg border border-border-subtle bg-surface-canvas p-3"
                                    >
                                        <div class="grid h-10 w-10 flex-shrink-0 place-items-center rounded-full bg-brand text-sm font-semibold text-brand-foreground">
                                            {{ user.name.charAt(0).toUpperCase() }}
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-text-primary">{{ user.name }}</p>
                                            <p class="text-xs text-text-tertiary">{{ user.email }}</p>
                                        </div>
                                    </div>
                                </dd>
                                <dd v-else class="text-sm text-text-tertiary">No users assigned to this role yet.</dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Permissions -->
                <Card :padded="false">
                    <div class="px-5 pt-5">
                        <h3 class="text-sm font-semibold text-text-primary">Permissions ({{ rolePermissions.length }})</h3>
                    </div>
                    <div class="p-5">
                        <div v-if="rolePermissions.length > 0" class="space-y-6">
                            <div v-for="(permissions, category) in groupedPermissions" :key="category">
                                <h4 class="mb-3 text-xs font-medium uppercase tracking-wider text-text-tertiary">
                                    {{ category }}
                                </h4>
                                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div
                                        v-for="permission in permissions"
                                        :key="permission.value"
                                        class="flex items-start gap-3 rounded-lg border border-border-subtle bg-surface-canvas p-3"
                                    >
                                        <CheckCircle2 :size="18" class="mt-0.5 flex-shrink-0 text-status-success" />
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-text-primary">{{ permission.label }}</p>
                                            <p class="text-xs text-text-tertiary">{{ permission.description }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
                            <ShieldAlert :size="22" class="text-text-tertiary" />
                            <p class="text-sm text-text-tertiary">No permissions assigned to this role.</p>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Sidebar -->
            <div class="space-y-4">
                <!-- Details -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Details</h3></div>
                    <div class="p-5">
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-xs text-text-tertiary">Created</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ formatDate(role.created_at) }}</dd>
                            </div>

                            <div>
                                <dt class="text-xs text-text-tertiary">Last Updated</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ formatDate(role.updated_at) }}</dd>
                            </div>

                            <div>
                                <dt class="text-xs text-text-tertiary">Role Slug</dt>
                                <dd class="mt-1">
                                    <span class="inline-block rounded-md border border-border-subtle bg-surface-canvas px-2 py-1 font-mono text-sm text-text-primary">
                                        {{ role.slug }}
                                    </span>
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs text-text-tertiary">Users Count</dt>
                                <dd class="mt-1 text-sm text-text-primary">
                                    {{ role.users ? role.users.length : 0 }} {{ role.users && role.users.length === 1 ? 'user' : 'users' }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Actions -->
                <Card v-if="!isProtected" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('common.actions') }}</h3></div>
                    <div class="p-5">
                        <div class="space-y-3">
                            <Button variant="default" class="w-full" as="Link" :href="route('roles.edit', role.id)">
                                <Pencil :size="16" />
                                Edit Role
                            </Button>

                            <Button
                                variant="danger"
                                class="w-full"
                                :disabled="role.users && role.users.length > 0"
                                :title="role.users && role.users.length > 0 ? 'Cannot delete role with assigned users' : 'Delete role'"
                                @click="showDeleteModal = true"
                            >
                                <Trash2 :size="16" />
                                Delete Role
                            </Button>

                            <p v-if="role.users && role.users.length > 0" class="text-center text-xs text-text-tertiary">
                                Reassign users before deleting
                            </p>
                        </div>
                    </div>
                </Card>

                <!-- System Role Notice -->
                <Card v-else :padded="false">
                    <div class="p-5">
                        <div class="flex gap-3 rounded-lg border border-brand/20 bg-brand-soft p-4">
                            <Info :size="18" class="mt-0.5 flex-shrink-0 text-brand" />
                            <div>
                                <p class="text-sm font-medium text-text-primary">System Role</p>
                                <p class="mt-1 text-xs text-text-secondary">
                                    This is a system role and cannot be modified or deleted.
                                </p>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center" @click="showDeleteModal = false">
                <div class="fixed inset-0 bg-black/50"></div>

                <div class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg" @click.stop>
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">Delete Role</h3>
                        <button
                            @click="showDeleteModal = false"
                            class="text-text-tertiary transition-colors hover:text-text-primary"
                        >
                            <X :size="18" />
                        </button>
                    </div>

                    <div class="mb-6">
                        <p class="mb-4 text-sm text-text-secondary">
                            Are you sure you want to delete the <strong class="text-text-primary">{{ role.name }}</strong> role?
                        </p>
                        <div class="rounded-lg border border-status-warning/20 bg-status-warning-soft p-4">
                            <div class="flex items-start gap-3">
                                <AlertTriangle :size="20" class="mt-0.5 flex-shrink-0 text-status-warning" />
                                <div class="text-sm text-status-warning">
                                    <p class="font-semibold">This action cannot be undone.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3">
                        <Button variant="secondary" @click="showDeleteModal = false" :disabled="deleting">
                            {{ t('common.cancel') }}
                        </Button>
                        <Button variant="danger" :loading="deleting" :disabled="deleting" @click="deleteRole">
                            <span v-if="deleting">Deleting...</span>
                            <span v-else>Delete Role</span>
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
