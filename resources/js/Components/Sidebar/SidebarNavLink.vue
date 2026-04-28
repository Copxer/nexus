<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        href?: string;
        active?: boolean;
        disabled?: boolean;
        soonLabel?: string;
        icon: Component;
    }>(),
    {
        href: '#',
        active: false,
        disabled: false,
        soonLabel: 'Soon',
    },
);
</script>

<template>
    <component
        :is="disabled ? 'span' : Link"
        :href="disabled ? undefined : href"
        :aria-disabled="disabled || undefined"
        :tabindex="disabled ? -1 : undefined"
        class="group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition"
        :class="[
            active
                ? 'bg-accent-cyan/10 text-text-primary shadow-[inset_2px_0_0_0_theme(colors.accent.cyan)]'
                : disabled
                  ? 'cursor-not-allowed text-text-muted'
                  : 'text-text-secondary hover:bg-background-panel-hover hover:text-text-primary',
        ]"
    >
        <component
            :is="icon"
            class="h-4 w-4 shrink-0 transition"
            :class="[
                active ? 'text-accent-cyan' : 'text-text-muted',
                !disabled && !active && 'group-hover:text-text-primary',
            ]"
            aria-hidden="true"
        />
        <span class="truncate">
            <slot />
        </span>
        <span
            v-if="disabled"
            class="ms-auto rounded-full border border-border-subtle px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.18em] text-text-muted"
        >
            {{ soonLabel }}
        </span>
    </component>
</template>
