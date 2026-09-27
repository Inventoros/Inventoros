<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
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

const { t } = useI18n();
const inertiaPage = usePage();
const pageId = computed(() => props.page ?? pageIdFromComponent(inertiaPage.component));

const OVERVIEW = 'overview';

const tabs = computed(() => {
    const placed = (props.components ?? [])
        .map((entry, index) => ({
            key: `server-${entry.plugin}-${entry.component}-${index}`,
            label: entry.label || entry.component,
            is: resolvePluginComponent(entry.plugin, entry.component),
            props: entry.data || {},
            position: entry.position ?? 100,
        }))
        .filter((tab) => tab.is);

    const registered = runtimeSlotComponents(pageId.value, 'tabs').map((entry) => ({
        key: `runtime-${entry.id}`,
        label: entry.label || entry.component?.name || 'Plugin',
        is: entry.component,
        props: entry.props,
        position: entry.position,
    }));

    return [...placed, ...registered].sort((a, b) => a.position - b.position);
});

const active = ref(OVERVIEW);

// If the active plugin tab disappears (plugin bundle reloaded, navigation),
// fall back to the core content.
watch(tabs, (list) => {
    if (active.value !== OVERVIEW && !list.some((tab) => tab.key === active.value)) {
        active.value = OVERVIEW;
    }
});

const order = computed(() => [OVERVIEW, ...tabs.value.map((tab) => tab.key)]);
const tabRefs = ref({});

const focusSibling = (event, step) => {
    const keys = order.value;
    const next = keys[(keys.indexOf(active.value) + step + keys.length) % keys.length];
    active.value = next;
    tabRefs.value[next]?.focus();
    event.preventDefault();
};

const tabClass = (key) => [
    'ds-focus-ring -mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors',
    active.value === key
        ? 'border-brand text-text-primary'
        : 'border-transparent text-text-secondary hover:text-text-primary',
];
</script>

<template>
    <slot v-if="tabs.length === 0" />

    <div v-else>
        <div
            role="tablist"
            :aria-label="t('plugins.pageSections')"
            class="mt-6 flex gap-1 overflow-x-auto overflow-y-hidden border-b border-border-subtle"
            @keydown.right="focusSibling($event, 1)"
            @keydown.left="focusSibling($event, -1)"
        >
            <button
                :ref="(el) => (tabRefs[OVERVIEW] = el)"
                id="plugin-tab-overview"
                type="button"
                role="tab"
                :aria-selected="active === OVERVIEW"
                aria-controls="plugin-tabpanel-overview"
                :tabindex="active === OVERVIEW ? 0 : -1"
                :class="tabClass(OVERVIEW)"
                @click="active = OVERVIEW"
            >
                {{ t('plugins.overviewTab') }}
            </button>
            <button
                v-for="tab in tabs"
                :key="tab.key"
                :ref="(el) => (tabRefs[tab.key] = el)"
                :id="`plugin-tab-${tab.key}`"
                type="button"
                role="tab"
                :aria-selected="active === tab.key"
                :aria-controls="`plugin-tabpanel-${tab.key}`"
                :tabindex="active === tab.key ? 0 : -1"
                :class="tabClass(tab.key)"
                @click="active = tab.key"
            >
                {{ tab.label }}
            </button>
        </div>

        <!-- v-show keeps the core content mounted, so its state survives a tab switch. -->
        <div
            v-show="active === OVERVIEW"
            id="plugin-tabpanel-overview"
            role="tabpanel"
            aria-labelledby="plugin-tab-overview"
        >
            <slot />
        </div>
        <div
            v-for="tab in tabs"
            v-show="active === tab.key"
            :key="tab.key"
            :id="`plugin-tabpanel-${tab.key}`"
            role="tabpanel"
            :aria-labelledby="`plugin-tab-${tab.key}`"
            class="mt-6"
        >
            <component :is="tab.is" v-if="active === tab.key" v-bind="tab.props" />
        </div>
    </div>
</template>
