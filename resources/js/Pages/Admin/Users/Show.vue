<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Pencil, ArrowLeft, Trash2, X, AlertTriangle, CheckCircle2 } from 'lucide-vue-next';

const props = defineProps({
    user: Object,
});

const { t } = useI18n();
const showDeleteModal = ref(false);
const deleting = ref(false);

const deleteUser = () => {
    deleting.value = true;
    router.delete(route('users.destroy', props.user.id), {
        onFinish: () => {
            deleting.value = false;
            showDeleteModal.value = false;
        },
    });
};

const roleVariant = (role) =>
    ({
        admin: 'brand',
        manager: 'info',
        member: 'neutral',
    }[role] || 'neutral');
</script>

<template>
    <Head :title="`User: ${user.name}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('users.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('users.index')" class="text-text-tertiary hover:text-text-primary">{{ t('admin.users') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ user.name }}</span>
            </div>
        </template>

        <PageHeader :title="user.name" :description="t('admin.userDetails')">
            <template #actions>
                <Badge :variant="roleVariant(user.role)" size="sm" dot class="capitalize">{{ user.role }}</Badge>
                <Button variant="default" size="sm" as="Link" :href="route('users.edit', user.id)">
                    <Pencil :size="14" />
                    Edit User
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('users.index')">
                    <ArrowLeft :size="14" />
                    Back to Users
                </Button>
            </template>
        </PageHeader>

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Main Info -->
            <div class="space-y-4 lg:col-span-2">
                <!-- User Information -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">User Information</h3></div>
                    <div class="p-5">
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-xs text-text-tertiary">Full Name</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ user.name }}</dd>
                            </div>

                            <div>
                                <dt class="text-xs text-text-tertiary">Email Address</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ user.email }}</dd>
                            </div>

                            <div>
                                <dt class="text-xs text-text-tertiary">Primary Role</dt>
                                <dd class="mt-1">
                                    <Badge :variant="roleVariant(user.role)" size="sm" class="capitalize">{{ user.role }}</Badge>
                                </dd>
                            </div>

                            <div v-if="user.roles && user.roles.length > 0">
                                <dt class="text-xs text-text-tertiary">Additional Roles</dt>
                                <dd class="mt-2 flex flex-wrap gap-2">
                                    <Badge
                                        v-for="role in user.roles"
                                        :key="role.id"
                                        variant="brand"
                                        size="sm"
                                    >
                                        {{ role.name }}
                                    </Badge>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Organization -->
                <Card v-if="user.organization" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('settings.organization.title') }}</h3></div>
                    <div class="p-5">
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-xs text-text-tertiary">Organization Name</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ user.organization.name }}</dd>
                            </div>

                            <div v-if="user.organization.address">
                                <dt class="text-xs text-text-tertiary">{{ t('common.address') }}</dt>
                                <dd class="mt-1 whitespace-pre-line text-sm text-text-primary">{{ user.organization.address }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>
            </div>

            <!-- Sidebar -->
            <div class="space-y-4">
                <!-- Account Details -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Account Details</h3></div>
                    <div class="p-5">
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-xs text-text-tertiary">Member Since</dt>
                                <dd class="mt-1 text-sm text-text-primary">
                                    {{ new Date(user.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs text-text-tertiary">Last Updated</dt>
                                <dd class="mt-1 text-sm text-text-primary">
                                    {{ new Date(user.updated_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs text-text-tertiary">Email Verified</dt>
                                <dd class="mt-1">
                                    <Badge v-if="user.email_verified_at" variant="success" size="sm" dot>
                                        <CheckCircle2 :size="12" />
                                        Verified
                                    </Badge>
                                    <Badge v-else variant="warning" size="sm" dot>
                                        <AlertTriangle :size="12" />
                                        Not Verified
                                    </Badge>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Actions -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('common.actions') }}</h3></div>
                    <div class="p-5 space-y-3">
                        <Button variant="default" class="w-full" as="Link" :href="route('users.edit', user.id)">
                            <Pencil :size="16" />
                            Edit User
                        </Button>
                        <Button variant="danger" class="w-full" @click="showDeleteModal = true">
                            <Trash2 :size="16" />
                            Delete User
                        </Button>
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
                        <h3 class="text-base font-semibold text-text-primary">
                            Delete User
                        </h3>
                        <button
                            @click="showDeleteModal = false"
                            class="text-text-tertiary transition-colors hover:text-text-primary"
                        >
                            <X :size="18" />
                        </button>
                    </div>

                    <div class="mb-6">
                        <p class="mb-4 text-sm text-text-secondary">
                            Are you sure you want to delete <strong class="text-text-primary">{{ user.name }}</strong>?
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
                        <Button variant="danger" :loading="deleting" :disabled="deleting" @click="deleteUser">
                            <span v-if="deleting">Deleting...</span>
                            <span v-else>Delete User</span>
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
