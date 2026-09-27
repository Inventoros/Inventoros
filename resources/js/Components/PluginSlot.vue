<script setup>
import { computed, defineAsyncComponent } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { pageIdFromComponent, runtimeComponent, runtimeSlotComponents } from '@/plugins/runtime';

const props = defineProps({
    slot: {
        type: String,
        required: true,
    },
    // Placements from the server (add_page_component), for this page and slot.
    components: {
        type: Array,
        default: () => [],
    },
    // Page identifier used for browser-registered components; defaults to the
    // current Inertia page ("Products/Show" -> "products.show").
    page: {
        type: String,
        default: null,
    },
});

// Components of plugins that were present when the app was built
// (plugins/{slug}/resources/js/Components/{Name}.vue).
const buildTimeComponents = import.meta.glob('../../../plugins/*/resources/js/Components/*.vue');
const asyncComponents = new Map();

const buildTimeComponent = (plugin, component) => {
    const path = `../../../plugins/${plugin}/resources/js/Components/${component}.vue`;
    if (!buildTimeComponents[path]) {
        return null;
    }
    if (!asyncComponents.has(path)) {
        asyncComponents.set(path, defineAsyncComponent(buildTimeComponents[path]));
    }
    return asyncComponents.get(path);
};

const inertiaPage = usePage();
const pageId = computed(() => props.page ?? pageIdFromComponent(inertiaPage.component));

const rendered = computed(() => {
    // Server placements: a runtime bundle's registration wins, falling back to
    // a build-time component. Until a runtime bundle has loaded, the entry is
    // skipped and appears as soon as the bundle registers it.
    const placed = (props.components ?? [])
        .map((entry, index) => ({
            key: `server-${entry.plugin}-${entry.component}-${index}`,
            is: runtimeComponent(entry.plugin, entry.component) ?? buildTimeComponent(entry.plugin, entry.component),
            props: entry.data || {},
            position: entry.position ?? 100,
        }))
        .filter((entry) => entry.is);

    const registered = runtimeSlotComponents(pageId.value, props.slot).map((entry) => ({
        key: `runtime-${entry.id}`,
        is: entry.component,
        props: entry.props,
        position: entry.position,
    }));

    return [...placed, ...registered].sort((a, b) => a.position - b.position);
});
</script>

<template>
    <template v-if="rendered.length > 0">
        <component
            v-for="item in rendered"
            :key="item.key"
            :is="item.is"
            v-bind="item.props"
        />
    </template>
</template>
