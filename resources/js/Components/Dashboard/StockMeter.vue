<script setup>
import { computed } from 'vue';
import { stockUrgency } from '@/lib/dashboard';

// On hand against minimum: the filled part is the stock there is, the
// hatched part the shortfall still to cover. A level comes from
// stockLevel(); nothing here is a fixed value.
const props = defineProps({
    level: { type: Object, required: true },
    label: { type: String, required: true },
});

const filled = computed(() => {
    const { stock, minimum, percent } = props.level;
    if (minimum > 0) return percent;
    return stock > 0 ? 100 : 0;
});
const critical = computed(() => stockUrgency(props.level) === 'critical');
</script>

<template>
    <div
        class="stock-meter"
        :class="{ 'stock-meter-critical': critical, 'stock-meter-short': level.shortfall > 0 }"
        role="img"
        :aria-label="label"
    >
        <span class="stock-meter-fill" :style="{ width: `${filled}%` }"></span>
    </div>
</template>

<style>
.stock-meter {
    position: relative;
    height: 10px;
    overflow: hidden;
    border-radius: 999px;
    background: hsl(var(--surface-overlay));
}
.stock-meter-short {
    background-color: hsl(var(--surface-raised));
    background-image: repeating-linear-gradient(
        135deg,
        hsl(var(--border-strong)) 0 1.5px,
        transparent 1.5px 6px
    );
    box-shadow: inset 0 0 0 1px hsl(var(--border-subtle));
}
.stock-meter-fill {
    position: absolute;
    inset-block: 0;
    inset-inline-start: 0;
    border-radius: 999px;
    background: hsl(var(--status-warning));
}
.stock-meter-critical .stock-meter-fill {
    background: hsl(var(--status-danger));
}
@media (forced-colors: active) {
    .stock-meter {
        border: 1px solid CanvasText;
    }
    .stock-meter-fill {
        background: Highlight;
    }
}
</style>
