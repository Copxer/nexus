<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

/**
 * Spec 036 — canonical empty-state. Drop into any list/grid that
 * can be empty so the user sees an intentional placeholder
 * (icon + headline + optional tip + optional CTA) instead of a
 * blank region.
 *
 * Designed for use inside an already-styled container (a
 * `glass-card` panel or a parent grid cell). The component itself
 * renders just the centered content stack.
 */
withDefaults(
    defineProps<{
        icon: LucideIcon;
        title: string;
        description?: string;
        action?: { label: string; href: string };
    }>(),
    {},
);
</script>

<template>
    <div
        class="flex flex-col items-center gap-3 rounded-lg border border-dashed border-border-subtle bg-background-panel-hover/40 px-4 py-8 text-center"
    >
        <component
            :is="icon"
            class="h-6 w-6 text-text-muted"
            aria-hidden="true"
        />
        <div class="flex flex-col gap-1">
            <p class="text-sm font-semibold text-text-primary">
                {{ title }}
            </p>
            <p
                v-if="description"
                class="text-[11px] text-text-muted"
            >
                {{ description }}
            </p>
        </div>
        <Link
            v-if="action"
            :href="action.href"
            class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
        >
            {{ action.label }}
        </Link>
    </div>
</template>
