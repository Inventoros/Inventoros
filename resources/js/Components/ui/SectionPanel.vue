<script setup>
import { inject } from 'vue';
import { sectionTabsKey } from './sectionTabsContext';

defineProps({ tab: { type: String, required: true } });
const tabs = inject(sectionTabsKey);
if (!tabs) throw new Error('SectionPanel must be inside SectionTabs.');
</script>

<template>
    <!-- Keep form state mounted when the user visits another local section. -->
    <div
        v-show="tabs.selected.value === tab"
        :id="tabs.panelId(tab)"
        role="tabpanel"
        :aria-labelledby="tabs.tabId(tab)"
        tabindex="0"
        class="section-panel ds-focus-ring"
    >
        <slot />
    </div>
</template>

<style scoped>
.section-panel { min-width: 0; }
</style>
