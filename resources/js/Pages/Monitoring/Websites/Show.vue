<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import { websiteStatusTone as statusTone } from '@/lib/websiteStyles';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ChevronLeft,
    ExternalLink,
    Pencil,
    Play,
    Trash2,
} from 'lucide-vue-next';

interface ProjectChip {
    id: number;
    slug: string;
    name: string;
    color: string | null;
    icon: string | null;
}

interface WebsiteShape {
    id: number;
    name: string;
    url: string;
    method: string;
    expected_status_code: number;
    timeout_ms: number;
    check_interval_seconds: number;
    status: string | null;
    last_checked_at: string | null;
    last_success_at: string | null;
    last_failure_at: string | null;
    project: ProjectChip | null;
}

interface CheckRow {
    id: number;
    status: 'up' | 'down' | 'slow' | 'error' | string | null;
    http_status_code: number | null;
    response_time_ms: number | null;
    error_message: string | null;
    checked_at: string | null;
    checked_at_iso: string | null;
}

interface SummaryShape {
    uptime_24h: number | null;
    uptime_7d: number | null;
    uptime_30d: number | null;
    last_incident_at: string | null;
}

const props = defineProps<{
    website: WebsiteShape;
    checks: CheckRow[];
    summary: SummaryShape;
    canUpdate: boolean;
    canDelete: boolean;
    canProbe: boolean;
}>();

const formatUptime = (rate: number | null): string =>
    rate === null ? '—%' : `${rate}%`;

// `statusTone` re-exported from `@/lib/websiteStyles` above so the
// four consumers stay in sync when the WebsiteStatus enum grows.

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

const runProbe = () => {
    if (!props.canProbe) return;
    router.post(
        route('monitoring.websites.probe', props.website.id),
        {},
        { preserveScroll: true },
    );
};

const confirmDelete = () => {
    if (!props.canDelete) return;
    if (
        !window.confirm(
            `Delete monitor ${props.website.name}? This removes all check history.`,
        )
    ) {
        return;
    }
    router.delete(route('monitoring.websites.destroy', props.website.id));
};
</script>

<template>
    <Head :title="website.name" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <Link
                    :href="route('monitoring.websites.index')"
                    class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan transition hover:text-accent-cyan/80"
                >
                    <ChevronLeft class="h-3 w-3" aria-hidden="true" />
                    Monitoring
                </Link>
                <h1 class="text-lg font-semibold text-text-primary">
                    {{ website.name }}
                </h1>
            </div>
        </template>

        <div class="mx-auto flex max-w-4xl flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8">
            <header class="glass-card flex flex-col gap-4 p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex flex-col gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <StatusBadge :tone="statusTone(website.status)">
                                {{ website.status }}
                            </StatusBadge>
                            <span
                                v-if="website.project"
                                class="inline-flex items-center gap-1 rounded-full border border-current/30 px-1.5 py-0.5 text-[10px] font-mono uppercase tracking-[0.18em]"
                                :class="projectAccentClass(website.project.color)"
                            >
                                {{ website.project.name }}
                            </span>
                        </div>
                        <a
                            :href="website.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1.5 truncate font-mono text-sm text-text-secondary transition hover:text-accent-cyan"
                        >
                            <span class="font-mono text-text-muted">{{ website.method }}</span>
                            {{ website.url }}
                            <ExternalLink
                                class="h-3.5 w-3.5"
                                aria-hidden="true"
                            />
                        </a>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            v-if="canProbe"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-accent-cyan/40 bg-accent-cyan/15 px-3 py-2 text-sm font-semibold text-accent-cyan transition hover:border-accent-cyan/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                            @click="runProbe"
                        >
                            <Play class="h-4 w-4" aria-hidden="true" />
                            Probe now
                        </button>
                        <Link
                            v-if="canUpdate"
                            :href="route('monitoring.websites.edit', website.id)"
                            class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm font-semibold text-text-secondary transition hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        >
                            <Pencil class="h-4 w-4" aria-hidden="true" />
                            Edit
                        </Link>
                        <button
                            v-if="canDelete"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-status-danger/40 bg-status-danger/10 px-3 py-2 text-sm font-semibold text-status-danger transition hover:border-status-danger/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-status-danger/60"
                            @click="confirmDelete"
                        >
                            <Trash2 class="h-4 w-4" aria-hidden="true" />
                            Delete
                        </button>
                    </div>
                </div>

                <dl
                    class="grid grid-cols-2 gap-4 border-t border-border-subtle pt-4 text-sm sm:grid-cols-4"
                >
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Expected
                        </dt>
                        <dd class="font-mono text-text-secondary">
                            {{ website.expected_status_code }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Timeout
                        </dt>
                        <dd class="font-mono text-text-secondary">
                            {{ website.timeout_ms }}ms
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Interval
                        </dt>
                        <dd class="font-mono text-text-secondary">
                            {{ website.check_interval_seconds }}s
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Last checked
                        </dt>
                        <dd class="text-text-secondary">
                            {{ website.last_checked_at ?? '—' }}
                        </dd>
                    </div>
                </dl>

                <dl
                    class="grid grid-cols-2 gap-4 border-t border-border-subtle pt-4 text-sm sm:grid-cols-4"
                >
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Uptime · 24h
                        </dt>
                        <dd class="font-display text-lg font-semibold tabular-nums text-text-primary">
                            {{ formatUptime(summary.uptime_24h) }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Uptime · 7d
                        </dt>
                        <dd class="font-display text-lg font-semibold tabular-nums text-text-primary">
                            {{ formatUptime(summary.uptime_7d) }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Uptime · 30d
                        </dt>
                        <dd class="font-display text-lg font-semibold tabular-nums text-text-primary">
                            {{ formatUptime(summary.uptime_30d) }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Last incident
                        </dt>
                        <dd class="text-text-secondary">
                            {{ summary.last_incident_at ?? 'Never' }}
                        </dd>
                    </div>
                </dl>
            </header>

            <section class="glass-card p-6 sm:p-8">
                <header
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle pb-4"
                >
                    <h3 class="text-sm font-semibold text-text-primary">
                        Recent checks
                    </h3>
                    <span class="text-xs text-text-muted">
                        Up to 50 most recent
                    </span>
                </header>

                <ul
                    v-if="checks.length > 0"
                    class="mt-2 divide-y divide-border-subtle"
                >
                    <li
                        v-for="check in checks"
                        :key="check.id"
                        class="flex items-center gap-4 py-3"
                    >
                        <StatusBadge :tone="statusTone(check.status)">
                            {{ check.status }}
                        </StatusBadge>
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <p
                                class="flex flex-wrap items-center gap-x-2 text-xs text-text-muted"
                            >
                                <span
                                    v-if="check.http_status_code"
                                    class="font-mono text-text-secondary"
                                >
                                    HTTP {{ check.http_status_code }}
                                </span>
                                <span
                                    v-if="check.response_time_ms !== null"
                                    class="font-mono text-text-secondary"
                                >
                                    {{ check.response_time_ms }}ms
                                </span>
                                <span v-if="check.checked_at">
                                    · {{ check.checked_at }}
                                </span>
                            </p>
                            <p
                                v-if="check.error_message"
                                class="flex items-start gap-1.5 text-xs text-status-danger"
                            >
                                <AlertTriangle
                                    class="mt-0.5 h-3 w-3 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="break-words font-mono text-text-secondary">
                                    {{ check.error_message }}
                                </span>
                            </p>
                        </div>
                    </li>
                </ul>

                <p
                    v-else
                    class="mt-6 rounded-lg border border-dashed border-border-subtle bg-background-panel-hover/30 p-4 text-sm text-text-muted"
                >
                    No checks yet.
                    <span v-if="canProbe">
                        Click <span class="font-mono">Probe now</span> to run
                        the first one.
                    </span>
                </p>
            </section>
        </div>
    </AppLayout>
</template>
