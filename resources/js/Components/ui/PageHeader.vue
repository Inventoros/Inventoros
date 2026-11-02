<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ChevronDown } from '@lucide/vue';

const { t } = useI18n();
const actions = ref(null);
const actionsToggle = ref(null);
const actionsOpen = ref(false);
const outside = (event) => {
    if (actionsOpen.value && !actions.value?.contains(event.target))
        actionsOpen.value = false;
};
const closeActions = () => {
    actionsOpen.value = false;
    actionsToggle.value?.focus();
};
const selectAction = () => {
    actionsOpen.value = false;
    // The selected item becomes hidden on a phone. Give dialogs a visible,
    // stable return target before their post-render opening watcher runs.
    if (actionsToggle.value?.getClientRects().length)
        actionsToggle.value.focus({ preventScroll: true });
};
onMounted(() => document.addEventListener('pointerdown', outside));
onBeforeUnmount(() => document.removeEventListener('pointerdown', outside));

defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
    eyebrow: { type: String, default: null },
});
</script>

<template>
    <header
        class="page-header flex flex-wrap items-start justify-between gap-x-6 gap-y-4 pb-6 border-b border-border-subtle"
    >
        <!--
            The title and the actions share a row while both fit. The title never
            shrinks below 16rem: when the actions need more room than is left they
            wrap onto their own line, and the actions themselves wrap, so a long
            row of buttons (or longer translated labels) never pushes the page
            wider than the viewport or squeezes the title to a word per line.
        -->
        <div class="min-w-0 flex-[1_1_16rem]">
            <p
                v-if="eyebrow"
                class="text-xs font-medium uppercase tracking-wider text-text-tertiary"
            >
                {{ eyebrow }}
            </p>
            <h1
                class="mt-0.5 text-xl font-semibold text-text-primary tracking-tight wrap-break-word"
            >
                {{ title }}
            </h1>
            <p v-if="description" class="mt-1 text-sm text-text-secondary">
                {{ description }}
            </p>
        </div>
        <div
            v-if="$slots.actions"
            ref="actions"
            class="page-header-actions flex min-w-0 max-w-full flex-wrap items-center gap-2 md:self-center"
            @keydown.esc.stop.prevent="closeActions"
        >
            <button
                ref="actionsToggle"
                type="button"
                class="page-header-action-toggle ds-focus-ring"
                :aria-expanded="actionsOpen"
                @click="actionsOpen = !actionsOpen"
            >
                {{ t('common.actions')
                }}<ChevronDown :size="14" aria-hidden="true" />
            </button>
            <div
                class="page-header-action-items"
                :class="{ 'is-open': actionsOpen }"
                @click="selectAction"
            >
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>
