<script setup lang="ts">
import DateRangeFilter, {
    type RangeOption,
} from '@/Components/Analytics/DateRangeFilter.vue';
import KpiCard from '@/Components/Dashboard/KpiCard.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import {
    Bell,
    Cpu,
    Gauge,
    Globe,
    HeartPulse,
    MemoryStick,
    Rocket,
    ShieldCheck,
    Timer,
} from 'lucide-vue-next';
import { computed } from 'vue';

type Status = 'success' | 'warning' | 'danger' | 'info' | 'muted';

interface DeploymentMetrics {
    frequency: { total: number; sparkline: number[] };
    success_rate: { percent: number | null; status: Status };
}

interface AlertMetrics {
    frequency: { total: number; sparkline: number[] };
    mttr: {
        seconds: number | null;
        label: string | null;
        status: Status;
    };
}

interface WebsiteMetrics {
    uptime: {
        percent: number | null;
        sparkline: number[];
        status: Status;
    };
    response_time: {
        avg_ms: number | null;
        sparkline: Array<number | null>;
        status: Status;
    };
}

interface ContainerMetrics {
    cpu: {
        percent: number | null;
        sparkline: Array<number | null>;
        status: Status;
    };
    memory: {
        percent: number | null;
        sparkline: Array<number | null>;
        status: Status;
    };
}

const props = defineProps<{
    filters: { range: RangeOption };
    metrics: {
        deployments: DeploymentMetrics;
        alerts: AlertMetrics;
        websites: WebsiteMetrics;
        containers: ContainerMetrics;
    };
}>();

const onRangeChange = (range: RangeOption) => {
    if (range === props.filters.range) return;
    router.visit(route('analytics.index'), {
        data: { range },
        preserveScroll: true,
        preserveState: false,
    });
};

const rangeLabel = computed<string>(() =>
    props.filters.range === '7d'
        ? 'last 7 days'
        : props.filters.range === '30d'
          ? 'last 30 days'
          : 'last 90 days',
);

// Sparkline expects `number[]`. Map nulls → 0 for cards whose
// underlying source can have empty days. This collapses "no data"
// into "zero" visually — acceptable for trend strips at this size;
// the headline value still shows the honest `—` when null.
const fillNulls = (series: Array<number | null>): number[] =>
    series.map((v) => (v === null ? 0 : v));

const formatPercent = (p: number | null, suffix = '%'): string =>
    p === null ? '—' : `${p}${suffix}`;
const formatMs = (m: number | null): string =>
    m === null ? '—' : `${m}ms`;
const formatInt = (n: number | null): string =>
    n === null ? '—' : `${n}`;
</script>

<template>
    <Head title="Analytics" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <h1 class="text-lg font-semibold text-text-primary">
                    Analytics
                </h1>
            </div>
        </template>

        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex flex-col gap-1">
                    <h2 class="text-xl font-semibold text-text-primary">
                        System trends — {{ rangeLabel }}
                    </h2>
                    <p class="text-sm text-text-secondary">
                        Real data from deployments, alerts, monitors, and
                        Docker hosts. Refresh the page for the latest
                        aggregates.
                    </p>
                </div>
                <DateRangeFilter
                    :value="props.filters.range"
                    @change="onRangeChange"
                />
            </header>

            <div
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"
            >
                <KpiCard
                    label="Deployment frequency"
                    :value="formatInt(props.metrics.deployments.frequency.total)"
                    secondary="Completed runs"
                    :icon="Rocket"
                    accent="blue"
                    :sparkline="props.metrics.deployments.frequency.sparkline"
                />
                <KpiCard
                    label="Deployment success"
                    :value="formatPercent(props.metrics.deployments.success_rate.percent)"
                    secondary="Success / (success + failure)"
                    :icon="ShieldCheck"
                    accent="success"
                    :status="props.metrics.deployments.success_rate.status"
                    :status-label="props.metrics.deployments.success_rate.percent === null ? 'No data' : 'Last range'"
                />
                <KpiCard
                    label="Alert frequency"
                    :value="formatInt(props.metrics.alerts.frequency.total)"
                    secondary="Triggered in range"
                    :icon="Bell"
                    accent="magenta"
                    :sparkline="props.metrics.alerts.frequency.sparkline"
                />
                <KpiCard
                    label="Mean time to recovery"
                    :value="props.metrics.alerts.mttr.label ?? '—'"
                    secondary="Resolved alerts only"
                    :icon="Timer"
                    accent="purple"
                    :status="props.metrics.alerts.mttr.status"
                    :status-label="props.metrics.alerts.mttr.seconds === null ? 'No data' : 'Avg'"
                />
                <KpiCard
                    label="Website uptime"
                    :value="formatPercent(props.metrics.websites.uptime.percent)"
                    secondary="Volume-weighted"
                    :icon="Globe"
                    accent="cyan"
                    :status="props.metrics.websites.uptime.status"
                    :status-label="props.metrics.websites.uptime.percent === null ? 'No data' : 'Range'"
                    :sparkline="props.metrics.websites.uptime.sparkline"
                />
                <KpiCard
                    label="Response time"
                    :value="formatMs(props.metrics.websites.response_time.avg_ms)"
                    secondary="Average over up checks"
                    :icon="Gauge"
                    accent="cyan"
                    :status="props.metrics.websites.response_time.status"
                    :status-label="props.metrics.websites.response_time.avg_ms === null ? 'No data' : 'Avg'"
                    :sparkline="fillNulls(props.metrics.websites.response_time.sparkline)"
                />
                <KpiCard
                    label="Container CPU"
                    :value="formatPercent(props.metrics.containers.cpu.percent)"
                    secondary="Across user hosts"
                    :icon="Cpu"
                    accent="purple"
                    :status="props.metrics.containers.cpu.status"
                    :status-label="props.metrics.containers.cpu.percent === null ? 'No data' : 'Avg'"
                    :sparkline="fillNulls(props.metrics.containers.cpu.sparkline)"
                />
                <KpiCard
                    label="Container memory"
                    :value="formatPercent(props.metrics.containers.memory.percent)"
                    secondary="Across user hosts"
                    :icon="MemoryStick"
                    accent="magenta"
                    :status="props.metrics.containers.memory.status"
                    :status-label="props.metrics.containers.memory.percent === null ? 'No data' : 'Avg'"
                    :sparkline="fillNulls(props.metrics.containers.memory.sparkline)"
                />
            </div>
        </div>
    </AppLayout>
</template>
