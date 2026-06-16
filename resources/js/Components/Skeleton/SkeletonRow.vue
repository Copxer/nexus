<script setup lang="ts">
import Skeleton from '@/Components/Skeleton/Skeleton.vue';

/**
 * Spec 036 — table-row skeleton (avatar + label/meta column +
 * trailing chip). Use inside an existing list/grid that owns the
 * row spacing.
 */
</script>

<template>
    <div
        class="flex items-center justify-between gap-3 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2"
        role="status"
        aria-label="Loading"
    >
        <div class="flex min-w-0 items-center gap-3">
            <Skeleton width="2rem" height="2rem" rounded="lg" />
            <div class="flex min-w-0 flex-col gap-1">
                <Skeleton width="9rem" height="0.875rem" />
                <Skeleton width="5rem" height="0.75rem" />
            </div>
        </div>
        <Skeleton width="4rem" height="1.25rem" rounded="full" />
        <span class="sr-only">Loading</span>
    </div>
</template>
