<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ChevronDown, SlidersHorizontal } from '@lucide/vue';

defineProps({
    padded: { type: Boolean, default: true },
    label: { type: String, default: null },
    enabled: { type: Boolean, default: true },
});
const { t } = useI18n();
const page = usePage();
const expanded = ref(false);
const panel = ref(null);
const activeCount = computed(
    () =>
        Object.entries(page.props.filters || {}).filter(([key, value]) => {
            if (['per_page', 'page', 'sort_by', 'sort_dir'].includes(key)
                || [null, undefined, '', 'all'].includes(value)) return false;
            // Inertia serializes these switches as both booleans and strings.
            // A search for the literal word "false" is still a real filter.
            if (['out_of_stock', 'include_zero', 'low_stock'].includes(key)) {
                return [true, 1, '1', 'true'].includes(value);
            }
            return true;
        }).length,
);
const close = () => {
    expanded.value = false;
    panel.value?.querySelector('summary')?.focus();
};
const outsideClick = (event) => {
    if (expanded.value && !panel.value?.contains(event.target))
        expanded.value = false;
};
onMounted(() => {
    document.addEventListener('pointerdown', outsideClick);
});
onUnmounted(() => {
    document.removeEventListener('pointerdown', outsideClick);
});
</script>

<template>
    <div class="table-toolbar">
        <slot name="quick" />
        <details
            v-if="enabled"
            ref="panel"
            :open="expanded"
            class="table-filters"
            @toggle="expanded = $event.target.open"
            @keydown.esc.stop.prevent="close"
        >
            <summary class="ds-focus-ring">
                <SlidersHorizontal :size="14" aria-hidden="true" /><span>{{
                    label || t('common.filters')
                }}</span
                ><span v-if="activeCount" class="table-filter-count">{{
                    activeCount
                }}</span
                ><ChevronDown
                    :size="14"
                    class="table-filters-chevron"
                    aria-hidden="true"
                />
            </summary>
            <div
                class="table-filters-content"
                :class="{ 'p-4': padded }"
                @submit="expanded = false"
            >
                <slot />
            </div>
        </details>
    </div>
</template>
