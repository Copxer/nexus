<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        tone?: 'success' | 'warning' | 'danger' | 'info' | 'muted';
        /** When true, render only the dot (no pill chrome). For tight spaces. */
        dotOnly?: boolean;
    }>(),
    {
        tone: 'info',
        dotOnly: false,
    },
);

/**
 * Token classes per tone. Glow is reserved for `success` (per visual-reference
 * rule "glow only for active/healthy states"). Other tones get a flat
 * coloured dot. Pill chrome stays consistent across tones.
 */
const tones = {
    success: {
        dot: 'bg-status-success shadow-glow-success',
        pill: 'border-status-success/30 bg-status-success/10 text-status-success',
    },
    warning: {
        dot: 'bg-status-warning',
        pill: 'border-status-warning/30 bg-status-warning/10 text-status-warning',
    },
    danger: {
        dot: 'bg-status-danger',
        pill: 'border-status-danger/30 bg-status-danger/10 text-status-danger',
    },
    info: {
        dot: 'bg-status-info',
        pill: 'border-status-info/30 bg-status-info/10 text-status-info',
    },
    muted: {
        dot: 'bg-text-muted',
        pill: 'border-border-subtle bg-background-panel-hover text-text-muted',
    },
} as const;

const styles = computed(() => tones[props.tone]);
</script>

<template>
    <span
        v-if="dotOnly"
        class="inline-block h-2 w-2 shrink-0 rounded-full"
        :class="styles.dot"
        aria-hidden="true"
    />
    <span
        v-else
        class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.18em]"
        :class="styles.pill"
    >
        <span
            class="inline-block h-1.5 w-1.5 rounded-full"
            :class="styles.dot"
            aria-hidden="true"
        />
        <slot />
    </span>
</template>
