<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Plus, Eye, Pencil, Users } from 'lucide-vue-next';

defineProps({
    users: Object,
    roles: Array,
    filters: Object,
});

const { t } = useI18n();

const roleVariant = (role) =>
    ({ admin: 'brand', manager: 'info', member: 'neutral' }[role] || 'neutral');

const thClass = 'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
</script>

<template>
    <Head :title="t('nav.users')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('admin.users') }}</span>
            </div>
        </template>

        <PageHeader :title="t('admin.users')" description="Manage users in your organization, assign roles and permissions.">
            <template #actions>
                <Button variant="default" size="sm" as="Link" :href="route('users.create')">
                    <Plus :size="14" />
                    {{ t('common.add') }} {{ t('nav.users') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Users table -->
        <div class="mt-6 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">{{ t('common.name') }}</th>
                        <th :class="thClass">{{ t('common.email') }}</th>
                        <th :class="thClass">Role</th>
                        <th :class="[thClass, 'text-right']">{{ t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!users || !users.data || users.data.length === 0">
                        <td colspan="4" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <Users :size="22" class="text-text-tertiary" />
                                <p class="text-sm font-medium text-text-primary">No users found.</p>
                                <p class="text-sm text-text-tertiary">Click "Add User" to create your first user.</p>
                                <Button variant="default" size="sm" as="Link" :href="route('users.create')">
                                    <Plus :size="14" />
                                    {{ t('common.add') }} {{ t('nav.users') }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr
                        v-for="user in users && users.data ? users.data : []"
                        :key="user.id"
                        class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                    >
                        <td class="px-4 py-3">
                            <span class="font-medium text-text-primary">{{ user.name }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-text-secondary">{{ user.email }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="roleVariant(user.role)" size="sm">{{ user.role }}</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Link :href="route('users.show', user.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-brand" :title="t('common.view')"><Eye :size="16" /></Link>
                                <Link :href="route('users.edit', user.id)" class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-success" :title="t('common.edit')"><Pencil :size="16" /></Link>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="users && users.links && users.links.length > 3" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p v-if="users.total !== undefined" class="text-xs text-text-tertiary">
                {{ t('common.showing') }} <span class="font-medium text-text-secondary">{{ users.from }}</span>
                {{ t('common.to') }} <span class="font-medium text-text-secondary">{{ users.to }}</span>
                {{ t('common.of') }} <span class="font-medium text-text-secondary">{{ users.total }}</span> {{ t('common.results') }}
            </p>
            <nav class="inline-flex items-center gap-1">
                <template v-for="link in users.links" :key="link.label">
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
