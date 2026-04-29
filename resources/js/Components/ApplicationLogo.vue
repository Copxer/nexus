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
    <!-- No `h-*` utility on the root: callers control height via the
         class they pass (`h-8`, `h-10`, etc.) and Vue's attribute
         inheritance merges that class onto the <img>. If we set
         `h-full` here it'd collide with the caller's `h-8` (same
         specificity, so cascade order decides — `h-full` was winning,
         making the image render at native size). `w-auto` preserves
         the wordmark/mark aspect ratio off the caller's height. -->
    <img
        :src="variant === 'mark' ? '/nexus-logo-small.png' : '/nexus-logo.png'"
        alt="Nexus"
        class="block w-auto select-none"
        draggable="false"
    />
</template>
