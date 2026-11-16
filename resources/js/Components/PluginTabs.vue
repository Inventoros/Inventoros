<script setup>
import { computed, ref } from 'vue';
import SectionTabs from './ui/SectionTabs.vue';
import SectionPanel from './ui/SectionPanel.vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { pluginLabel } from '@/plugins/pluginI18n';
import { pageIdFromComponent, resolvePluginComponent, runtimeSlotComponents } from '@/plugins/runtime';

/**
 * Plugin tabs for a detail page.
 *
 * Wraps the page's core content (the default slot). With no plugin tabs it
 * renders that content alone, so the page looks exactly as it does without
 * plugins. With at least one tab it adds a tab bar whose first tab,
 * "Overview", is the core content.
 *
 * Tabs come from the server (add_page_component($page, 'tabs', [..., 'label'
 * => 'Notes'])), already filtered by permission, or from a runtime bundle
 * (registerSlotComponent('<page>:tabs', Component, { label })).
 */
const props = defineProps({
    components: {
        type: Array,
        default: () => [],
    },
    page: {
        type: String,
        default: null,
    },
});

const { t, te } = useI18n();
const inertiaPage = usePage();
const pageId = computed(() => props.page ?? pageIdFromComponent(inertiaPage.component));

const OVERVIEW = 'overview';

const tabs = computed(() => {
    const placed = (props.components ?? [])
        .map((entry, index) => ({
            key: `server-${entry.plugin}-${entry.component}-${index}`,
            label: pluginLabel(t, te, entry.label_key, entry.label || entry.component),
            is: resolvePluginComponent(entry.plugin, entry.component),
            props: entry.data || {},
            position: entry.position ?? 100,
        }))
        .filter((tab) => tab.is);

    const registered = runtimeSlotComponents(pageId.value, 'tabs').map((entry) => ({
        key: `runtime-${entry.id}`,
        label: pluginLabel(t, te, entry.label_key, entry.label || entry.component?.name || 'Plugin'),
        is: entry.component,
        props: entry.props,
        position: entry.position,
    }));

    return [...placed, ...registered].sort((a, b) => a.position - b.position);
});

const active = ref(OVERVIEW);

const sections = computed(() => [
    { key: OVERVIEW, label: t('plugins.overviewTab') },
    ...tabs.value.map(({ key, label }) => ({ key, label })),
]);
</script>

<template>
    <slot v-if="tabs.length === 0" />
    <SectionTabs v-else v-model="active" :tabs="sections" :label="t('plugins.pageSections')" class="mt-6">
        <SectionPanel :tab="OVERVIEW">
            <slot />
        </SectionPanel>
        <SectionPanel v-for="tab in tabs" :key="tab.key" :tab="tab.key" class="mt-6">
            <component :is="tab.is" v-if="active === tab.key" v-bind="tab.props" />
        </SectionPanel>
    </SectionTabs>
</template>
