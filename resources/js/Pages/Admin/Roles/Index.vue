<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Plus, Users, ShieldCheck } from 'lucide-vue-next';

defineProps({
    roles: Object,
    filters: Object,
});

const { t } = useI18n();
</script>

<template>
    <Head :title="t('nav.roles')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('nav.roles') }}</span>
            </div>
        </template>

        <PageHeader
            title="Roles & Permissions"
            description="Manage roles and permissions for your organization. Create custom roles with specific permission sets to control access across the application."
        >
            <template #actions>
                <Button variant="default" size="sm" as="Link" :href="route('roles.create')">
                    <Plus :size="14" />
                    {{ t('admin.createRole') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Roles grid -->
        <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Card v-for="role in roles?.data" :key="role.id">
                <div class="mb-4 flex items-start justify-between">
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-text-primary">{{ role.name }}</h3>
                        <p v-if="role.description" class="mt-1 text-sm text-text-secondary">{{ role.description }}</p>
                    </div>
                    <Badge v-if="role.is_system" variant="brand" size="sm">System</Badge>
                </div>

                <div class="mb-4 space-y-2">
                    <div class="flex items-center gap-2 text-sm text-text-secondary">
                        <Users :size="16" class="text-text-tertiary" />
                        <span>{{ role.users_count || 0 }} users</span>
                    </div>
                    <div class="flex items-center gap-2 text-sm text-text-secondary">
                        <ShieldCheck :size="16" class="text-text-tertiary" />
                        <span>{{ role.permissions ? role.permissions.length : 0 }} permissions</span>
                    </div>
                </div>

                <div class="flex gap-2">
                    <Button variant="secondary" size="sm" as="Link" :href="route('roles.show', role.id)" class="flex-1">
                        {{ t('common.view') }}
                    </Button>
                    <Button
                        v-if="role.slug !== 'system-administrator'"
                        variant="default"
                        size="sm"
                        as="Link"
                        :href="route('roles.edit', role.id)"
                        class="flex-1"
                    >
                        {{ t('common.edit') }}
                    </Button>
                    <span
                        v-else
                        class="inline-flex h-8 flex-1 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-3 text-xs font-medium text-text-tertiary"
                        title="Administrator role cannot be edited"
                    >
                        Locked
                    </span>
                </div>
            </Card>

            <!-- Empty state -->
            <div v-if="!roles || !roles.data || roles.data.length === 0" class="col-span-full">
                <Card>
                    <div class="flex flex-col items-center gap-3 py-10 text-center">
                        <ShieldCheck :size="22" class="text-text-tertiary" />
                        <p class="text-sm text-text-tertiary">No roles found. Click "Create Role" to define your first custom role.</p>
                        <Button variant="default" size="sm" as="Link" :href="route('roles.create')">
                            <Plus :size="14" />
                            {{ t('admin.createRole') }}
                        </Button>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Pagination -->
        <div v-if="roles && roles.links && roles.links.length > 3" class="mt-4 flex justify-center">
            <nav class="inline-flex items-center gap-1">
                <template v-for="(link, index) in roles.links" :key="index">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-colors',
                            link.active
                                ? 'border-brand bg-brand text-brand-foreground'
                                : 'border-border-subtle bg-surface-canvas text-text-secondary hover:bg-surface-overlay',
                        ]"
                        v-html="link.label"
                    />
                    <span v-else class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-2.5 text-xs text-text-tertiary opacity-50" v-html="link.label" />
                </template>
            </nav>
        </div>
    </AppLayout>
</template>
