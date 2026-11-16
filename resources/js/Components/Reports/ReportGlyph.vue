<script setup>
import { computed } from 'vue';
import { reportGlyphs } from './reportGlyphs';

const props = defineProps({
    kind: { type: String, required: true, validator: value => value in reportGlyphs },
    size: { type: [Number, String], default: 48 },
});
const glyph = computed(() => reportGlyphs[props.kind] || reportGlyphs.builder);
</script>

<template>
    <svg
        :width="size" :height="size" viewBox="0 0 48 48" fill="none"
        stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"
        class="report-glyph" :data-report-glyph="kind" aria-hidden="true" focusable="false"
    >
        <path v-for="(path, index) in glyph.ink" :key="`ink-${index}`" :d="path" />
        <path v-for="(path, index) in glyph.accent" :key="`accent-${index}`" :d="path" class="report-glyph-accent" />
        <path v-for="(path, index) in glyph.solid" :key="`solid-${index}`" :d="path" class="report-glyph-solid" stroke="none" />
        <text v-for="label in glyph.labels" :key="label.text" :x="label.x" :y="label.y" text-anchor="middle" stroke="none" fill="currentColor">{{ label.text }}</text>
    </svg>
</template>

<style>
.report-glyph {
    display: block;
    flex-shrink: 0;
    color: hsl(var(--text-secondary));
}
.report-glyph-accent {
    color: hsl(var(--accent));
}
.report-glyph-solid {
    fill: hsl(var(--accent));
}
.report-glyph text {
    font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
    font-size: 8px;
    font-weight: 600;
}
@media (forced-colors: active) {
    .report-glyph, .report-glyph-accent {
        color: CanvasText;
    }
    .report-glyph-solid {
        fill: CanvasText;
    }
}
</style>
