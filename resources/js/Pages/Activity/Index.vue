<script setup lang="ts">
import ActivityFeed from '@/Components/Activity/ActivityFeed.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useActivityFeed } from '@/lib/useActivityFeed';
import type { ActivityEvent } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Activity, WifiOff } from 'lucide-vue-next';

const props = defineProps<{
    events: ActivityEvent[];
}>();

// Spec 019 — same composable as the rail. Seeds from the page's own
// 100-row payload and prepends Reverb broadcasts on top, capped at the
// page's wider limit.
const { events: liveEvents, connected: realtimeConnected } = useActivityFeed({
    seed: () => props.events,
    limit: 100,
});
</script>

<template>
    <Head title="Activity" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <span
                    class="text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan"
                >
                    Phase 3
                </span>
                <h1 class="text-lg font-semibold text-text-primary">Activity</h1>
            </div>
        </template>

        <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
            <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
                <div class="flex flex-col gap-2">
                    <h2 class="text-xl font-semibold text-text-primary">
                        Engineering activity
                    </h2>
                    <p class="text-sm text-text-secondary">
                        The latest events from your connected repositories.
                        Up to 100 entries shown — newer events stream in
                        live as webhooks land.
                    </p>
                </div>
                <span
                    v-if="realtimeConnected === false"
                    class="inline-flex items-center gap-1.5 rounded-full border border-status-warning/40 bg-status-warning/10 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-status-warning"
                    title="Live updates offline. Refresh to see the latest events."
                >
                    <WifiOff class="h-3 w-3" aria-hidden="true" />
                    Live updates offline
                </span>
            </header>

            <section class="glass-card p-5">
                <ActivityFeed
                    v-if="liveEvents.length > 0"
                    :events="liveEvents"
                />
                <div
                    v-else
                    class="flex flex-col items-center justify-center gap-3 px-6 py-12 text-center"
                >
                    <span
                        class="flex h-12 w-12 items-center justify-center rounded-full border border-border-subtle bg-slate-950/60"
                    >
                        <Activity
                            class="h-5 w-5 text-text-muted"
                            aria-hidden="true"
                        />
                    </span>
                    <p class="text-sm font-medium text-text-secondary">
                        No events yet
                    </p>
                    <p class="max-w-sm text-xs text-text-muted">
                        Connect a GitHub repository and create or update an
                        issue / pull request — events will land here once the
                        webhook fires.
                    </p>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
