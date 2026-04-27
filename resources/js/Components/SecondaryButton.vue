<script setup lang="ts">
withDefaults(
    defineProps<{
        type?: 'button' | 'submit' | 'reset';
    }>(),
    {
        type: 'button',
    },
);
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center justify-center rounded-lg border border-border-subtle bg-slate-900/60 px-5 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus:ring-2 focus:ring-accent-cyan/40 disabled:opacity-40"
    >
        <slot />
    </button>
</template>
