<script setup>
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertCircle } from '@lucide/vue';

const props = defineProps({
    errors: { type: Object, default: () => ({}) },
    targets: { type: Object, default: () => ({}) },
});
const { t } = useI18n();
const panel = ref(null);
const headingId = `form-errors-${useId()}`;
const entries = computed(() => Object.entries(props.errors).filter(([, message]) => Boolean(message)));
function focusField(key) {
    const field = document.getElementById(props.targets[key]);
    if (!field) return;
    field.scrollIntoView({ block: 'center' });
    field.focus({ preventScroll: true });
}
defineExpose({ focus: () => panel.value?.focus() });
</script>

<template>
    <section v-if="entries.length" ref="panel" tabindex="-1" :aria-labelledby="headingId" :aria-describedby="`${headingId}-hint`" class="form-error-summary mb-4 rounded-lg border border-status-danger/40 bg-surface-raised p-4 text-text-primary ds-focus-ring">
        <div class="flex items-center gap-2"><AlertCircle :size="18" class="shrink-0 text-status-danger" aria-hidden="true" /><h2 :id="headingId" class="text-sm font-semibold">{{ t('components.formFeedback.title') }}</h2></div>
        <p :id="`${headingId}-hint`" class="mt-2 text-sm text-text-secondary">{{ t('components.formFeedback.hint') }}</p>
        <ul class="mt-3 list-disc space-y-2 ps-5 text-sm">
            <li v-for="[key, message] in entries" :key="key">
                <a v-if="targets[key]" :href="'#' + targets[key]" class="rounded text-brand underline underline-offset-2 ds-focus-ring" @click.prevent="focusField(key)">{{ message }}</a>
                <span v-else>{{ message }}</span>
            </li>
        </ul>
    </section>
</template>
