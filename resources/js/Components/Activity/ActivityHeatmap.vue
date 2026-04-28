<script setup lang="ts">
import type { ActivityHeatmapPayload } from '@/types';
import { computed } from 'vue';

const props = defineProps<{
    /** 7 days × 6 four-hour buckets. Each value is an event count. */
    data: ActivityHeatmapPayload;
}>();

/** Column labels — Sunday-first, matching `Date#getDay()` indexing. */
const dayLabels = ['S', 'M', 'T', 'W', 'T', 'F', 'S'] as const;
const dayNames = [
    'Sun',
    'Mon',
    'Tue',
    'Wed',
    'Thu',
    'Fri',
    'Sat',
] as const;

/** Row labels — six 4-hour buckets per §8.11 example. */
const rowLabels = ['12 AM', '4 AM', '8 AM', '12 PM', '4 PM', '8 PM'] as const;

/** Max count across all cells; used to normalize each cell into a 0..4 bucket. */
const maxCount = computed(() => {
    let m = 0;
    for (const day of props.data) {
        for (const c of day) if (c > m) m = c;
    }
    return m || 1;
});

/**
 * Map a count to one of 5 intensity steps. The ramp goes panel-hover →
 * accent-purple/15 → /40 → accent-magenta/55 → /80 to match the §11 visual
 * reference. Returns the Tailwind class for the bucket.
 */
const bucketClasses = [
    'bg-background-panel-hover',
    'bg-accent-purple/15',
    'bg-accent-purple/40',
    'bg-accent-magenta/55',
    'bg-accent-magenta/80',
] as const;

const cellClass = (count: number) => {
    if (count <= 0) return bucketClasses[0];
    const pct = count / maxCount.value;
    const idx = Math.min(4, Math.max(1, Math.ceil(pct * 4)));
    return bucketClasses[idx];
};
</script>

<template>
    <figure
        role="img"
        :aria-label="`Activity heatmap, 7 days by six 4-hour buckets, peak ${maxCount} events`"
        class="flex flex-col gap-3"
    >
        <!-- Grid: a `min-content` label column + 7 fixed day cols. We avoid
             `auto` for the label column because grid greedily expands it to
             absorb any leftover container width, pushing the cells right.
             `w-fit` on the figure keeps the whole heatmap left-aligned. -->
        <div
            class="grid w-fit grid-cols-[min-content_repeat(7,32px)] gap-1.5 sm:grid-cols-[min-content_repeat(7,40px)]"
        >
            <!-- Header row: empty corner + day initials -->
            <div aria-hidden="true" />
            <div
                v-for="(day, i) in dayLabels"
                :key="`day-${i}`"
                class="text-center font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
            >
                {{ day }}
            </div>

            <!-- Data rows: row label + 7 cells -->
            <template v-for="(rowLabel, rowIdx) in rowLabels" :key="rowLabel">
                <div
                    class="pe-2 text-end font-mono text-[10px] tabular-nums text-text-muted"
                >
                    {{ rowLabel }}
                </div>
                <div
                    v-for="(_, dayIdx) in dayLabels"
                    :key="`${rowLabel}-${dayIdx}`"
                    :title="`${dayNames[dayIdx]} ${rowLabel} — ${data[dayIdx]?.[rowIdx] ?? 0} events`"
                    :aria-label="`${dayNames[dayIdx]} ${rowLabel} — ${data[dayIdx]?.[rowIdx] ?? 0} events`"
                    class="aspect-square rounded border border-border-subtle/40 transition hover:border-accent-cyan/60"
                    :class="cellClass(data[dayIdx]?.[rowIdx] ?? 0)"
                />
            </template>
        </div>

        <!-- Legend strip -->
        <figcaption
            class="ms-auto flex items-center gap-2 font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
        >
            Less
            <span class="flex items-center gap-1">
                <span
                    v-for="bucket in bucketClasses"
                    :key="bucket"
                    class="h-2.5 w-2.5 rounded-sm border border-border-subtle/40"
                    :class="bucket"
                />
            </span>
            More
        </figcaption>
    </figure>
</template>
