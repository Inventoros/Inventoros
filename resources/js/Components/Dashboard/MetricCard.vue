<script setup>
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight, Minus, TrendingDown, TrendingUp } from '@lucide/vue';

defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    context: { type: String, default: '' },
    detail: { type: String, default: '' },
    extra: { type: String, default: '' },
    href: { type: String, default: null },
    // Kept for callers and plugins; the tiles read by label, not icon.
    icon: { type: [Object, Function], default: null },
    // { text, direction: 'up' | 'down' | 'flat' }, only from a real comparison.
    trend: { type: Object, default: null },
    // The one highlighted figure on the dashboard.
    featured: { type: Boolean, default: false },
});
</script>

<template>
    <component
        :is="href ? Link : 'article'"
        :href="href || undefined"
        class="dashboard-metric ds-focus-ring"
        :class="{ 'dashboard-metric-featured': featured }"
        :title="detail || undefined"
        :tabindex="href ? undefined : 0"
    >
        <div class="dashboard-metric-label">
            <span>{{ label }}</span>
            <span v-if="href" class="dashboard-metric-link" aria-hidden="true"
                ><ArrowUpRight :size="featured ? 18 : 14" :stroke-width="1.6"
            /></span>
        </div>
        <p class="dashboard-metric-value">{{ value }}</p>
        <p v-if="context" class="dashboard-metric-context">{{ context }}</p>
        <p v-if="extra" class="dashboard-metric-context">{{ extra }}</p>
        <p v-if="trend" class="dashboard-metric-trend" :class="`dashboard-metric-trend-${trend.direction}`">
            <component
                :is="trend.direction === 'down' ? TrendingDown : trend.direction === 'up' ? TrendingUp : Minus"
                :size="13"
                aria-hidden="true"
            />{{ trend.text }}
        </p>
    </component>
</template>
