<script setup lang="ts">
import Sparkline from '@/Components/Dashboard/Sparkline.vue';
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import TrendChip from '@/Components/Dashboard/TrendChip.vue';
import type { LucideIcon } from 'lucide-vue-next';
import { computed } from 'vue';

type Accent = 'cyan' | 'blue' | 'purple' | 'magenta' | 'success' | 'danger';

const props = withDefaults(
    defineProps<{
        label: string;
        /** Big numeric (or short) value rendered in tabular nums. */
        value: string;
        /** Tiny secondary label beneath the value, e.g. "Successful", "Online". */
        secondary?: string;
        icon: LucideIcon;
        /** Drives the icon glow + sparkline accent. */
        accent?: Accent;
        /** Optional trend indicator next to the value. */
        trend?: { direction: 'up' | 'down' | 'flat'; value: string };
        /** Optional health pill rendered top-right of the card. */
        status?: 'success' | 'warning' | 'danger' | 'info';
        statusLabel?: string;
        /** Optional inline mini-sparkline data. */
        sparkline?: number[];
        /**
         * Click-to-detail target. When omitted (or `disabled` is true) the
         * card is rendered as inert with `aria-disabled` — mirrors the
         * sidebar/palette "Soon" treatment until target sections exist.
         */
        href?: string;
        disabled?: boolean;
    }>(),
    {
        accent: 'cyan',
        disabled: true,
    },
);

const isInert = computed(() => props.disabled || !props.href);

const accentIconClass: Record<Accent, string> = {
    cyan: 'text-accent-cyan shadow-[0_0_24px_rgba(34,211,238,0.45)]',
    blue: 'text-accent-blue shadow-[0_0_24px_rgba(56,189,248,0.45)]',
    purple: 'text-accent-purple shadow-[0_0_24px_rgba(139,92,246,0.45)]',
    magenta: 'text-accent-magenta shadow-[0_0_24px_rgba(217,70,239,0.45)]',
    success: 'text-status-success shadow-glow-success',
    danger: 'text-status-danger shadow-glow-danger',
};
</script>

<template>
    <!-- Inert variant renders as a plain <div>: not in the tab order, no
         aria-disabled (which would otherwise be announced as a "dimmed
         control" by screen readers and confuse the user). The interactive
         variant renders as an <a> with the focus ring + cursor. -->
    <component
        :is="isInert ? 'div' : 'a'"
        :href="!isInert ? href : undefined"
        :title="
            isInert
                ? `${label} detail view lands when the section ships`
                : undefined
        "
        class="glass-card group relative flex flex-col gap-4 p-4"
        :class="[
            isInert
                ? 'cursor-default'
                : 'cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60',
        ]"
    >
        <!-- Top row: icon (with accent glow) + status pill -->
        <div class="flex items-start justify-between gap-3">
            <span
                class="flex h-9 w-9 items-center justify-center rounded-xl border border-border-subtle bg-background-panel-hover transition group-hover:border-accent-cyan/30"
            >
                <component
                    :is="icon"
                    class="h-4 w-4 transition"
                    :class="accentIconClass[accent]"
                    aria-hidden="true"
                />
            </span>
            <StatusBadge v-if="status" :tone="status">
                {{ statusLabel ?? status }}
            </StatusBadge>
        </div>

        <!-- Value cluster -->
        <div class="flex flex-col gap-1">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <!-- Value font-size ramps with card width:
                       2-col mobile / 3-col tablet → text-2xl (220–290px cards)
                       3-col laptop (lg)            → text-3xl (~330px cards)
                       6-col xl                     → text-2xl (~210px cards)
                       6-col 2xl+                   → text-3xl (~250px+) -->
                <span
                    class="font-display text-2xl font-semibold tabular-nums text-text-primary lg:text-3xl xl:text-2xl 2xl:text-3xl"
                >
                    {{ value }}
                </span>
                <TrendChip
                    v-if="trend"
                    :direction="trend.direction"
                    :value="trend.value"
                />
            </div>
            <!-- Allow wrapping at < md so long secondary labels (e.g.
                 "100% healthy") don't get truncated mid-letter. -->
            <div
                class="flex flex-wrap items-baseline justify-between gap-x-2 gap-y-0.5 text-xs text-text-muted"
            >
                <span class="truncate">{{ label }}</span>
                <span
                    v-if="secondary"
                    class="shrink-0 text-text-secondary"
                >
                    {{ secondary }}
                </span>
            </div>
        </div>

        <!-- Sparkline footer -->
        <Sparkline
            v-if="sparkline && sparkline.length > 1"
            :points="sparkline"
            :accent="accent"
        />
    </component>
</template>
