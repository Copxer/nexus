<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import SkeletonRow from '@/Components/Skeleton/SkeletonRow.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Bell,
    BellOff,
    CheckCircle2,
    ExternalLink,
    Eye,
    WifiOff,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface ProjectChip {
    id: number;
    slug?: string;
    name: string;
    color: string | null;
    icon?: string | null;
}

interface AlertRow {
    id: number;
    source: string | null;
    source_id: number | null;
    type: string;
    severity: string | null;
    severity_tone: 'info' | 'warning' | 'danger' | null;
    status: 'open' | 'acknowledged' | 'resolved' | 'muted' | string | null;
    title: string;
    description: string | null;
    triggered_at: string | null;
    triggered_at_iso: string | null;
    acknowledged_at: string | null;
    resolved_at: string | null;
    last_seen_at: string | null;
    metadata: Record<string, unknown> | null;
    project: ProjectChip | null;
    affected_entity_url: string | null;
    can_acknowledge: boolean;
    can_resolve: boolean;
    can_mute: boolean;
}

interface FilterState {
    severity: string | null;
    source: string | null;
    status: string | null;
    project_id: number | null;
}

interface FilterOptions {
    severities: string[];
    sources: string[];
    statuses: string[];
    projects: { id: number; name: string; color: string | null }[];
}

const props = defineProps<{
    alerts: AlertRow[];
    filters: FilterState;
    filterOptions: FilterOptions;
}>();

const page = usePage<PageProps>();

const filterDraft = ref<FilterState>({ ...props.filters });
const isAlertsLoading = ref(false);
const actingAlertId = ref<number | null>(null);

const hasFilterActive = computed<boolean>(() => {
    const f = props.filters;
    // The default landing filter is `status: 'open'` — that's not
    // considered "user-active" for the empty-state CTA.
    if (f.severity !== null) return true;
    if (f.source !== null) return true;
    if (f.project_id !== null) return true;
    if (f.status !== null && f.status !== 'open') return true;
    return false;
});

const headerCounts = computed(() => {
    const total = props.alerts.length;
    const critical = props.alerts.filter(
        (a) => a.severity === 'critical' && (a.status === 'open' || a.status === 'acknowledged'),
    ).length;
    return { total, critical };
});

const applyFilters = () => {
    const params: Record<string, string | number> = {};
    for (const [key, value] of Object.entries(filterDraft.value)) {
        if (value !== null && value !== '') {
            params[key] = value;
        }
    }
    router.get(route('alerts.index'), params, {
        preserveScroll: true,
        preserveState: true,
        only: ['alerts', 'filters', 'filterOptions'],
        onStart: () => {
            isAlertsLoading.value = true;
        },
        onFinish: () => {
            isAlertsLoading.value = false;
        },
    });
};

const clearFilters = () => {
    filterDraft.value = {
        severity: null,
        source: null,
        status: null,
        project_id: null,
    };
    router.get(
        route('alerts.index'),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['alerts', 'filters', 'filterOptions'],
            onStart: () => {
                isAlertsLoading.value = true;
            },
            onFinish: () => {
                isAlertsLoading.value = false;
            },
        },
    );
};

const acknowledge = (alert: AlertRow) => {
    actingAlertId.value = alert.id;
    router.post(
        route('alerts.acknowledge', alert.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                actingAlertId.value = null;
            },
        },
    );
};

const resolve = (alert: AlertRow) => {
    actingAlertId.value = alert.id;
    router.post(
        route('alerts.resolve', alert.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                actingAlertId.value = null;
            },
        },
    );
};

const mute = (alert: AlertRow) => {
    actingAlertId.value = alert.id;
    router.post(route('alerts.mute', alert.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            actingAlertId.value = null;
        },
    });
};

const capitalize = (value: string | null): string =>
    value === null ? '' : value.charAt(0).toUpperCase() + value.slice(1);

const projectAccentClass = (color: string | null) =>
    (
        ({
            cyan: 'text-accent-cyan',
            blue: 'text-accent-blue',
            purple: 'text-accent-purple',
            magenta: 'text-accent-magenta',
            success: 'text-status-success',
            warning: 'text-status-warning',
        }) as const
    )[color ?? ''] ?? 'text-text-muted';

const statusLabel = (alert: AlertRow): string => {
    if (alert.status === 'open' && alert.triggered_at) {
        return `Open since ${alert.triggered_at}`;
    }
    if (alert.status === 'acknowledged' && alert.acknowledged_at) {
        return `Acknowledged ${alert.acknowledged_at}`;
    }
    if (alert.status === 'resolved' && alert.resolved_at) {
        return `Resolved ${alert.resolved_at}`;
    }
    if (alert.status === 'muted') {
        return 'Muted';
    }
    return capitalize(alert.status);
};

// ─── Reverb subscription (spec 032) ──────────────────────────────────
// Listen on `users.{id}.alerts` for both fresh triggers and resolves.
// Each pulse partial-reloads the list / filters / filterOptions props
// so server-side sort + status filter re-apply naturally — no JS-side
// merge logic. Mirrors spec 028's Hosts Show pattern verbatim.
const realtimeConnected = ref<boolean | null>(null);
let teardown: (() => void) | null = null;

onMounted(() => {
    if (typeof window === 'undefined' || !window.Echo) {
        return;
    }
    const userId = page.props.auth?.user?.id;
    if (userId == null) return;

    const channelName = `users.${userId}.alerts`;
    const channel = window.Echo.private(channelName);

    const reloadList = () => {
        router.reload({
            only: ['alerts', 'filters', 'filterOptions'],
            onStart: () => {
                isAlertsLoading.value = true;
            },
            onFinish: () => {
                isAlertsLoading.value = false;
            },
        });
    };

    channel.listen('.AlertTriggered', reloadList);
    channel.listen('.AlertResolved', reloadList);

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
        channel.stopListening('.AlertTriggered');
        channel.stopListening('.AlertResolved');
        window.Echo?.leave(channelName);
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
    <Head title="Alerts" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <h1 class="text-lg font-semibold text-text-primary">Alerts</h1>
            </div>
        </template>

        <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
            <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-xl font-semibold text-text-primary">
                            Open alerts
                        </h2>
                        <span
                            v-if="realtimeConnected === false"
                            class="inline-flex items-center gap-1.5 rounded-full border border-status-warning/40 bg-status-warning/10 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-status-warning"
                            title="Live updates offline. Alerts still land on the schedule; refresh to see the latest."
                        >
                            <WifiOff class="h-3 w-3" aria-hidden="true" />
                            Live offline
                        </span>
                    </div>
                    <p class="text-sm text-text-secondary">
                        {{ headerCounts.total }}
                        {{
                            headerCounts.total === 1 ? 'alert' : 'alerts'
                        }}
                        <span v-if="headerCounts.critical > 0">
                            · <span class="text-status-danger"
                                >{{ headerCounts.critical }} critical</span
                            >
                        </span>
                    </p>
                </div>

                <!-- Filter bar -->
                <div class="flex flex-wrap items-end gap-3">
                    <label class="flex flex-col gap-1 text-xs">
                        <span
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Severity
                        </span>
                        <select
                            v-model="filterDraft.severity"
                            class="min-w-[120px] rounded-md border border-border-subtle bg-background-panel-hover px-2 py-1.5 text-sm text-text-primary focus:border-accent-cyan/60 focus:outline-none"
                            @change="applyFilters"
                        >
                            <option :value="null">Any severity</option>
                            <option
                                v-for="severity in filterOptions.severities"
                                :key="severity"
                                :value="severity"
                            >
                                {{ capitalize(severity) }}
                            </option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-xs">
                        <span
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Source
                        </span>
                        <select
                            v-model="filterDraft.source"
                            class="min-w-[120px] rounded-md border border-border-subtle bg-background-panel-hover px-2 py-1.5 text-sm text-text-primary focus:border-accent-cyan/60 focus:outline-none"
                            @change="applyFilters"
                        >
                            <option :value="null">Any source</option>
                            <option
                                v-for="source in filterOptions.sources"
                                :key="source"
                                :value="source"
                            >
                                {{ capitalize(source) }}
                            </option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-xs">
                        <span
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Status
                        </span>
                        <select
                            v-model="filterDraft.status"
                            class="min-w-[140px] rounded-md border border-border-subtle bg-background-panel-hover px-2 py-1.5 text-sm text-text-primary focus:border-accent-cyan/60 focus:outline-none"
                            @change="applyFilters"
                        >
                            <!-- 'all' is the explicit "show every
                                 status" sentinel — the controller
                                 reads it as "skip the where clause"
                                 and round-trips it so reload keeps
                                 this option selected. -->
                            <option value="all">Any status</option>
                            <option
                                v-for="status in filterOptions.statuses"
                                :key="status"
                                :value="status"
                            >
                                {{ capitalize(status) }}
                            </option>
                        </select>
                    </label>
                    <label
                        v-if="filterOptions.projects.length > 0"
                        class="flex flex-col gap-1 text-xs"
                    >
                        <span
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Project
                        </span>
                        <select
                            v-model.number="filterDraft.project_id"
                            class="min-w-[140px] rounded-md border border-border-subtle bg-background-panel-hover px-2 py-1.5 text-sm text-text-primary focus:border-accent-cyan/60 focus:outline-none"
                            @change="applyFilters"
                        >
                            <option :value="null">All projects</option>
                            <option
                                v-for="project in filterOptions.projects"
                                :key="project.id"
                                :value="project.id"
                            >
                                {{ project.name }}
                            </option>
                        </select>
                    </label>
                </div>
            </header>

            <div
                v-if="isAlertsLoading"
                class="flex flex-col gap-2"
                role="status"
                aria-label="Loading alerts"
            >
                <SkeletonRow v-for="n in 4" :key="n" />
                <span class="sr-only">Loading alerts</span>
            </div>

            <div
                v-else-if="alerts.length === 0 && !hasFilterActive"
                class="glass-card flex flex-col items-center justify-center gap-3 px-6 py-16 text-center"
            >
                <span
                    class="flex h-12 w-12 items-center justify-center rounded-full border border-status-success/40 bg-status-success/10"
                >
                    <CheckCircle2
                        class="h-5 w-5 text-status-success"
                        aria-hidden="true"
                    />
                </span>
                <p class="text-sm font-medium text-text-primary">All clear</p>
                <p class="max-w-sm text-xs text-text-muted">
                    No open alerts. Transitions emitted by website
                    monitors, host telemetry, and workflow webhooks
                    auto-promote here when they fire.
                </p>
            </div>

            <div
                v-else-if="alerts.length === 0"
                class="glass-card flex flex-col items-center justify-center gap-3 px-6 py-16 text-center"
            >
                <span
                    class="flex h-12 w-12 items-center justify-center rounded-full border border-border-subtle bg-slate-950/60"
                >
                    <Bell
                        class="h-5 w-5 text-text-muted"
                        aria-hidden="true"
                    />
                </span>
                <p class="text-sm font-medium text-text-secondary">
                    No alerts match this filter
                </p>
                <button
                    type="button"
                    class="text-xs font-semibold text-accent-cyan transition hover:text-accent-cyan/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    @click="clearFilters"
                >
                    Clear filter
                </button>
            </div>

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="alert in alerts"
                    :key="alert.id"
                    class="glass-card flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-start sm:gap-4"
                >
                    <span
                        class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-border-subtle bg-slate-950/60"
                        :class="{
                            'border-status-danger/40 bg-status-danger/10':
                                alert.severity_tone === 'danger',
                            'border-status-warning/40 bg-status-warning/10':
                                alert.severity_tone === 'warning',
                            'border-status-info/40 bg-status-info/10':
                                alert.severity_tone === 'info',
                        }"
                    >
                        <AlertTriangle
                            class="h-3.5 w-3.5"
                            :class="{
                                'text-status-danger':
                                    alert.severity_tone === 'danger',
                                'text-status-warning':
                                    alert.severity_tone === 'warning',
                                'text-status-info':
                                    alert.severity_tone === 'info',
                            }"
                            aria-hidden="true"
                        />
                    </span>

                    <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="truncate text-sm font-semibold text-text-primary"
                            >
                                {{ alert.title }}
                            </span>
                            <StatusBadge
                                v-if="alert.severity_tone && alert.severity"
                                :tone="alert.severity_tone"
                            >
                                {{ alert.severity }}
                            </StatusBadge>
                            <span
                                v-if="alert.project"
                                class="inline-flex items-center gap-1 rounded-full border border-current/30 px-1.5 py-0.5 text-[10px] font-mono uppercase tracking-[0.18em]"
                                :class="projectAccentClass(alert.project.color)"
                            >
                                {{ alert.project.name }}
                            </span>
                        </div>
                        <p
                            v-if="alert.description"
                            class="text-xs text-text-secondary"
                        >
                            {{ alert.description }}
                        </p>
                        <p
                            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-text-muted"
                        >
                            <span class="font-mono uppercase">
                                {{ alert.source }}
                            </span>
                            <span>· {{ statusLabel(alert) }}</span>
                            <span
                                v-if="
                                    alert.last_seen_at &&
                                    alert.last_seen_at !== alert.triggered_at
                                "
                            >
                                · Last seen {{ alert.last_seen_at }}
                            </span>
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <button
                            v-if="alert.can_acknowledge"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-md border border-border-subtle bg-background-panel-hover px-2 py-1 text-xs font-semibold text-text-secondary transition hover:border-accent-cyan/60 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                            :disabled="actingAlertId !== null"
                            @click="acknowledge(alert)"
                        >
                            <Eye class="h-3 w-3" aria-hidden="true" />
                            {{ actingAlertId === alert.id ? 'Saving…' : 'Ack' }}
                        </button>
                        <button
                            v-if="alert.can_resolve"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-md border border-status-success/40 bg-status-success/10 px-2 py-1 text-xs font-semibold text-status-success transition hover:border-status-success/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-status-success/60"
                            :disabled="actingAlertId !== null"
                            @click="resolve(alert)"
                        >
                            <CheckCircle2
                                class="h-3 w-3"
                                aria-hidden="true"
                            />
                            {{ actingAlertId === alert.id ? 'Saving…' : 'Resolve' }}
                        </button>
                        <button
                            v-if="alert.can_mute"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-md border border-border-subtle bg-background-panel-hover px-2 py-1 text-xs font-semibold text-text-muted transition hover:border-status-warning/40 hover:text-status-warning focus:outline-none focus-visible:ring-2 focus-visible:ring-status-warning/60"
                            :disabled="actingAlertId !== null"
                            @click="mute(alert)"
                        >
                            <BellOff class="h-3 w-3" aria-hidden="true" />
                            {{ actingAlertId === alert.id ? 'Saving…' : 'Mute' }}
                        </button>
                        <a
                            v-if="alert.affected_entity_url"
                            :href="alert.affected_entity_url"
                            :target="
                                alert.affected_entity_url.startsWith('http')
                                    ? '_blank'
                                    : undefined
                            "
                            :rel="
                                alert.affected_entity_url.startsWith('http')
                                    ? 'noopener noreferrer'
                                    : undefined
                            "
                            class="inline-flex items-center justify-center rounded-md border border-border-subtle bg-background-panel-hover p-1.5 text-text-muted transition hover:border-accent-cyan/60 hover:text-accent-cyan focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                            :aria-label="`Open the entity that triggered ${alert.title}`"
                        >
                            <ExternalLink
                                class="h-3.5 w-3.5"
                                aria-hidden="true"
                            />
                        </a>
                    </div>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
