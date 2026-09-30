<script setup>
/**
 * ImportResult renders the structured result an importer flashes when a run
 * had row errors or warnings: { type, message, stats: { imported, updated?,
 * skipped?, failed?, errors: [{row, errors}], warnings: [{row, warnings}] } }.
 *
 * The global FlashMessages toast only shows plain strings, so without this
 * the per-row detail (including warnings such as duplicate-SKU skips on an
 * otherwise successful import) would never reach the user.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '@/Components/ui/Card.vue';
import { AlertTriangle, Check, RefreshCw, SkipForward, X, Info } from 'lucide-vue-next';

const props = defineProps({
    result: {
        type: Object,
        required: true,
    },
});

defineEmits(['dismiss']);

const { t } = useI18n();

const stats = computed(() => props.result.stats || {});
const errors = computed(() => stats.value.errors || []);
const warnings = computed(() => stats.value.warnings || []);

const counts = computed(() => [
    { key: 'imported', label: t('importExport.result.imported'), icon: Check, tone: 'text-status-success' },
    { key: 'updated', label: t('importExport.result.updated'), icon: RefreshCw, tone: 'text-status-info' },
    { key: 'skipped', label: t('importExport.result.skipped'), icon: SkipForward, tone: 'text-status-warning' },
    { key: 'failed', label: t('importExport.result.failed'), icon: X, tone: 'text-status-danger' },
].filter((count) => typeof stats.value[count.key] === 'number'));
</script>

<template>
    <Card class="border-status-warning/20 bg-status-warning-soft" role="alert">
        <div class="flex items-start gap-3">
            <AlertTriangle :size="20" class="shrink-0 text-status-warning" />
            <div class="min-w-0 flex-1">
                <div class="flex items-start justify-between gap-3">
                    <p class="font-medium text-status-warning">{{ result.message }}</p>
                    <button
                        type="button"
                        class="rounded-md p-1 text-text-tertiary transition-colors hover:text-text-primary ds-focus-ring"
                        :aria-label="t('importExport.result.dismiss')"
                        @click="$emit('dismiss')"
                    >
                        <X :size="16" />
                    </button>
                </div>

                <div v-if="counts.length" class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                    <span v-for="count in counts" :key="count.key" class="inline-flex items-center gap-1.5">
                        <component :is="count.icon" :size="14" :class="count.tone" />
                        <span :class="count.tone">{{ count.label }}:</span>
                        <span class="tabular-nums text-text-secondary">{{ stats[count.key] }}</span>
                    </span>
                </div>

                <div v-if="errors.length" class="mt-4">
                    <p class="mb-1 text-sm font-medium text-status-danger">
                        {{ t('importExport.result.errorRows', { count: errors.length }, errors.length) }}
                    </p>
                    <ul class="max-h-48 space-y-1 overflow-y-auto">
                        <li
                            v-for="(error, index) in errors"
                            :key="`e-${index}`"
                            class="rounded bg-status-danger-soft px-2 py-1 text-xs text-status-danger"
                        >
                            <span class="font-medium">{{ t('importExport.result.row', { row: error.row }) }}</span>
                            {{ error.errors.join(' ') }}
                        </li>
                    </ul>
                </div>

                <div v-if="warnings.length" class="mt-4">
                    <p class="mb-1 text-sm font-medium text-status-warning">
                        {{ t('importExport.result.warningRows', { count: warnings.length }, warnings.length) }}
                    </p>
                    <ul class="max-h-48 space-y-1 overflow-y-auto">
                        <li
                            v-for="(warning, index) in warnings"
                            :key="`w-${index}`"
                            class="flex items-start gap-1.5 rounded bg-surface-raised px-2 py-1 text-xs text-text-secondary"
                        >
                            <Info :size="12" class="mt-0.5 shrink-0 text-status-warning" />
                            <span>
                                <span class="font-medium text-text-primary">{{ t('importExport.result.row', { row: warning.row }) }}</span>
                                {{ warning.warnings.join(' ') }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </Card>
</template>
