<script setup>
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';

defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    context: { type: String, default: '' },
    detail: { type: String, default: '' },
    extra: { type: String, default: '' },
    href: { type: String, default: null },
    icon: { type: [Object, Function], required: true },
});
</script>

<template>
    <component
        :is="href ? Link : 'article'"
        :href="href || undefined"
        class="dashboard-metric ds-focus-ring"
        :title="detail || undefined"
        :tabindex="href ? undefined : 0"
    >
        <div class="dashboard-metric-label">
            <component :is="icon" :size="15" :stroke-width="1.6" class="dashboard-metric-icon" aria-hidden="true" />
            <span>{{ label }}</span>
            <ArrowUpRight v-if="href" :size="13" class="dashboard-metric-link" aria-hidden="true" />
        </div>
        <p class="dashboard-metric-value">{{ value }}</p>
        <p v-if="context" class="dashboard-metric-context">{{ context }}</p>
        <p v-if="extra" class="dashboard-metric-context">{{ extra }}</p>
    </component>
</template>
