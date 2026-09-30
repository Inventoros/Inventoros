<script setup>
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import InputError from '@/Components/InputError.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { displayDateTime } from '@/lib/dates';
import { CalendarClock, Plus, Pencil, Trash2, Pause, Play } from 'lucide-vue-next';

// Scheduled email delivery for a saved report. Rendered for the report's
// owner only; the server refuses schedule changes from anyone else.
const props = defineProps({
    reportId: { type: Number, required: true },
    schedules: { type: Array, default: () => [] },
    recipientOptions: { type: Array, default: () => [] },
    options: { type: Object, required: true },
});

const { t } = useI18n();

const showForm = ref(false);
const editingId = ref(null);

const blank = () => ({
    frequency: 'weekly',
    day_of_week: 1,
    day_of_month: 1,
    time_of_day: '08:00',
    format: 'csv',
    recipients: [],
});

const form = useForm(blank());

const openCreate = () => {
    editingId.value = null;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    showForm.value = true;
};

const openEdit = (schedule) => {
    editingId.value = schedule.id;
    form.frequency = schedule.frequency;
    form.day_of_week = schedule.day_of_week ?? 1;
    form.day_of_month = schedule.day_of_month ?? 1;
    form.time_of_day = schedule.time_of_day;
    form.format = schedule.format;
    form.recipients = [...schedule.recipients];
    form.clearErrors();
    showForm.value = true;
};

const close = () => {
    showForm.value = false;
    editingId.value = null;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: close };
    if (editingId.value) {
        form.put(route('reports.builder.schedules.update', [props.reportId, editingId.value]), options);
    } else {
        form.post(route('reports.builder.schedules.store', props.reportId), options);
    }
};

const payloadFor = (schedule, overrides = {}) => ({
    frequency: schedule.frequency,
    day_of_week: schedule.day_of_week,
    day_of_month: schedule.day_of_month,
    time_of_day: schedule.time_of_day,
    format: schedule.format,
    recipients: schedule.recipients,
    is_active: schedule.is_active,
    ...overrides,
});

const toggleActive = (schedule) => {
    router.put(
        route('reports.builder.schedules.update', [props.reportId, schedule.id]),
        payloadFor(schedule, { is_active: !schedule.is_active }),
        { preserveScroll: true }
    );
};

const destroy = (schedule) => {
    if (confirm(t('reportBuilder.schedules.confirmDelete'))) {
        router.delete(route('reports.builder.schedules.destroy', [props.reportId, schedule.id]), { preserveScroll: true });
    }
};

const describe = (schedule) => {
    const freq = t(`reportBuilder.schedules.frequencies.${schedule.frequency}`);
    if (schedule.frequency === 'weekly') {
        return `${freq}, ${t(`reportBuilder.schedules.days.${schedule.day_of_week}`)} ${schedule.time_of_day}`;
    }
    if (schedule.frequency === 'monthly') {
        return `${freq}, ${t('reportBuilder.schedules.dayOfMonth').toLowerCase()} ${schedule.day_of_month}, ${schedule.time_of_day}`;
    }
    return `${freq}, ${schedule.time_of_day}`;
};

const formatDateTime = (iso) => displayDateTime(iso);

const statusVariant = (status) => ({ sent: 'success', skipped: 'warning', failed: 'danger' }[status] || 'neutral');

const recipientErrors = computed(() => form.errors.recipients || Object.entries(form.errors).find(([k]) => k.startsWith('recipients.'))?.[1]);

const inputClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
const labelClass = 'mb-1 block text-xs font-medium text-text-secondary';
</script>

<template>
    <Card :padded="false" class="mt-4">
        <div class="px-5 pt-5">
            <CardHeader :title="t('reportBuilder.schedules.title')" :subtitle="t('reportBuilder.schedules.description')">
                <template #actions>
                    <Button v-if="!showForm" variant="secondary" size="sm" @click="openCreate">
                        <Plus :size="14" />
                        {{ t('reportBuilder.schedules.add') }}
                    </Button>
                </template>
            </CardHeader>
        </div>

        <div class="space-y-4 p-5">
            <form v-if="showForm" class="space-y-4 rounded-lg border border-border-subtle bg-surface-canvas p-4" @submit.prevent="submit">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div>
                        <label for="schedule-frequency" :class="labelClass">{{ t('reportBuilder.schedules.frequency') }}</label>
                        <select id="schedule-frequency" v-model="form.frequency" :class="inputClass">
                            <option v-for="f in options.frequencies" :key="f" :value="f">{{ t(`reportBuilder.schedules.frequencies.${f}`) }}</option>
                        </select>
                        <InputError :message="form.errors.frequency" class="mt-1" />
                    </div>
                    <div v-if="form.frequency === 'weekly'">
                        <label for="schedule-dow" :class="labelClass">{{ t('reportBuilder.schedules.dayOfWeek') }}</label>
                        <select id="schedule-dow" v-model.number="form.day_of_week" :class="inputClass">
                            <option v-for="d in [1, 2, 3, 4, 5, 6, 0]" :key="d" :value="d">{{ t(`reportBuilder.schedules.days.${d}`) }}</option>
                        </select>
                        <InputError :message="form.errors.day_of_week" class="mt-1" />
                    </div>
                    <div v-if="form.frequency === 'monthly'">
                        <label for="schedule-dom" :class="labelClass">{{ t('reportBuilder.schedules.dayOfMonth') }}</label>
                        <input id="schedule-dom" v-model.number="form.day_of_month" type="number" min="1" max="31" :class="inputClass" />
                        <p class="mt-1 text-xs text-text-tertiary">{{ t('reportBuilder.schedules.dayOfMonthHint') }}</p>
                        <InputError :message="form.errors.day_of_month" class="mt-1" />
                    </div>
                    <div>
                        <label for="schedule-time" :class="labelClass">{{ t('reportBuilder.schedules.time') }}</label>
                        <input id="schedule-time" v-model="form.time_of_day" type="time" step="900" :class="inputClass" />
                        <p class="mt-1 text-xs text-text-tertiary">{{ t('reportBuilder.schedules.timezone', { tz: options.timezone }) }}</p>
                        <InputError :message="form.errors.time_of_day" class="mt-1" />
                    </div>
                    <div>
                        <label for="schedule-format" :class="labelClass">{{ t('reportBuilder.schedules.format') }}</label>
                        <select id="schedule-format" v-model="form.format" :class="inputClass">
                            <option v-for="f in options.formats" :key="f" :value="f">{{ t(`reports.export.${f}`) }}</option>
                        </select>
                        <InputError :message="form.errors.format" class="mt-1" />
                    </div>
                </div>

                <fieldset>
                    <legend :class="labelClass">{{ t('reportBuilder.schedules.recipients') }}</legend>
                    <p class="mb-2 text-xs text-text-tertiary">{{ t('reportBuilder.schedules.recipientsHint') }}</p>
                    <div class="grid max-h-48 grid-cols-1 gap-1 overflow-y-auto sm:grid-cols-2">
                        <label
                            v-for="person in recipientOptions"
                            :key="person.id"
                            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-text-primary hover:bg-surface-overlay"
                        >
                            <input v-model="form.recipients" type="checkbox" :value="person.email.toLowerCase()" class="rounded border-border-subtle ds-focus-ring" />
                            <span class="truncate">{{ person.name }}</span>
                            <span class="truncate text-xs text-text-tertiary">{{ person.email }}</span>
                        </label>
                    </div>
                    <InputError :message="recipientErrors" class="mt-1" />
                </fieldset>

                <div class="flex items-center gap-2">
                    <Button type="submit" variant="default" size="sm" :loading="form.processing" :disabled="form.processing">
                        {{ t('reportBuilder.schedules.save') }}
                    </Button>
                    <Button type="button" variant="ghost" size="sm" @click="close">{{ t('reportBuilder.schedules.cancel') }}</Button>
                </div>
            </form>

            <div v-if="schedules.length === 0 && !showForm" class="flex flex-col items-center gap-2 py-6 text-center">
                <CalendarClock :size="24" class="text-text-tertiary" />
                <p class="text-sm text-text-tertiary">{{ t('reportBuilder.schedules.none') }}</p>
            </div>

            <ul v-else class="divide-y divide-border-subtle">
                <li v-for="schedule in schedules" :key="schedule.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-text-primary">
                            {{ describe(schedule) }}
                            <Badge variant="info" size="sm">{{ t(`reports.export.${schedule.format}`) }}</Badge>
                            <Badge :variant="schedule.is_active ? 'success' : 'neutral'" size="sm" dot>
                                {{ schedule.is_active ? t('reportBuilder.schedules.active') : t('reportBuilder.schedules.paused') }}
                            </Badge>
                        </p>
                        <p class="mt-0.5 truncate text-xs text-text-tertiary">{{ schedule.recipients.join(', ') }}</p>
                        <p class="mt-0.5 text-xs text-text-tertiary">
                            {{ t('reportBuilder.schedules.nextRun') }}: {{ schedule.is_active ? formatDateTime(schedule.next_run_at) : '-' }}
                            <template v-if="schedule.last_run_at">
                                · {{ t('reportBuilder.schedules.lastRun') }}: {{ formatDateTime(schedule.last_run_at) }}
                                <Badge v-if="schedule.last_status" :variant="statusVariant(schedule.last_status)" size="sm" class="ml-1">
                                    {{ t(`reportBuilder.schedules.status.${schedule.last_status}`) }}
                                </Badge>
                            </template>
                        </p>
                    </div>
                    <div class="flex items-center gap-1">
                        <Button variant="ghost" size="xs" @click="toggleActive(schedule)">
                            <component :is="schedule.is_active ? Pause : Play" :size="14" />
                            {{ schedule.is_active ? t('reportBuilder.schedules.pause') : t('reportBuilder.schedules.resume') }}
                        </Button>
                        <Button variant="ghost" size="xs" @click="openEdit(schedule)">
                            <Pencil :size="14" />
                            {{ t('reportBuilder.schedules.edit') }}
                        </Button>
                        <Button variant="ghost" size="xs" @click="destroy(schedule)">
                            <Trash2 :size="14" />
                            {{ t('reportBuilder.schedules.delete') }}
                        </Button>
                    </div>
                </li>
            </ul>
        </div>
    </Card>
</template>
