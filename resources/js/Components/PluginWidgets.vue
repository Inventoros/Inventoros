<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import { resolvePluginComponent } from '@/plugins/runtime';
import { pluginLabel } from '@/plugins/pluginI18n';

const { t, te } = useI18n();

/**
 * Dashboard widgets registered by plugins (register_dashboard_widget()).
 * The server only sends widgets the user may see, with their data resolved.
 */
const props = defineProps({
    widgets: {
        type: Array,
        default: () => [],
    },
});

const widthClass = {
    full: 'sm:col-span-2 lg:col-span-12',
    half: 'sm:col-span-2 lg:col-span-6',
    third: 'sm:col-span-2 lg:col-span-4',
    quarter: 'lg:col-span-3',
};

// A widget whose runtime bundle has not registered its component yet is left
// out and appears as soon as the bundle loads.
const rendered = computed(() =>
    (props.widgets ?? [])
        .map((widget) => ({
            ...widget,
            is: resolvePluginComponent(widget.plugin, widget.component),
            // title_key (plugins.{slug}.*) in the user's language, else the plain title.
            heading: pluginLabel(t, te, widget.title_key, widget.title),
        }))
        .filter((widget) => widget.is),
);
</script>

<template>
    <section v-if="rendered.length > 0" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-12">
        <Card
            v-for="widget in rendered"
            :key="widget.id"
            :padded="false"
            :class="widthClass[widget.width] ?? widthClass.full"
        >
            <div class="px-5 pt-5">
                <CardHeader :title="widget.heading" />
            </div>
            <div class="p-5">
                <component :is="widget.is" v-bind="widget.data || {}" />
            </div>
        </Card>
    </section>
</template>
