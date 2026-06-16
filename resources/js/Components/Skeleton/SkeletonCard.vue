<script setup lang="ts">
import Skeleton from '@/Components/Skeleton/Skeleton.vue';

/**
 * Spec 036 — KpiCard-shaped skeleton. Renders the same chrome
 * (glass-card with the icon corner + label + value + sparkline
 * strip) so the initial paint matches the final layout.
 */
</script>

<template>
    <div
        class="glass-card flex flex-col gap-3 p-5"
        role="status"
        aria-label="Loading"
    >
        <div class="flex items-start justify-between gap-3">
            <Skeleton width="2.25rem" height="2.25rem" rounded="lg" />
            <Skeleton width="3.5rem" height="1rem" rounded="full" />
        </div>
        <Skeleton width="60%" height="0.75rem" />
        <Skeleton width="45%" height="1.75rem" />
        <Skeleton width="100%" height="1.75rem" rounded="sm" />
        <span class="sr-only">Loading</span>
    </div>
</template>
