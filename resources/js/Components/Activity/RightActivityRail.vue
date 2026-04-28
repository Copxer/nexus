<script setup lang="ts">
import { Activity, Filter, X } from 'lucide-vue-next';

defineProps<{
    /**
     * `column` is the desktop layout (≥ 2xl). `drawer` is the slide-over used
     * on tablet/laptop where the rail isn't always visible.
     */
    variant?: 'column' | 'drawer';
}>();

defineEmits<{
    (e: 'close'): void;
}>();
</script>

<template>
    <aside
        class="flex h-full flex-col gap-4 overflow-y-auto border-s border-border-subtle bg-background-panel px-4 py-6 backdrop-blur-xl"
        :class="variant === 'drawer' ? 'w-80' : 'w-80'"
        aria-label="Activity feed"
    >
        <header class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <Activity
                    class="h-4 w-4 text-accent-cyan"
                    aria-hidden="true"
                />
                <h2
                    class="text-[11px] font-semibold uppercase tracking-[0.32em] text-text-secondary"
                >
                    Activity
                </h2>
            </div>
            <div class="flex items-center gap-1">
                <button
                    type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-lg border border-border-subtle bg-slate-950/40 text-text-muted transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    aria-label="Filter activity"
                    disabled
                >
                    <Filter class="h-3.5 w-3.5" aria-hidden="true" />
                </button>
                <button
                    v-if="variant === 'drawer'"
                    type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-lg border border-border-subtle bg-slate-950/40 text-text-muted transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    aria-label="Close activity rail"
                    @click="$emit('close')"
                >
                    <X class="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            </div>
        </header>

        <!-- Empty state — real feed lands in spec 007 -->
        <div
            class="flex flex-1 flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-border-subtle bg-slate-950/40 px-4 py-10 text-center"
        >
            <span
                class="flex h-10 w-10 items-center justify-center rounded-full border border-border-subtle bg-slate-950/60"
            >
                <Activity
                    class="h-4 w-4 text-text-muted"
                    aria-hidden="true"
                />
            </span>
            <p class="text-sm font-medium text-text-secondary">
                No events yet
            </p>
            <p class="max-w-[220px] text-xs text-text-muted">
                Once integrations are connected, recent events will stream
                here.
            </p>
        </div>
    </aside>
</template>
