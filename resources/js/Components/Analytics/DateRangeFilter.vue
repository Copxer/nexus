<script setup lang="ts">
/**
 * Spec 034 — three-option pill group (7d / 30d / 90d). The active
 * range is the source of truth in the URL; this component only emits
 * the user's new selection. The parent page handles the actual nav.
 */
export type RangeOption = '7d' | '30d' | '90d';

const props = withDefaults(
    defineProps<{
        value: RangeOption;
    }>(),
    {},
);

const emit = defineEmits<{
    (event: 'change', value: RangeOption): void;
}>();

const options: { value: RangeOption; label: string }[] = [
    { value: '7d', label: '7 days' },
    { value: '30d', label: '30 days' },
    { value: '90d', label: '90 days' },
];
</script>

<template>
    <div
        class="inline-flex items-center gap-1 rounded-full border border-border-subtle bg-background-panel-hover p-1"
        role="tablist"
        aria-label="Time range"
    >
        <button
            v-for="opt in options"
            :key="opt.value"
            type="button"
            role="tab"
            :aria-selected="props.value === opt.value"
            class="rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
            :class="
                props.value === opt.value
                    ? 'bg-accent-cyan/10 text-accent-cyan'
                    : 'text-text-muted hover:text-text-primary'
            "
            @click="emit('change', opt.value)"
        >
            {{ opt.label }}
        </button>
    </div>
</template>
