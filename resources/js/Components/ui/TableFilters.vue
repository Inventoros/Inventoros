<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ChevronDown, SlidersHorizontal } from '@lucide/vue';

defineProps({ padded: { type: Boolean, default: true } });
const { t } = useI18n();
const expanded = ref(true);
const compact = ref(false);
const panel = ref(null);
let media;

const updateCompact = () => {
    compact.value = !media.matches;
    expanded.value = media.matches;
};
const close = () => {
    expanded.value = false;
    panel.value?.querySelector('summary')?.focus();
};
const outsideClick = (event) => {
    if (compact.value && expanded.value && !panel.value?.contains(event.target)) expanded.value = false;
};
onMounted(() => {
    media = window.matchMedia('(min-width: 768px) and (min-height: 700px)');
    updateCompact();
    media.addEventListener('change', updateCompact);
    document.addEventListener('pointerdown', outsideClick);
});
onUnmounted(() => {
    media?.removeEventListener('change', updateCompact);
    document.removeEventListener('pointerdown', outsideClick);
});
</script>

<template>
    <details ref="panel" :open="expanded" class="table-filters" @toggle="expanded = $event.target.open" @keydown.esc.stop.prevent="close">
        <summary class="ds-focus-ring">
            <SlidersHorizontal :size="14" aria-hidden="true" /><span>{{ t('common.filters') }}</span
            ><ChevronDown :size="14" class="table-filters-chevron" aria-hidden="true" />
        </summary>
        <div class="table-filters-content" :class="{ 'p-4': padded }" @submit="compact && (expanded = false)"><slot /></div>
    </details>
</template>
