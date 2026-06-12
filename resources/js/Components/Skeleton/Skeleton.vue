<script setup lang="ts">
/**
 * Spec 036 — base shimmer primitive. Renders a single
 * `skeleton-shimmer` block at the given width / height. The
 * shimmer keyframes live in `resources/css/app.css` so the rule
 * collapses correctly under `prefers-reduced-motion: reduce`.
 */
withDefaults(
    defineProps<{
        width?: string;
        height?: string;
        rounded?: 'none' | 'sm' | 'md' | 'lg' | 'full';
        class?: string;
    }>(),
    {
        width: '100%',
        height: '1rem',
        rounded: 'md',
    },
);

const roundedClass: Record<string, string> = {
    none: 'rounded-none',
    sm: 'rounded-sm',
    md: 'rounded-md',
    lg: 'rounded-lg',
    full: 'rounded-full',
};
</script>

<template>
    <span
        :class="['block skeleton-shimmer', roundedClass[rounded], $props.class]"
        :style="{ width, height }"
        aria-hidden="true"
    />
</template>
