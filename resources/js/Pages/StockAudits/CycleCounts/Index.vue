<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Plus, CalendarClock, Play, Pencil, Trash2, ArrowLeft } from 'lucide-vue-next';

const { t } = useI18n();

defineProps({
    schedules: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '-');

const scopeText = (schedule) =>
    schedule.scope_type === 'all'
        ? t('cycleCounts.scopes.all')
        : `${t(`cycleCounts.scopes.${schedule.scope_type}`)}: ${schedule.scope_label || '-'}`;

const runNow = (schedule) => {
    router.post(route('cycle-counts.run', schedule.id), {}, { preserveScroll: true });
};

const destroy = (schedule) => {
    if (confirm(t('cycleCounts.confirmDelete', { name: schedule.name }))) {
        router.delete(route('cycle-counts.destroy', schedule.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="t('cycleCounts.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('stock-audits.index')" class="text-text-tertiary hover:text-text-primary">Stock Audits</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('cycleCounts.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('cycleCounts.title')" :description="t('cycleCounts.description')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-audits.index')">
                    <ArrowLeft :size="14" />
                    {{ t('cycleCounts.backToAudits') }}
                </Button>
                <Button v-if="canManage" size="sm" as="Link" :href="route('cycle-counts.create')">
                    <Plus :size="14" />
                    {{ t('cycleCounts.new') }}
                </Button>
            </template>
        </PageHeader>

        <div class="mt-6 space-y-3">
            <Card v-if="schedules.length === 0">
                <div class="flex flex-col items-center gap-2 py-10 text-center">
                    <CalendarClock :size="32" class="text-text-tertiary" />
                    <p class="text-sm font-medium text-text-primary">{{ t('cycleCounts.empty') }}</p>
                    <p class="max-w-md text-xs text-text-tertiary">{{ t('cycleCounts.emptyHint') }}</p>
                    <Button v-if="canManage" size="lg" class="mt-2 min-h-11" as="Link" :href="route('cycle-counts.create')">
                        <Plus :size="16" />
                        {{ t('cycleCounts.new') }}
                    </Button>
                </div>
            </Card>

            <Card v-for="schedule in schedules" :key="schedule.id">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-text-primary">{{ schedule.name }}</h3>
                            <Badge size="sm">{{ t(`cycleCounts.frequencies.${schedule.frequency}`) }}</Badge>
                            <Badge :variant="schedule.is_active ? 'success' : 'neutral'" size="sm" dot>
                                {{ schedule.is_active ? t('cycleCounts.active') : t('cycleCounts.paused') }}
                            </Badge>
                        </div>
                        <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-1 text-xs text-text-secondary sm:grid-cols-2">
                            <div><dt class="inline text-text-tertiary">{{ t('cycleCounts.scope') }}:</dt> <dd class="inline">{{ scopeText(schedule) }}</dd></div>
                            <div><dt class="inline text-text-tertiary">{{ t('cycleCounts.perRun') }}:</dt> <dd class="inline">{{ schedule.products_per_run }}</dd></div>
                            <div><dt class="inline text-text-tertiary">{{ t('cycleCounts.assignee') }}:</dt> <dd class="inline">{{ schedule.assignee || t('cycleCounts.unassigned') }}</dd></div>
                            <div><dt class="inline text-text-tertiary">{{ t('cycleCounts.nextRun') }}:</dt> <dd class="inline">{{ schedule.is_active ? formatDate(schedule.next_run_at) : '-' }}</dd></div>
                            <div class="sm:col-span-2">
                                <dt class="inline text-text-tertiary">{{ t('cycleCounts.lastAudit') }}:</dt>
                                <dd class="inline">
                                    <Link v-if="schedule.last_audit" :href="route('stock-audits.show', schedule.last_audit.id)" class="text-brand hover:underline">
                                        {{ schedule.last_audit.audit_number }}
                                    </Link>
                                    <span v-else>-</span>
                                    <span v-if="schedule.last_run_at"> ({{ formatDate(schedule.last_run_at) }})</span>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div v-if="canManage" class="flex flex-wrap gap-2 md:shrink-0">
                        <Button variant="secondary" size="lg" class="min-h-11" @click="runNow(schedule)">
                            <Play :size="16" />
                            {{ t('cycleCounts.runNow') }}
                        </Button>
                        <Button variant="secondary" size="lg" class="min-h-11" as="Link" :href="route('cycle-counts.edit', schedule.id)">
                            <Pencil :size="16" />
                            {{ t('common.edit') }}
                        </Button>
                        <Button variant="ghost" size="lg" class="min-h-11" :aria-label="t('common.delete')" @click="destroy(schedule)">
                            <Trash2 :size="16" />
                        </Button>
                    </div>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
