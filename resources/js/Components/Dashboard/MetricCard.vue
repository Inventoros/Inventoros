<script setup>
import { Link } from '@inertiajs/vue3';

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
            <span>{{ label }}</span>
            <span class="dashboard-metric-icon"><component :is="icon" :size="15" :stroke-width="1.6" aria-hidden="true" /></span>
        </div>
        <p class="dashboard-metric-value">{{ value }}</p>
        <p v-if="context" class="dashboard-metric-context">{{ context }}</p>
        <p v-if="extra" class="dashboard-metric-context">{{ extra }}</p>
    </component>
</template>
