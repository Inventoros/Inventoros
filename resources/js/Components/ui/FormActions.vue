<script setup>
import Button from './Button.vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    label: { type: String, required: true },
    cancelHref: { type: String, required: true },
    processing: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    sticky: { type: Boolean, default: true },
});
const { t } = useI18n();

// The browser only scrolls a newly focused field when it is outside the
// viewport, so a field already on screen but partly under the sticky bar
// stays covered. Bring it clear (the scroll padding below leaves room).
const bar = ref(null);
const keepFocusClear = (event) => {
    const target = event.target;
    if (!props.sticky || !bar.value || !(target instanceof Element) || bar.value.contains(target)) return;
    const barTop = bar.value.getBoundingClientRect().top;
    const field = target.getBoundingClientRect();
    if (field.bottom > barTop && field.top < window.innerHeight) {
        target.scrollIntoView({ block: 'nearest', behavior: 'instant' });
    }
};
onMounted(() => document.addEventListener('focusin', keepFocusClear));
onBeforeUnmount(() => document.removeEventListener('focusin', keepFocusClear));
</script>

<template>
    <div ref="bar" class="form-actions z-20 mt-6 flex flex-wrap items-stretch justify-end gap-2 rounded-t-lg border border-border-subtle bg-surface-raised p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] shadow-sm" :class="{ 'sticky bottom-0': sticky }" :aria-busy="processing || undefined">
        <div v-if="$slots.hint" class="w-full text-xs text-text-secondary"><slot name="hint" /></div>
        <Button variant="secondary" as="Link" :href="cancelHref" class="h-auto min-h-11 flex-1 whitespace-normal py-2.5 sm:h-10 sm:min-h-10 sm:flex-none sm:py-0">{{ t('common.cancel') }}</Button>
        <slot name="actions" />
        <Button type="submit" :loading="processing" :disabled="processing || disabled" class="h-auto min-h-11 flex-1 whitespace-normal py-2.5 sm:h-10 sm:min-h-10 sm:flex-none sm:py-0">{{ label }}</Button>
    </div>
</template>

<style>
html:has(.form-actions) {
    scroll-padding-block-start: 7rem;
    scroll-padding-block-end: calc(7rem + env(safe-area-inset-bottom));
}
</style>
