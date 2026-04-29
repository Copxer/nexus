<script setup lang="ts">
import ActivityFeed from '@/Components/Activity/ActivityFeed.vue';
import type { ActivityEvent } from '@/types';
import { Activity, Filter, WifiOff, X } from 'lucide-vue-next';

const props = withDefaults(
    defineProps<{
        /**
         * `column` is the desktop layout (≥ 2xl). `drawer` is the slide-over used
         * on tablet/laptop where the rail isn't always visible.
         */
        variant?: 'column' | 'drawer';
        /**
         * Optional populated feed. When omitted (or empty) the rail falls back
         * to the empty-state block; pages without an activity feed (Profile,
         * etc.) get the empty-state automatically.
         */
        events?: ActivityEvent[];
        /**
         * Reverb websocket connection state (spec 019). When `false` the
         * header shows a small "offline" pill so the user knows the page
         * is page-load-fresh-only and won't auto-update. When `null`
         * (default) realtime isn't being attempted on this page and the
         * pill stays hidden.
         */
        realtimeConnected?: boolean | null;
    }>(),
    {
        variant: 'column',
        events: () => [],
        realtimeConnected: null,
    },
);

defineEmits<{
    (e: 'close'): void;
}>();

const hasEvents = () => props.events.length > 0;
</script>

<template>
    <!-- Cap drawer width to 88vw at < sm so the backdrop stays a generous
         click target on small phones (360px viewport → ~43px backdrop). -->
    <aside
        class="flex h-full w-80 max-w-[88vw] flex-col gap-4 overflow-y-auto border-s border-border-subtle bg-background-panel px-4 py-6 backdrop-blur-xl"
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
                <span
                    v-if="realtimeConnected === false"
                    class="inline-flex items-center gap-1 rounded-full border border-status-warning/40 bg-status-warning/10 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.18em] text-status-warning"
                    title="Live updates offline. Refresh the page to see the latest events."
                >
                    <WifiOff class="h-3 w-3" aria-hidden="true" />
                    Offline
                </span>
            </div>
            <div class="flex items-center gap-1">
                <button
                    type="button"
                    class="flex h-8 w-8 cursor-not-allowed items-center justify-center rounded-lg border border-border-subtle bg-slate-950/40 text-text-muted transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    aria-label="Filter activity"
                    aria-disabled="true"
                    title="Activity filtering arrives when real integrations land."
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

        <!-- Populated feed when events were forwarded from the page; otherwise
             the empty-state block shipped in spec 004 (used on Profile/Edit
             and any future page that doesn't supply events). -->
        <ActivityFeed v-if="hasEvents()" :events="events" />
        <div
            v-else
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
