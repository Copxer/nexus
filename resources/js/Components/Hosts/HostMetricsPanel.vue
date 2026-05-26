<script setup lang="ts">
import { Activity, ArrowDown, ArrowUp } from 'lucide-vue-next';
import { computed } from 'vue';

interface CurrentMetrics {
    cpu_percent: number | null;
    memory_used_mb: number | null;
    memory_total_mb: number | null;
    memory_percent: number | null;
    disk_used_gb: number | null;
    disk_total_gb: number | null;
    load_average: number | null;
    network_rx_bytes: number | null;
    network_tx_bytes: number | null;
    recorded_at: string | null;
}

const props = defineProps<{
    current: CurrentMetrics;
}>();

const diskPercent = computed<number | null>(() => {
    const used = props.current.disk_used_gb;
    const total = props.current.disk_total_gb;
    if (used === null || total === null || total === 0) return null;
    return Math.round((used / total) * 1000) / 10;
});

// <70 healthy, 70–90 warning, ≥90 danger — same thresholds the
// Overview host card uses.
const usageBarClass = (pct: number | null): string => {
    if (pct === null) return 'bg-text-muted';
    if (pct >= 90) return 'bg-status-danger';
    if (pct >= 70) return 'bg-status-warning';
    return 'bg-status-success';
};

const clampWidth = (pct: number | null): string =>
    pct === null ? '0%' : `${Math.min(Math.max(pct, 0), 100)}%`;

const formatPercent = (pct: number | null): string =>
    pct === null ? '—' : `${pct}%`;

const formatGb = (mb: number | null): string =>
    mb === null ? '—' : `${(mb / 1024).toFixed(1)} GB`;

const formatBytes = (bytes: number | null): string => {
    if (bytes === null) return '—';
    if (bytes === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(
        Math.floor(Math.log(bytes) / Math.log(1024)),
        units.length - 1,
    );
    return `${(bytes / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
};

interface Bar {
    label: string;
    percent: number | null;
    detail: string;
}

const bars = computed<Bar[]>(() => [
    {
        label: 'CPU',
        percent: props.current.cpu_percent,
        detail: formatPercent(props.current.cpu_percent),
    },
    {
        label: 'Memory',
        percent: props.current.memory_percent,
        detail: `${formatGb(props.current.memory_used_mb)} / ${formatGb(props.current.memory_total_mb)}`,
    },
    {
        label: 'Disk',
        percent: diskPercent.value,
        detail:
            props.current.disk_used_gb !== null &&
            props.current.disk_total_gb !== null
                ? `${props.current.disk_used_gb} / ${props.current.disk_total_gb} GB`
                : '—',
    },
]);
</script>

<template>
    <section class="glass-card flex flex-col gap-4 p-6">
        <header class="flex items-center justify-between gap-3">
            <h3
                class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.2em] text-text-secondary"
            >
                <Activity class="h-3.5 w-3.5 text-accent-cyan" aria-hidden="true" />
                Host metrics
            </h3>
            <span v-if="current.recorded_at" class="text-[11px] text-text-muted">
                as of {{ new Date(current.recorded_at).toLocaleString() }}
            </span>
        </header>

        <div class="flex flex-col gap-3">
            <div v-for="bar in bars" :key="bar.label" class="flex flex-col gap-1">
                <div class="flex items-baseline justify-between text-xs">
                    <span
                        class="font-mono uppercase tracking-[0.18em] text-text-muted"
                    >
                        {{ bar.label }}
                    </span>
                    <span class="text-text-secondary">
                        {{ bar.detail }}
                        <span class="text-text-muted">
                            · {{ formatPercent(bar.percent) }}
                        </span>
                    </span>
                </div>
                <div
                    class="h-1.5 w-full overflow-hidden rounded-full bg-background-panel-hover"
                >
                    <div
                        class="h-full rounded-full transition-[width]"
                        :class="usageBarClass(bar.percent)"
                        :style="{ width: clampWidth(bar.percent) }"
                    />
                </div>
            </div>
        </div>

        <dl
            class="grid grid-cols-3 gap-4 border-t border-border-subtle pt-4 text-xs"
        >
            <div class="flex flex-col gap-1">
                <dt class="uppercase tracking-[0.18em] text-text-muted">
                    Load avg
                </dt>
                <dd class="font-mono text-sm text-text-primary">
                    {{
                        current.load_average !== null
                            ? current.load_average.toFixed(2)
                            : '—'
                    }}
                </dd>
            </div>
            <div class="flex flex-col gap-1">
                <dt
                    class="flex items-center gap-1 uppercase tracking-[0.18em] text-text-muted"
                >
                    <ArrowDown class="h-3 w-3" aria-hidden="true" />
                    Net in
                </dt>
                <dd class="font-mono text-sm text-text-primary">
                    {{ formatBytes(current.network_rx_bytes) }}
                </dd>
            </div>
            <div class="flex flex-col gap-1">
                <dt
                    class="flex items-center gap-1 uppercase tracking-[0.18em] text-text-muted"
                >
                    <ArrowUp class="h-3 w-3" aria-hidden="true" />
                    Net out
                </dt>
                <dd class="font-mono text-sm text-text-primary">
                    {{ formatBytes(current.network_tx_bytes) }}
                </dd>
            </div>
        </dl>
    </section>
</template>
