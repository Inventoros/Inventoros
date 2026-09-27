<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, KeyRound, Trash2, X, Info } from 'lucide-vue-next';

const props = defineProps({
    tokens: { type: Array, default: () => [] },
    availableAbilities: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const page = usePage();

// One-time reveal of a newly created token. It arrives once via a flash and
// is never part of a normal page load.
const revealedToken = computed(() => page.props.flash?.newApiToken ?? null);
const copied = ref(false);
const copyToken = async () => {
    if (!revealedToken.value) return;
    try {
        await navigator.clipboard.writeText(revealedToken.value);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch (err) {
        console.error('Failed to copy token:', err);
    }
};

const allAbilityValues = computed(() =>
    Object.values(props.availableAbilities).flat().map((permission) => permission.value),
);
const hasAbilities = computed(() => allAbilityValues.value.length > 0);

const showCreateModal = ref(false);
const form = useForm({
    name: '',
    abilities: [],
});

const openCreateModal = () => {
    form.reset();
    form.clearErrors();
    showCreateModal.value = true;
};

const closeCreateModal = () => {
    showCreateModal.value = false;
    form.reset();
};

const submit = () => {
    form.post(route('settings.api-tokens.store'), {
        preserveScroll: true,
        onSuccess: () => closeCreateModal(),
    });
};

const toggleGroup = (group) => {
    const values = props.availableAbilities[group].map((permission) => permission.value);
    const allSelected = values.every((value) => form.abilities.includes(value));
    form.abilities = allSelected
        ? form.abilities.filter((value) => !values.includes(value))
        : [...new Set([...form.abilities, ...values])];
};

const isGroupSelected = (group) =>
    props.availableAbilities[group].every((permission) => form.abilities.includes(permission.value));

const selectAll = () => {
    form.abilities = [...allAbilityValues.value];
};

const clearAll = () => {
    form.abilities = [];
};

const abilityErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key === 'abilities' || key.startsWith('abilities.'))
        .map(([, message]) => message),
);

const revoke = (token) => {
    if (confirm(t('settings.apiTokens.revokeConfirm', { name: token.name }))) {
        router.delete(route('settings.api-tokens.destroy', token.id), { preserveScroll: true });
    }
};

const formatDate = (value) => (value ? new Date(value).toLocaleString() : t('settings.apiTokens.never'));

const abilitySummary = (token) => {
    const abilities = token.abilities || [];
    if (abilities.includes('*')) return t('settings.apiTokens.allPermissions');
    return t('settings.apiTokens.abilitiesCount', { count: abilities.length });
};

const thClass = 'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('settings.apiTokens.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('settings.index')" class="text-text-tertiary hover:text-text-primary">{{ t('settings.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('settings.apiTokens.title') }}</span>
            </div>
        </template>

        <div>
            <PageHeader :title="t('settings.apiTokens.title')" :description="t('settings.apiTokens.description')">
                <template #actions>
                    <Button variant="default" size="sm" :disabled="!hasAbilities" @click="openCreateModal">
                        <Plus :size="14" />
                        {{ t('settings.apiTokens.create') }}
                    </Button>
                </template>
            </PageHeader>

            <!-- One-time plaintext token, shown once right after creation -->
            <div
                v-if="revealedToken"
                class="mt-6 flex items-start gap-3 rounded-lg border border-status-warning/30 bg-status-warning-soft p-4"
            >
                <Info :size="18" class="mt-0.5 shrink-0 text-status-warning" />
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-medium text-text-primary">{{ t('settings.apiTokens.newTokenTitle') }}</h3>
                    <p class="mt-1 text-sm text-text-secondary">{{ t('settings.apiTokens.newTokenHint') }}</p>
                    <div class="mt-2 flex items-center gap-3">
                        <code class="min-w-0 flex-1 break-all rounded-md border border-border-subtle bg-surface-canvas p-3 font-mono text-xs text-text-primary">{{ revealedToken }}</code>
                        <button type="button" class="shrink-0 rounded-md text-xs text-brand hover:underline ds-focus-ring" @click="copyToken">
                            {{ copied ? t('settings.apiTokens.copied') : t('settings.apiTokens.copy') }}
                        </button>
                    </div>
                </div>
            </div>

            <p v-if="!hasAbilities" class="mt-6 text-sm text-text-secondary">{{ t('settings.apiTokens.noPermissions') }}</p>

            <!-- Tokens table -->
            <div class="mt-6 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-subtle">
                            <th :class="thClass">{{ t('settings.apiTokens.name') }}</th>
                            <th :class="thClass">{{ t('settings.apiTokens.abilities') }}</th>
                            <th :class="thClass">{{ t('settings.apiTokens.lastUsed') }}</th>
                            <th :class="thClass">{{ t('settings.apiTokens.created') }}</th>
                            <th :class="[thClass, 'text-right']">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="tokens.length === 0">
                            <td colspan="5" class="px-4 py-12 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <KeyRound :size="22" class="text-text-tertiary" />
                                    <p class="text-sm text-text-tertiary">{{ t('settings.apiTokens.empty') }}</p>
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-for="token in tokens"
                            :key="token.id"
                            class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                        >
                            <td class="px-4 py-3 font-medium text-text-primary">{{ token.name }}</td>
                            <td class="px-4 py-3">
                                <Badge variant="brand" size="sm" :title="(token.abilities || []).join(', ')">{{ abilitySummary(token) }}</Badge>
                            </td>
                            <td class="px-4 py-3 tabular-nums text-text-secondary">{{ formatDate(token.last_used_at) }}</td>
                            <td class="px-4 py-3 tabular-nums text-text-secondary">{{ formatDate(token.created_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end">
                                    <button
                                        type="button"
                                        class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-danger ds-focus-ring"
                                        :title="t('settings.apiTokens.revoke')"
                                        :aria-label="`${t('settings.apiTokens.revoke')} ${token.name}`"
                                        @click="revoke(token)"
                                    >
                                        <Trash2 :size="16" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Create modal -->
            <Teleport to="body">
                <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center">
                    <div class="fixed inset-0 bg-black/50" @click="closeCreateModal"></div>
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="api-token-create-title"
                        class="relative mx-4 my-8 max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg"
                    >
                        <div class="mb-6 flex items-center justify-between">
                            <h3 id="api-token-create-title" class="text-base font-semibold text-text-primary">{{ t('settings.apiTokens.createTitle') }}</h3>
                            <button
                                type="button"
                                class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-text-primary ds-focus-ring"
                                :aria-label="t('common.cancel')"
                                @click="closeCreateModal"
                            >
                                <X :size="18" />
                            </button>
                        </div>

                        <form class="space-y-6" @submit.prevent="submit">
                            <div>
                                <label for="api-token-name" :class="fieldLabel">{{ t('settings.apiTokens.name') }}</label>
                                <input
                                    id="api-token-name"
                                    v-model="form.name"
                                    type="text"
                                    maxlength="255"
                                    :class="fieldInput"
                                    :placeholder="t('settings.apiTokens.namePlaceholder')"
                                    required
                                />
                                <p v-if="form.errors.name" :class="fieldError">{{ form.errors.name }}</p>
                            </div>

                            <div>
                                <div class="mb-1 flex items-center justify-between">
                                    <span :class="fieldLabel">{{ t('settings.apiTokens.abilities') }}</span>
                                    <div class="flex items-center gap-3 text-xs">
                                        <button type="button" class="text-brand hover:underline ds-focus-ring" @click="selectAll">{{ t('settings.apiTokens.selectAll') }}</button>
                                        <button type="button" class="text-text-tertiary hover:underline ds-focus-ring" @click="clearAll">{{ t('settings.apiTokens.clearAll') }}</button>
                                    </div>
                                </div>
                                <p class="mb-3 text-xs text-text-tertiary">{{ t('settings.apiTokens.abilitiesHint') }}</p>

                                <div class="space-y-4">
                                    <fieldset v-for="(permissions, group) in availableAbilities" :key="group" class="rounded-lg border border-border-subtle p-3">
                                        <legend class="px-1">
                                            <label class="flex items-center gap-2 text-sm font-medium text-text-primary">
                                                <input
                                                    type="checkbox"
                                                    class="h-4 w-4 rounded border-border-subtle text-brand ds-focus-ring"
                                                    :checked="isGroupSelected(group)"
                                                    @change="toggleGroup(group)"
                                                />
                                                {{ group }}
                                            </label>
                                        </legend>
                                        <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                            <label
                                                v-for="permission in permissions"
                                                :key="permission.value"
                                                class="flex items-start gap-2 text-sm"
                                            >
                                                <input
                                                    v-model="form.abilities"
                                                    type="checkbox"
                                                    :value="permission.value"
                                                    class="mt-0.5 h-4 w-4 rounded border-border-subtle text-brand ds-focus-ring"
                                                />
                                                <span>
                                                    <span class="text-text-primary">{{ permission.label }}</span>
                                                    <span class="block font-mono text-xs text-text-tertiary">{{ permission.value }}</span>
                                                </span>
                                            </label>
                                        </div>
                                    </fieldset>
                                </div>
                                <p v-for="(message, index) in abilityErrors" :key="index" :class="fieldError">{{ message }}</p>
                            </div>

                            <div class="flex justify-end gap-2">
                                <Button type="button" variant="secondary" size="sm" @click="closeCreateModal">{{ t('common.cancel') }}</Button>
                                <Button type="submit" variant="default" size="sm" :loading="form.processing" :disabled="form.processing">
                                    {{ t('settings.apiTokens.create') }}
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>
        </div>
    </AppLayout>
</template>
