<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { roleName } from '@/lib/permissionLabels';
import { displayDate } from '@/lib/dates';
import { Pencil, ArrowLeft, Trash2, X, AlertTriangle, CheckCircle2 } from 'lucide-vue-next';

const props = defineProps({
    user: Object,
});

const { t, te } = useI18n();
const i18n = { t, te };
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
    <Head :title="t('admin.users.show.titleWithName', { name: user.name })" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('users.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('users.index')" class="text-text-tertiary hover:text-text-primary">{{ t('admin.users.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ user.name }}</span>
            </div>
        </template>

        <PageHeader :title="user.name" :description="t('admin.userDetails')">
            <template #actions>
                <Badge :variant="roleVariant(user.role)" size="sm" dot class="capitalize">{{ t(`admin.users.roles.${user.role}`) }}</Badge>
                <Button variant="default" size="sm" as="Link" :href="route('users.edit', user.id)">
                    <Pencil :size="14" />
                    {{ t('admin.users.show.editUser') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('users.index')">
                    <ArrowLeft :size="14" />
                    {{ t('admin.users.show.backToUsers') }}
                </Button>
            </template>
        </PageHeader>

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Main Info -->
            <div class="space-y-4 lg:col-span-2">
                <!-- User Information -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('admin.users.show.userInfo') }}</h3></div>
                    <div class="p-5">
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('admin.users.show.fullName') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ user.name }}</dd>
                            </div>

                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('admin.users.show.emailAddress') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ user.email }}</dd>
                            </div>

                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('admin.users.show.primaryRole') }}</dt>
                                <dd class="mt-1">
                                    <Badge :variant="roleVariant(user.role)" size="sm" class="capitalize">{{ t(`admin.users.roles.${user.role}`) }}</Badge>
                                </dd>
                            </div>

                            <div v-if="user.roles && user.roles.length > 0">
                                <dt class="text-xs text-text-tertiary">{{ t('admin.users.show.additionalRoles') }}</dt>
                                <dd class="mt-2 flex flex-wrap gap-2">
                                    <Badge
                                        v-for="role in user.roles"
                                        :key="role.id"
                                        variant="brand"
                                        size="sm"
                                    >
                                        {{ roleName(role, i18n) }}
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
                                <dt class="text-xs text-text-tertiary">{{ t('admin.users.show.orgName') }}</dt>
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
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('admin.users.show.accountDetails') }}</h3></div>
                    <div class="p-5">
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('admin.users.show.memberSince') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">
                                    {{ displayDate(user.created_at) }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('common.updatedAt') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">
                                    {{ displayDate(user.updated_at) }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('admin.users.show.emailVerified') }}</dt>
                                <dd class="mt-1">
                                    <Badge v-if="user.email_verified_at" variant="success" size="sm" dot>
                                        <CheckCircle2 :size="12" />
                                        {{ t('admin.users.show.verified') }}
                                    </Badge>
                                    <Badge v-else variant="warning" size="sm" dot>
                                        <AlertTriangle :size="12" />
                                        {{ t('admin.users.show.notVerified') }}
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
                            {{ t('admin.users.show.editUser') }}
                        </Button>
                        <Button variant="danger" class="w-full" @click="showDeleteModal = true">
                            <Trash2 :size="16" />
                            {{ t('admin.users.show.deleteUser') }}
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
                            {{ t('admin.users.show.deleteUser') }}
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
                            {{ t('admin.users.show.confirmDelete', { name: user.name }) }}
                        </p>
                        <div class="rounded-lg border border-status-warning/20 bg-status-warning-soft p-4">
                            <div class="flex items-start gap-3">
                                <AlertTriangle :size="20" class="mt-0.5 flex-shrink-0 text-status-warning" />
                                <div class="text-sm text-status-warning">
                                    <p class="font-semibold">{{ t('admin.users.show.cannotUndo') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3">
                        <Button variant="secondary" @click="showDeleteModal = false" :disabled="deleting">
                            {{ t('common.cancel') }}
                        </Button>
                        <Button variant="danger" :loading="deleting" :disabled="deleting" @click="deleteUser">
                            <span v-if="deleting">{{ t('common.deleting') }}</span>
                            <span v-else>{{ t('admin.users.show.deleteUser') }}</span>
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
