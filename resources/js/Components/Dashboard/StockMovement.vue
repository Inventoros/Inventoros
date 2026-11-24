<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArrowUpRight } from '@lucide/vue';
import { movementSummary } from '@/lib/dashboard';
import { displayCalendarDate } from '@/lib/dates';
import { formatNumber } from '@/lib/money';

// Units received above the baseline, units out below it, one pair of bars
// per day. Every figure comes from stock adjustments; days with none are
// zero, never interpolated.
const props = defineProps({
    series: { type: Array, default: () => [] },
    href: { type: String, default: null },
});
const { t } = useI18n();

const WIDTH = 300;
const HALF = 44;
const summary = computed(() => movementSummary(props.series));
const slot = computed(() => (summary.value.days.length ? WIDTH / summary.value.days.length : WIDTH));
const barWidth = computed(() => Math.max(2, slot.value - 3));
const scale = (value) => (summary.value.peak > 0 ? (value / summary.value.peak) * HALF : 0);
const first = computed(() => summary.value.days[0]?.date);
const last = computed(() => summary.value.days.at(-1)?.date);
const hasMovement = computed(() => summary.value.totalIn > 0 || summary.value.totalOut > 0);
const label = computed(() =>
    t('dashboard.design.movementSummary', { in: formatNumber(summary.value.totalIn), out: formatNumber(summary.value.totalOut) }),
);
</script>

<template>
    <section class="dashboard-panel stock-movement" aria-labelledby="movement-title">
        <div class="dashboard-panel-heading">
            <div class="dashboard-panel-title">
                <h2 id="movement-title">{{ t('dashboard.design.stockMovement') }}</h2>
                <span class="stock-movement-period">{{ t('dashboard.design.last30Days') }}</span>
            </div>
            <Link v-if="href" :href="href" class="dashboard-text-link ds-focus-ring"
                >{{ t('nav.stockAdjustments') }}<ArrowUpRight :size="14" aria-hidden="true"
            /></Link>
        </div>
        <div class="stock-movement-body">
            <dl class="stock-movement-totals">
                <div>
                    <dt><span class="stock-movement-key stock-movement-key-in" aria-hidden="true"></span>{{ t('dashboard.design.unitsIn') }}</dt>
                    <dd>{{ formatNumber(summary.totalIn) }}</dd>
                </div>
                <div>
                    <dt><span class="stock-movement-key stock-movement-key-out" aria-hidden="true"></span>{{ t('dashboard.design.unitsOut') }}</dt>
                    <dd>{{ formatNumber(summary.totalOut) }}</dd>
                </div>
            </dl>
            <p v-if="!hasMovement" class="stock-movement-empty">{{ t('dashboard.design.noMovement') }}</p>
            <template v-else>
                <svg class="stock-movement-chart" :viewBox="`0 0 ${WIDTH} ${HALF * 2 + 2}`" preserveAspectRatio="none" role="img" :aria-label="label">
                    <g v-for="(day, index) in summary.days" :key="day.date">
                        <title>{{ displayCalendarDate(day.date) }}: +{{ formatNumber(day.in) }} / -{{ formatNumber(day.out) }}</title>
                        <rect
                            v-if="day.in > 0"
                            class="stock-movement-in"
                            :x="index * slot"
                            :y="HALF - scale(day.in)"
                            :width="barWidth"
                            :height="Math.max(1, scale(day.in))"
                        />
                        <rect
                            v-if="day.out > 0"
                            class="stock-movement-out"
                            :x="index * slot"
                            :y="HALF + 2"
                            :width="barWidth"
                            :height="Math.max(1, scale(day.out))"
                        />
                    </g>
                    <line class="stock-movement-baseline" x1="0" :x2="WIDTH" :y1="HALF + 1" :y2="HALF + 1" />
                </svg>
                <div class="stock-movement-axis" aria-hidden="true">
                    <span>{{ displayCalendarDate(first) }}</span><span>{{ displayCalendarDate(last) }}</span>
                </div>
            </template>
        </div>
    </section>
</template>

<style>
.stock-movement {
    display: flex;
    flex-direction: column;
}
.stock-movement-period {
    color: hsl(var(--text-tertiary));
    font-size: 11px;
}
.stock-movement-body {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 14px;
    padding: 16px 20px 18px;
}
.stock-movement-totals {
    display: flex;
    gap: 28px;
}
.stock-movement-totals dt {
    display: flex;
    align-items: center;
    gap: 6px;
    color: hsl(var(--text-tertiary));
    font-size: 11px;
}
.stock-movement-totals dd {
    margin-top: 2px;
    font-size: 20px;
    font-weight: 600;
    letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
}
.stock-movement-key {
    width: 8px;
    height: 8px;
    border-radius: 2px;
}
.stock-movement-key-in,
.stock-movement-in {
    background: hsl(var(--accent));
    fill: hsl(var(--accent));
}
.stock-movement-key-out,
.stock-movement-out {
    background: hsl(var(--text-tertiary));
    fill: hsl(var(--text-tertiary));
}
.stock-movement-chart {
    display: block;
    flex: 1 1 112px;
    width: 100%;
    min-height: 112px;
    max-height: 220px;
}
.stock-movement-chart g:hover rect {
    opacity: 0.75;
}
.stock-movement-baseline {
    stroke: hsl(var(--border-strong));
    stroke-width: 1;
    vector-effect: non-scaling-stroke;
}
.stock-movement-axis {
    display: flex;
    justify-content: space-between;
    color: hsl(var(--text-tertiary));
    font-size: 10px;
    font-variant-numeric: tabular-nums;
}
.stock-movement-empty {
    margin-top: auto;
    color: hsl(var(--text-tertiary));
    font-size: 12px;
}
[dir='rtl'] .stock-movement-chart {
    transform: scaleX(-1);
}
@media (forced-colors: active) {
    .stock-movement-in,
    .stock-movement-key-in {
        fill: Highlight;
        background: Highlight;
    }
    .stock-movement-out,
    .stock-movement-key-out {
        fill: CanvasText;
        background: CanvasText;
    }
}
</style>
