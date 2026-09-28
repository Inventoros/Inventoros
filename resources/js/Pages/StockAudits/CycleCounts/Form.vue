<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    schedule: { type: Object, default: null },
    locations: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    frequencies: { type: Array, default: () => [] },
    scopes: { type: Array, default: () => [] },
});

const editing = computed(() => !!props.schedule);

const form = useForm({
    name: props.schedule?.name ?? '',
    frequency: props.schedule?.frequency ?? 'weekly',
    scope_type: props.schedule?.scope_type ?? 'all',
    scope_id: props.schedule?.scope_id ?? null,
    products_per_run: props.schedule?.products_per_run ?? 20,
    assigned_to: props.schedule?.assigned_to ?? null,
    is_active: props.schedule?.is_active ?? true,
    next_run_at: props.schedule?.next_run_at ?? '',
});

const scopeOptions = computed(() => ({
    location: props.locations,
    warehouse: props.warehouses,
    category: props.categories,
}[form.scope_type] || []));

// A scope value from another scope type means nothing; clear it on switch.
watch(() => form.scope_type, (type, previous) => {
    if (previous !== undefined && type !== previous) form.scope_id = null;
});

const submit = () => {
    const payload = (data) => ({ ...data, next_run_at: data.next_run_at || null });
    if (editing.value) {
        form.transform(payload).put(route('cycle-counts.update', props.schedule.id));
    } else {
        form.transform(payload).post(route('cycle-counts.store'));
    }
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-11 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="editing ? t('cycleCounts.edit') : t('cycleCounts.new')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.workspace') }}</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('stock-audits.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.stockAudits') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('cycle-counts.index')" class="text-text-tertiary hover:text-text-primary">{{ t('cycleCounts.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ editing ? t('cycleCounts.edit') : t('cycleCounts.new') }}</span>
            </div>
        </template>

        <PageHeader :title="editing ? t('cycleCounts.edit') : t('cycleCounts.new')" :description="t('cycleCounts.formHint')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('cycle-counts.index')">
                    <ArrowLeft :size="14" />
                    {{ t('common.back') }}
                </Button>
            </template>
        </PageHeader>

        <Card class="mt-6">
            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <label for="cc-name" :class="fieldLabel">{{ t('cycleCounts.name') }}</label>
                    <input id="cc-name" v-model="form.name" type="text" maxlength="255" required :class="fieldInput" :placeholder="t('cycleCounts.namePlaceholder')" />
                    <p v-if="form.errors.name" :class="fieldError">{{ form.errors.name }}</p>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="cc-frequency" :class="fieldLabel">{{ t('cycleCounts.frequency') }}</label>
                        <select id="cc-frequency" v-model="form.frequency" :class="fieldInput">
                            <option v-for="f in frequencies" :key="f" :value="f">{{ t(`cycleCounts.frequencies.${f}`) }}</option>
                        </select>
                        <p v-if="form.errors.frequency" :class="fieldError">{{ form.errors.frequency }}</p>
                    </div>
                    <div>
                        <label for="cc-per-run" :class="fieldLabel">{{ t('cycleCounts.perRun') }}</label>
                        <input id="cc-per-run" v-model.number="form.products_per_run" type="number" min="1" max="1000" inputmode="numeric" required :class="fieldInput" />
                        <p class="mt-1 text-xs text-text-tertiary">{{ t('cycleCounts.perRunHelp') }}</p>
                        <p v-if="form.errors.products_per_run" :class="fieldError">{{ form.errors.products_per_run }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="cc-scope-type" :class="fieldLabel">{{ t('cycleCounts.scope') }}</label>
                        <select id="cc-scope-type" v-model="form.scope_type" :class="fieldInput">
                            <option v-for="s in scopes" :key="s" :value="s">{{ t(`cycleCounts.scopes.${s}`) }}</option>
                        </select>
                        <p v-if="form.errors.scope_type" :class="fieldError">{{ form.errors.scope_type }}</p>
                    </div>
                    <div v-if="form.scope_type !== 'all'">
                        <label for="cc-scope-id" :class="fieldLabel">{{ t(`cycleCounts.scopes.${form.scope_type}`) }}</label>
                        <select id="cc-scope-id" v-model="form.scope_id" :class="fieldInput" required>
                            <option :value="null" disabled>{{ t('cycleCounts.choose') }}</option>
                            <option v-for="o in scopeOptions" :key="o.id" :value="o.id">{{ o.code ? `${o.name} (${o.code})` : o.name }}</option>
                        </select>
                        <p v-if="form.errors.scope_id" :class="fieldError">{{ form.errors.scope_id }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="cc-assignee" :class="fieldLabel">{{ t('cycleCounts.assignee') }}</label>
                        <select id="cc-assignee" v-model="form.assigned_to" :class="fieldInput">
                            <option :value="null">{{ t('cycleCounts.unassigned') }}</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                        <p v-if="form.errors.assigned_to" :class="fieldError">{{ form.errors.assigned_to }}</p>
                    </div>
                    <div>
                        <label for="cc-next-run" :class="fieldLabel">{{ editing ? t('cycleCounts.nextRun') : t('cycleCounts.firstRun') }}</label>
                        <input id="cc-next-run" v-model="form.next_run_at" type="datetime-local" :class="fieldInput" />
                        <p class="mt-1 text-xs text-text-tertiary">{{ t('cycleCounts.firstRunHelp') }}</p>
                        <p v-if="form.errors.next_run_at" :class="fieldError">{{ form.errors.next_run_at }}</p>
                    </div>
                </div>

                <label class="flex min-h-11 items-center gap-3">
                    <input v-model="form.is_active" type="checkbox" class="h-5 w-5 rounded border-border-strong text-brand ds-focus-ring" />
                    <span class="text-sm font-medium text-text-primary">{{ t('cycleCounts.activeLabel') }}</span>
                </label>

                <div class="flex flex-wrap justify-end gap-2 border-t border-border-subtle pt-5">
                    <Button variant="secondary" size="lg" class="min-h-11" as="Link" :href="route('cycle-counts.index')">{{ t('common.cancel') }}</Button>
                    <Button type="submit" size="lg" class="min-h-11" :loading="form.processing" :disabled="form.processing">
                        {{ editing ? t('common.saveChanges') : t('cycleCounts.create') }}
                    </Button>
                </div>
            </form>
        </Card>
    </AppLayout>
</template>
