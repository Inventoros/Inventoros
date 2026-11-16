<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { stockLevel } from '@/lib/dashboard';
import { formatNumber } from '@/lib/money';

const props = defineProps({ product: { type: Object, required: true } });
const { t } = useI18n();
const level = computed(() => stockLevel(props.product));
</script>

<template>
    <div class="stock-position" :class="{ 'stock-position-empty': level.stock <= 0 }">
        <div class="stock-position-values">
            <span><strong>{{ formatNumber(level.stock) }}</strong>{{ t('warehouses.stockLevels.onHand') }}</span>
            <span><strong>{{ formatNumber(level.minimum) }}</strong>{{ t('dashboard.design.minimum') }}</span>
        </div>
        <div
            class="stock-position-ruler"
            role="img"
            :aria-label="t('dashboard.design.stockLevel', { stock: formatNumber(level.stock), minimum: formatNumber(level.minimum) })"
        >
            <!-- The dashed part is the gap to minimum, never a history or forecast. -->
            <svg viewBox="0 0 240 20" preserveAspectRatio="none" aria-hidden="true">
                <path class="stock-position-gap" d="M0 10H240" />
                <path v-if="level.percent > 0" class="stock-position-fill" :d="`M0 10H${240 * level.percent / 100}`" />
                <path class="stock-position-zero" d="M0 5V15" />
                <path class="stock-position-minimum" d="M240 2V18" />
            </svg>
            <span class="stock-position-head" :style="{ insetInlineStart: `${level.percent}%` }" aria-hidden="true"></span>
        </div>
    </div>
</template>

<style>
.stock-position {
    min-width: 0;
}
.stock-position-values {
    display: flex;
    justify-content: space-between;
    gap: 12px;
}
.stock-position-values > span {
    display: flex;
    flex-direction: column;
    gap: 2px;
    color: hsl(var(--text-tertiary));
    font-size: 10px;
    min-width: 0;
    max-width: calc(50% - 6px);
}
.stock-position-values > span:last-child {
    text-align: end;
}
.stock-position-values strong {
    color: hsl(var(--text-primary));
    font-size: 23px;
    line-height: 28px;
    font-weight: 500;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}
.stock-position-values > span:first-child strong {
    color: hsl(var(--status-warning));
}
.stock-position-ruler {
    position: relative;
    height: 20px;
    margin: 2px 4px 0;
}
.stock-position-ruler svg {
    display: block;
    width: 100%;
    height: 20px;
    overflow: visible;
}
.stock-position-ruler path {
    vector-effect: non-scaling-stroke;
}
.stock-position-gap {
    fill: none;
    stroke: hsl(var(--border-strong));
    stroke-width: 2;
    stroke-dasharray: 2 4;
}
.stock-position-fill {
    fill: none;
    stroke: hsl(var(--status-warning));
    stroke-width: 4;
}
.stock-position-zero {
    fill: none;
    stroke: hsl(var(--border-strong));
    stroke-width: 1;
}
.stock-position-minimum {
    fill: none;
    stroke: hsl(var(--text-secondary));
    stroke-width: 2;
}
.stock-position-head {
    position: absolute;
    top: 6px;
    width: 8px;
    height: 8px;
    margin-inline-start: -4px;
    border-radius: 50%;
    border: 2px solid hsl(var(--status-warning));
    background: hsl(var(--surface-raised));
}
.stock-position-empty .stock-position-head {
    background: hsl(var(--status-warning));
}
[dir='rtl'] .stock-position-ruler svg {
    transform: scaleX(-1);
}
@media (forced-colors: active) {
    .stock-position-ruler path {
        stroke: CanvasText;
    }
    .stock-position-head {
        background: Canvas;
        border-color: CanvasText;
    }
    .stock-position-empty .stock-position-head {
        background: CanvasText;
    }
}
</style>
