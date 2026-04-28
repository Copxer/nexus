<script setup lang="ts">
import type { Command } from '@/lib/commands';

defineProps<{
    command: Command;
    selected: boolean;
}>();

defineEmits<{
    /** Mouse click on the row — parent decides whether to run (disabled rows are no-ops). */
    (e: 'select'): void;
}>();
</script>

<template>
    <li
        :id="`command-${command.id}`"
        role="option"
        :aria-selected="selected"
        :aria-disabled="command.disabled || undefined"
        class="group flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition"
        :class="[
            selected && !command.disabled
                ? 'bg-accent-cyan/10 text-text-primary shadow-[inset_2px_0_0_0_theme(colors.accent.cyan)]'
                : selected && command.disabled
                  ? 'cursor-not-allowed bg-background-panel-hover text-text-muted'
                  : command.disabled
                    ? 'cursor-not-allowed text-text-muted'
                    : 'text-text-secondary hover:bg-background-panel-hover hover:text-text-primary',
        ]"
        @click="!command.disabled && $emit('select')"
    >
        <component
            :is="command.icon"
            class="h-4 w-4 shrink-0 transition"
            :class="[
                selected && !command.disabled
                    ? 'text-accent-cyan'
                    : 'text-text-muted',
                !command.disabled &&
                    !selected &&
                    'group-hover:text-text-primary',
            ]"
            aria-hidden="true"
        />
        <span class="min-w-0 flex-1 truncate">{{ command.label }}</span>

        <!-- Right-side pill: "Soon" for disabled rows, shortcut hint or
             "Enter ↵" when the row is the active selection. -->
        <span
            v-if="command.disabled"
            class="ms-auto rounded-full border border-border-subtle px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.18em] text-text-muted"
        >
            {{ command.soonLabel ?? 'Soon' }}
        </span>
        <span
            v-else-if="command.shortcut"
            class="ms-auto font-mono text-[11px] text-text-muted"
        >
            {{ command.shortcut }}
        </span>
        <span
            v-else-if="selected"
            class="ms-auto inline-flex items-center gap-1 font-mono text-[11px] text-accent-cyan"
            aria-hidden="true"
        >
            Enter ↵
        </span>
    </li>
</template>
