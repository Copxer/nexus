<script setup lang="ts">
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        direction: 'up' | 'down' | 'flat';
        /** Pre-formatted change string, e.g. "+18%", "-2.3%", "+0.01". */
        value: string;
    }>(),
    {
        direction: 'flat',
    },
);

const styles = computed(() => {
    if (props.direction === 'up') {
        return {
            icon: ArrowUpRight,
            className:
                'border-status-success/30 bg-status-success/10 text-status-success',
        };
    }
    if (props.direction === 'down') {
        return {
            icon: ArrowDownRight,
            className:
                'border-status-danger/30 bg-status-danger/10 text-status-danger',
        };
    }
    return {
        icon: Minus,
        className:
            'border-border-subtle bg-background-panel-hover text-text-muted',
    };
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 font-mono text-[11px] font-semibold tabular-nums"
        :class="styles.className"
    >
        <component :is="styles.icon" class="h-3 w-3" aria-hidden="true" />
        {{ value }}
    </span>
</template>
