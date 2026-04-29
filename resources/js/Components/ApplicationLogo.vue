<script setup lang="ts">
withDefaults(
    defineProps<{
        /**
         * `wordmark` (default) — the wide `nexus-logo.png` (~3.55:1).
         * Best for surfaces with horizontal room: sidebar header,
         * GuestLayout, Welcome top bar at sm+.
         *
         * `mark` — the compact `nexus-logo-small.png` (~1.27:1, near-
         * square). Best for narrow surfaces: mobile top bars, future
         * icon-only collapsed sidebar, anywhere a wordmark would
         * crowd siblings.
         */
        variant?: 'wordmark' | 'mark';
    }>(),
    { variant: 'wordmark' },
);
</script>

<template>
    <img
        :src="variant === 'mark' ? '/nexus-logo-small.png' : '/nexus-logo.png'"
        alt="Nexus"
        class="block h-full w-auto select-none"
        draggable="false"
    />
</template>
