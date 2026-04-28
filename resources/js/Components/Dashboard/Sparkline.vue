<script setup lang="ts">
import { uniqueId } from '@/lib/uniqueId';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /** Series values. The line is drawn proportionally to min/max within `points`. */
        points: number[];
        /** Stroke + fill accent. Maps to design-token colors via accentRgb below. */
        accent?: 'cyan' | 'blue' | 'purple' | 'magenta' | 'success' | 'danger';
        /** Visual height of the SVG element in CSS pixels. */
        height?: number;
    }>(),
    {
        accent: 'cyan',
        height: 28,
    },
);

/**
 * Token RGB for stroke + gradient fill. Mirrors the values from
 * tailwind.config.js so the sparkline stays token-driven without pulling in
 * the JS color resolver.
 */
const accentRgb: Record<NonNullable<typeof props.accent>, string> = {
    cyan: '34, 211, 238',
    blue: '56, 189, 248',
    purple: '139, 92, 246',
    magenta: '217, 70, 239',
    success: '34, 197, 94',
    danger: '239, 68, 68',
};

const VIEW_W = 100;
const VIEW_H = 24;

const polyline = computed(() => {
    const pts = props.points;
    if (pts.length < 2) return '';
    const min = Math.min(...pts);
    const max = Math.max(...pts);
    const range = max - min || 1;
    const stepX = VIEW_W / (pts.length - 1);
    return pts
        .map((v, i) => {
            const x = (i * stepX).toFixed(2);
            const y = (VIEW_H - ((v - min) / range) * VIEW_H).toFixed(2);
            return `${x},${y}`;
        })
        .join(' ');
});

/** Closed polygon for the gradient fill — line + bottom-right corner + bottom-left corner. */
const area = computed(() => {
    if (!polyline.value) return '';
    return `${polyline.value} ${VIEW_W},${VIEW_H} 0,${VIEW_H}`;
});

const gradientId = uniqueId(`sparkline-${props.accent}`);

const stroke = computed(() => `rgb(${accentRgb[props.accent]})`);
const fillStart = computed(() => `rgba(${accentRgb[props.accent]}, 0.28)`);
const fillEnd = computed(() => `rgba(${accentRgb[props.accent]}, 0)`);
</script>

<template>
    <svg
        :viewBox="`0 0 ${VIEW_W} ${VIEW_H}`"
        :height="height"
        preserveAspectRatio="none"
        class="block w-full"
        aria-hidden="true"
        focusable="false"
    >
        <defs>
            <linearGradient :id="gradientId" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" :stop-color="fillStart" />
                <stop offset="100%" :stop-color="fillEnd" />
            </linearGradient>
        </defs>
        <polygon v-if="area" :points="area" :fill="`url(#${gradientId})`" />
        <polyline
            v-if="polyline"
            :points="polyline"
            fill="none"
            :stroke="stroke"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
        />
    </svg>
</template>
