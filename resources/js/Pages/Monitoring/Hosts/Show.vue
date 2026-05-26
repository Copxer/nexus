<script setup lang="ts">
import Sparkline from '@/Components/Dashboard/Sparkline.vue';
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import AgentTokenPanel from '@/Components/Hosts/AgentTokenPanel.vue';
import ContainerTable from '@/Components/Hosts/ContainerTable.vue';
import HostMetricsPanel from '@/Components/Hosts/HostMetricsPanel.vue';
import { hostStatusTone as statusTone } from '@/lib/hostStyles';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { PageProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ChevronLeft,
    Clock,
    ExternalLink,
    PencilLine,
    Trash2,
    WifiOff,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface ActiveAgentToken {
    id: number;
    name: string | null;
    last_used_at: string | null;
    created_at: string | null;
}

interface ProjectChip {
    id: number;
    slug: string;
    name: string;
    color: string | null;
    icon: string | null;
}

interface HostPayload {
    id: number;
    name: string;
    slug: string;
    provider: string | null;
    endpoint_url: string | null;
    connection_type: string | null;
    status:
        | 'pending'
        | 'online'
        | 'offline'
        | 'degraded'
        | 'archived'
        | string
        | null;
    last_seen_at: string | null;
    cpu_count: number | null;
    memory_total_mb: number | null;
    disk_total_gb: number | null;
    os: string | null;
    docker_version: string | null;
    cpu_percent: number | null;
    memory_percent: number | null;
    project: ProjectChip | null;
    active_agent_token: ActiveAgentToken | null;
}

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

interface SeriesPoint {
    cpu_percent: number | null;
    memory_percent: number | null;
    recorded_at: string | null;
}

interface ContainerRow {
    id: number;
    container_id: string;
    name: string;
    image: string;
    image_tag: string | null;
    status: string | null;
    state: string | null;
    health_status: string | null;
    cpu_percent: number | null;
    memory_usage_mb: number | null;
    memory_limit_mb: number | null;
    memory_percent: number | null;
    last_seen_at: string | null;
}

interface TelemetryShape {
    current: CurrentMetrics | null;
    series: SeriesPoint[];
    containers: ContainerRow[];
}

const props = defineProps<{
    host: HostPayload;
    telemetry: TelemetryShape;
    canUpdate: boolean;
    canDelete: boolean;
    canManageTokens: boolean;
}>();

const page = usePage<PageProps>();

const archive = () => {
    if (
        !window.confirm(
            'Archive this host? Existing agent tokens will be revoked. Telemetry history is kept.',
        )
    ) {
        return;
    }
    router.delete(route('monitoring.hosts.destroy', props.host.id));
};

// ─── Sparkline series (leading-null skip + carry-forward) ───────────
// Same pattern as spec 025's response-time sparkline: nulls inherit
// the previous known value once one exists, so a brief metric gap
// doesn't pull the chart to 0.
const sparklineFrom = (
    pick: (point: SeriesPoint) => number | null,
): number[] => {
    const points: number[] = [];
    let last: number | null = null;
    for (const point of props.telemetry.series) {
        const value = pick(point);
        if (value !== null) {
            last = value;
        }
        if (last === null) continue;
        points.push(last);
    }
    return points;
};

const cpuSeries = computed<number[]>(() =>
    sparklineFrom((p) => p.cpu_percent),
);
const memorySeries = computed<number[]>(() =>
    sparklineFrom((p) => p.memory_percent),
);

const currentCpu = computed<number | null>(
    () => props.telemetry.current?.cpu_percent ?? null,
);
const currentMemory = computed<number | null>(
    () => props.telemetry.current?.memory_percent ?? null,
);

// ─── Reverb subscription (spec 028) ──────────────────────────────────
// `HostTelemetryRecorded` fires on `users.{ownerId}.hosts` when an
// agent posts telemetry. Filter client-side by host_id and partial-
// reload host + telemetry props on a match. Mirrors spec 025.
const realtimeConnected = ref<boolean | null>(null);
let teardown: (() => void) | null = null;

onMounted(() => {
    if (typeof window === 'undefined' || !window.Echo) {
        return;
    }
    const userId = page.props.auth?.user?.id;
    if (userId == null) return;

    const channelName = `users.${userId}.hosts`;
    const channel = window.Echo.private(channelName);

    channel.listen(
        '.HostTelemetryRecorded',
        (payload: { host_id: number }) => {
            if (payload.host_id !== props.host.id) return;
            router.reload({ only: ['host', 'telemetry'] });
        },
    );

    const connector = window.Echo.connector;
    const pusher = (
        connector as {
            pusher?: {
                connection?: {
                    state?: string;
                    bind: (e: string, cb: () => void) => void;
                    unbind: (e: string, cb: () => void) => void;
                };
            };
        }
    )?.pusher;

    const onConnect = () => {
        realtimeConnected.value = true;
    };
    const onDisconnect = () => {
        realtimeConnected.value = false;
    };

    if (pusher?.connection) {
        if (pusher.connection.state === 'connected') {
            realtimeConnected.value = true;
        }
        pusher.connection.bind('connected', onConnect);
        pusher.connection.bind('disconnected', onDisconnect);
        pusher.connection.bind('unavailable', onDisconnect);
        pusher.connection.bind('failed', onDisconnect);
    }

    teardown = () => {
        channel.stopListening('.HostTelemetryRecorded');
        window.Echo?.leave(`users.${userId}.hosts`);
        if (pusher?.connection) {
            pusher.connection.unbind('connected', onConnect);
            pusher.connection.unbind('disconnected', onDisconnect);
            pusher.connection.unbind('unavailable', onDisconnect);
            pusher.connection.unbind('failed', onDisconnect);
        }
    };
});

onBeforeUnmount(() => {
    teardown?.();
});
</script>

<template>
    <Head :title="`Host · ${host.name}`" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <Link
                    :href="route('monitoring.hosts.index')"
                    class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan transition hover:text-accent-cyan/80"
                >
                    <ChevronLeft class="h-3 w-3" aria-hidden="true" />
                    Hosts
                </Link>
                <h1 class="text-lg font-semibold text-text-primary">
                    {{ host.name }}
                </h1>
            </div>
        </template>

        <div
            class="mx-auto flex max-w-4xl flex-col gap-4 px-4 py-6 sm:px-6 lg:px-8"
        >
            <section class="glass-card flex flex-col gap-4 p-6">
                <header
                    class="flex flex-wrap items-start justify-between gap-3"
                >
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center gap-2">
                            <h2
                                class="text-xl font-semibold text-text-primary"
                            >
                                {{ host.name }}
                            </h2>
                            <StatusBadge :tone="statusTone(host.status)">
                                {{ host.status }}
                            </StatusBadge>
                        </div>
                        <p
                            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-text-muted"
                        >
                            <span
                                v-if="host.project"
                                class="font-mono uppercase tracking-[0.18em] text-text-secondary"
                            >
                                {{ host.project.name }}
                            </span>
                            <span v-if="host.provider">
                                · {{ host.provider }}
                            </span>
                            <span v-if="host.connection_type">
                                · {{ host.connection_type }} push
                            </span>
                            <span v-if="host.last_seen_at">
                                · Last seen {{ host.last_seen_at }}
                            </span>
                            <span v-else class="text-text-muted/70">
                                · Awaiting first telemetry
                            </span>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span
                            v-if="realtimeConnected === false"
                            class="inline-flex items-center gap-1.5 rounded-full border border-status-warning/40 bg-status-warning/10 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-status-warning"
                            title="Live updates offline. Telemetry still records; refresh to see the latest."
                        >
                            <WifiOff class="h-3 w-3" aria-hidden="true" />
                            Live offline
                        </span>
                        <Link
                            v-if="canUpdate"
                            :href="route('monitoring.hosts.edit', host.id)"
                            class="inline-flex items-center gap-1 rounded-md border border-border-subtle bg-background-panel-hover px-2 py-1 text-xs font-semibold text-text-secondary transition hover:border-accent-cyan/60 hover:text-text-primary"
                        >
                            <PencilLine
                                class="h-3 w-3"
                                aria-hidden="true"
                            />
                            Edit
                        </Link>
                        <button
                            v-if="canDelete"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-md border border-status-danger/40 bg-status-danger/10 px-2 py-1 text-xs font-semibold text-status-danger transition hover:border-status-danger/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-status-danger/60"
                            @click="archive"
                        >
                            <Trash2 class="h-3 w-3" aria-hidden="true" />
                            Archive
                        </button>
                    </div>
                </header>

                <dl
                    class="grid grid-cols-2 gap-4 border-t border-border-subtle pt-4 text-xs sm:grid-cols-4"
                >
                    <div class="flex flex-col gap-1">
                        <dt class="uppercase tracking-[0.18em] text-text-muted">
                            CPU cores
                        </dt>
                        <dd
                            class="font-mono text-sm text-text-primary"
                        >
                            {{ host.cpu_count ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt class="uppercase tracking-[0.18em] text-text-muted">
                            Memory
                        </dt>
                        <dd
                            class="font-mono text-sm text-text-primary"
                        >
                            {{
                                host.memory_total_mb !== null
                                    ? `${Math.round(host.memory_total_mb / 1024)} GB`
                                    : '—'
                            }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt class="uppercase tracking-[0.18em] text-text-muted">
                            Disk
                        </dt>
                        <dd
                            class="font-mono text-sm text-text-primary"
                        >
                            {{
                                host.disk_total_gb !== null
                                    ? `${host.disk_total_gb} GB`
                                    : '—'
                            }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt class="uppercase tracking-[0.18em] text-text-muted">
                            Docker
                        </dt>
                        <dd
                            class="font-mono text-sm text-text-primary"
                        >
                            {{ host.docker_version ?? '—' }}
                        </dd>
                    </div>
                </dl>

                <p
                    v-if="host.endpoint_url"
                    class="flex items-center gap-1 text-xs text-text-muted"
                >
                    Endpoint
                    <a
                        :href="host.endpoint_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-mono text-text-secondary transition hover:text-accent-cyan"
                    >
                        {{ host.endpoint_url }}
                        <ExternalLink
                            class="ml-1 inline h-3 w-3"
                            aria-hidden="true"
                        />
                    </a>
                </p>
            </section>

            <section
                v-if="telemetry.current === null"
                class="glass-card flex flex-col items-center gap-3 border-dashed p-8 text-center"
            >
                <Clock
                    class="h-6 w-6 text-text-muted"
                    aria-hidden="true"
                />
                <p class="text-sm font-semibold text-text-secondary">
                    Waiting for first telemetry
                </p>
                <p class="max-w-sm text-xs text-text-muted">
                    Mint an agent token below and point the reference agent
                    at this host. Metrics and the container table appear
                    here as soon as the first tick lands.
                </p>
            </section>

            <template v-else>
                <HostMetricsPanel :current="telemetry.current" />

                <section class="glass-card flex flex-col gap-4 p-6">
                    <header class="flex items-center justify-between gap-3">
                        <h3
                            class="text-xs font-semibold uppercase tracking-[0.2em] text-text-secondary"
                        >
                            History
                        </h3>
                        <span class="text-[11px] text-text-muted">
                            last {{ telemetry.series.length }}
                            {{
                                telemetry.series.length === 1
                                    ? 'tick'
                                    : 'ticks'
                            }}
                        </span>
                    </header>
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div class="flex flex-col gap-2">
                            <div
                                class="flex items-baseline justify-between text-xs"
                            >
                                <span
                                    class="font-mono uppercase tracking-[0.18em] text-text-muted"
                                >
                                    CPU
                                </span>
                                <span class="text-text-secondary">
                                    {{
                                        currentCpu !== null
                                            ? `${currentCpu.toFixed(1)}%`
                                            : '—'
                                    }}
                                </span>
                            </div>
                            <Sparkline
                                v-if="cpuSeries.length >= 2"
                                :points="cpuSeries"
                                accent="cyan"
                                :height="40"
                            />
                            <p
                                v-else
                                class="text-[11px] text-text-muted"
                            >
                                Not enough data yet.
                            </p>
                        </div>
                        <div class="flex flex-col gap-2">
                            <div
                                class="flex items-baseline justify-between text-xs"
                            >
                                <span
                                    class="font-mono uppercase tracking-[0.18em] text-text-muted"
                                >
                                    Memory
                                </span>
                                <span class="text-text-secondary">
                                    {{
                                        currentMemory !== null
                                            ? `${currentMemory.toFixed(1)}%`
                                            : '—'
                                    }}
                                </span>
                            </div>
                            <Sparkline
                                v-if="memorySeries.length >= 2"
                                :points="memorySeries"
                                accent="purple"
                                :height="40"
                            />
                            <p
                                v-else
                                class="text-[11px] text-text-muted"
                            >
                                Not enough data yet.
                            </p>
                        </div>
                    </div>
                </section>

                <ContainerTable :containers="telemetry.containers" />
            </template>

            <AgentTokenPanel
                :host-id="host.id"
                :active-token="host.active_agent_token"
                :can-manage-tokens="canManageTokens"
            />
        </div>
    </AppLayout>
</template>
