<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import { computed } from 'vue';

/**
 * Spec 033 — project health score badge. Wraps `StatusBadge` with
 * the §14.2 band → tone mapping. Renders a muted "—" placeholder
 * when the project has never been scored (first-run state before
 * the scheduled sweep ticks). The score itself is shown as
 * `{score}/100 {label}` — e.g. `82/100 Good`, `25/100 Critical`.
 *
 * The band string comes from the backend (`health_band` Inertia
 * prop, computed by `HealthScoreBand::fromScore` in
 * `ProjectController::transform`) so the frontend doesn't need to
 * mirror the threshold table. If the band ever drifts from the
 * score's true band — eg. a manual DB poke — the badge follows the
 * band, not the score recomputed locally.
 */
const props = defineProps<{
    score: number | null;
    band: string | null;
}>();

type StatusTone = 'success' | 'warning' | 'danger' | 'info' | 'muted';

const tone = computed<StatusTone>(() => {
    switch (props.band) {
        case 'healthy':
            return 'success';
        case 'good':
            return 'info';
        case 'degraded':
        case 'warning':
            return 'warning';
        case 'critical':
            return 'danger';
        default:
            return 'muted';
    }
});

const label = computed<string>(() => {
    switch (props.band) {
        case 'healthy':
            return 'Healthy';
        case 'good':
            return 'Good';
        case 'degraded':
            return 'Degraded';
        case 'warning':
            return 'Warning';
        case 'critical':
            return 'Critical';
        default:
            return 'Unscored';
    }
});
</script>

<template>
    <StatusBadge :tone="tone">
        <template v-if="score === null">— Unscored</template>
        <template v-else>{{ score }}/100 {{ label }}</template>
    </StatusBadge>
</template>
