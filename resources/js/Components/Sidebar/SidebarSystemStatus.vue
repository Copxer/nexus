<script setup lang="ts">
// Placeholder. Real data lands when we build the self-monitoring layer
// in spec 009 (Redis/Horizon/queue/scheduler wired up).
const status: 'ok' | 'warn' | 'down' = 'ok';

const labels = {
    ok: { dot: 'bg-status-success shadow-glow-success', text: 'All systems online' },
    warn: { dot: 'bg-status-warning', text: 'Degraded' },
    down: { dot: 'bg-status-danger shadow-glow-danger', text: 'Outage detected' },
};
</script>

<template>
    <div
        class="flex items-center gap-3 rounded-xl border border-border-subtle bg-slate-950/40 px-3 py-2"
    >
        <span class="relative flex h-2 w-2 shrink-0">
            <span
                class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-60"
                :class="labels[status].dot"
            />
            <span
                class="relative inline-flex h-2 w-2 rounded-full"
                :class="labels[status].dot"
            />
        </span>
        <div class="min-w-0">
            <p
                class="truncate text-[11px] font-semibold uppercase tracking-[0.18em] text-text-secondary"
            >
                System status
            </p>
            <p class="truncate text-xs text-text-muted">
                {{ labels[status].text }}
            </p>
        </div>
    </div>
</template>
