<script setup lang="ts">
import { Search } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

defineEmits<{
    /** User clicked or pressed Enter/Space — AppLayout opens the palette. */
    (e: 'open-palette'): void;
}>();

const isMac = ref(false);

onMounted(() => {
    // navigator.platform is deprecated but still universally implemented; the
    // newer userAgentData API isn't on Safari yet. Either signal is fine here
    // since we're only choosing between two visual hint strings.
    const platform =
        (navigator as Navigator & {
            userAgentData?: { platform?: string };
        }).userAgentData?.platform ?? navigator.platform;
    isMac.value = /mac|iphone|ipad|ipod/i.test(platform ?? '');
});
</script>

<template>
    <button
        type="button"
        aria-label="Search and run commands"
        aria-keyshortcuts="Meta+K Control+K"
        class="relative hidden h-9 items-center rounded-lg border border-border-subtle bg-slate-950/60 ps-9 pe-2 text-sm text-text-muted shadow-inner shadow-black/20 transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60 md:inline-flex md:w-64 lg:w-72"
        @click="$emit('open-palette')"
    >
        <Search
            class="pointer-events-none absolute start-3 h-4 w-4"
            aria-hidden="true"
        />
        <span class="min-w-0 flex-1 truncate whitespace-nowrap text-start">
            Search or run a command…
        </span>
        <kbd
            class="ms-2 hidden shrink-0 rounded border border-border-subtle bg-slate-950/40 px-1.5 py-0.5 font-mono text-[11px] text-text-muted lg:inline-block"
        >
            {{ isMac ? '⌘K' : 'Ctrl K' }}
        </kbd>
    </button>
</template>
