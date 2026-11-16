<script setup>
import { computed, nextTick, provide, ref, useId, watch } from 'vue';
import { sectionTabsKey } from './sectionTabsContext';

// Local page sections only. Links that navigate to another URL retain link
// semantics and should not be converted to ARIA tabs.
const props = defineProps({
    modelValue: { type: String, required: true },
    tabs: { type: Array, required: true },
    label: { type: String, required: true },
});
const emit = defineEmits(['update:modelValue']);
const id = `sections-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;
const list = ref(null);
const buttons = new Map();
const enabled = computed(() => props.tabs.filter(tab => !tab.disabled));
const selected = computed(() => enabled.value.some(tab => tab.key === props.modelValue)
    ? props.modelValue : enabled.value[0]?.key);
const tabId = key => `${id}-tab-${encodeURIComponent(key)}`;
const panelId = key => `${id}-panel-${encodeURIComponent(key)}`;
provide(sectionTabsKey, { selected, tabId, panelId });

watch([() => props.modelValue, () => enabled.value.map(tab => tab.key)], () => {
    if (selected.value !== undefined && selected.value !== props.modelValue) {
        const restoreFocus = list.value?.contains(document.activeElement);
        emit('update:modelValue', selected.value);
        if (restoreFocus) nextTick(() => buttons.get(selected.value)?.focus());
    }
}, { immediate: true });

const setButton = (key, element) => element ? buttons.set(key, element) : buttons.delete(key);
const move = async event => {
    const keys = enabled.value.map(tab => tab.key);
    if (!keys.length) return;
    const rtl = getComputedStyle(list.value).direction === 'rtl';
    let index = keys.indexOf(selected.value);
    if (event.key === 'Home') index = 0;
    else if (event.key === 'End') index = keys.length - 1;
    else if (['ArrowLeft', 'ArrowRight'].includes(event.key)) {
        const step = (event.key === 'ArrowRight' ? 1 : -1) * (rtl ? -1 : 1);
        index = (index + step + keys.length) % keys.length;
    } else return;
    event.preventDefault();
    emit('update:modelValue', keys[index]);
    await nextTick();
    buttons.get(keys[index])?.focus();
};
</script>

<template>
    <div class="section-tabs">
        <div
            ref="list" role="tablist" :aria-label="label" aria-orientation="horizontal"
            class="section-tab-list" @keydown="move"
        >
            <button
                v-for="tab in tabs"
                :key="tab.key"
                :ref="element => setButton(tab.key, element)"
                :id="tabId(tab.key)"
                type="button" role="tab"
                :aria-selected="selected === tab.key"
                :aria-controls="panelId(tab.key)"
                :tabindex="selected === tab.key ? 0 : -1"
                :disabled="tab.disabled" class="section-tab ds-focus-ring"
                @click="$emit('update:modelValue', tab.key)"
            >
                <span>{{ tab.label }}</span><slot name="badge" :tab="tab" />
            </button>
        </div>
        <slot />
    </div>
</template>

<style scoped>
.section-tabs { min-width: 0; }
.section-tab-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0 24px;
    border-bottom: 1px solid hsl(var(--border-subtle));
}
.section-tab {
    display: inline-flex;
    min-width: 0;
    min-height: 44px;
    align-items: center;
    gap: 8px;
    margin-bottom: -1px;
    padding: 10px 4px;
    border-bottom: 2px solid transparent;
    color: hsl(var(--text-secondary));
    font-size: 14px;
    font-weight: 500;
    text-align: start;
    overflow-wrap: anywhere;
}
.section-tab:hover { color: hsl(var(--text-primary)); }
.section-tab[aria-selected="true"] {
    border-color: hsl(var(--accent));
    color: hsl(var(--accent));
    font-weight: 600;
}
.section-tab:disabled { opacity: 0.5; cursor: not-allowed; }
@media (max-width: 639px) {
    .section-tab-list {
        gap: 4px 16px;
        border-bottom: 0;
    }
    .section-tab {
        max-width: 100%;
        margin-bottom: 0;
        border-color: hsl(var(--border-subtle));
    }
}
@media (forced-colors: active) {
    .section-tab[aria-selected="true"] { color: Highlight; border-color: Highlight; }
}
</style>
