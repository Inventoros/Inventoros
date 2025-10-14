<script setup>
import { defineAsyncComponent } from 'vue';

const props = defineProps({
    slot: {
        type: String,
        required: true,
    },
    components: {
        type: Array,
        default: () => [],
    },
});

// Load plugin components dynamically
const loadPluginComponent = (plugin, component) => {
    return defineAsyncComponent(() =>
        import(`../../../plugins/${plugin}/resources/js/Components/${component}.vue`)
    );
};
</script>

<template>
    <template v-if="components?.length > 0">
        <component
            v-for="(pluginComp, index) in components"
            :key="`${slot}-${index}`"
            :is="loadPluginComponent(pluginComp.plugin, pluginComp.component)"
            v-bind="pluginComp.data || {}"
        />
    </template>
</template>
