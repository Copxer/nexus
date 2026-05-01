<script setup lang="ts">
import ActivityHeatmap from '@/Components/Activity/ActivityHeatmap.vue';
import KpiCard from '@/Components/Dashboard/KpiCard.vue';
import Sparkline from '@/Components/Dashboard/Sparkline.vue';
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { ActivityHeatmapPayload, DashboardPayload } from '@/types';
import { Head } from '@inertiajs/vue3';
import {
    Activity,
    BarChart3,
    Bell,
    FolderKanban,
    Gauge,
    GitBranch,
    GitPullRequest,
    Globe,
    HeartPulse,
    LineChart,
    Rocket,
    Server,
    ShieldCheck,
} from 'lucide-vue-next';

interface TopWorkItem {
    id: string;
    kind: 'issue' | 'pull_request' | string;
    number: number;
    title: string;
    state: string | null;
    author_login: string | null;
    updated_at_github: string | null;
    html_url: string | null;
    repository: { id: number; full_name: string; name: string } | null;
}

defineProps<{
    dashboard: DashboardPayload;
    activityHeatmap: ActivityHeatmapPayload;
    topWorkItems: TopWorkItem[];
}>();

// ----- Hardcoded mock data for the populated stub widgets -----
// These widgets each have their own dedicated spec in later phases. The
// mock data here exists only so the page looks intentional, not skeletal —
// each stub footer points at the phase that will replace it.

const stubHosts = [
    { name: 'prod-web-01', region: 'us-east', cpu: 0.42, mem: 0.61, status: 'success' as const },
    { name: 'prod-api-02', region: 'us-east', cpu: 0.78, mem: 0.83, status: 'warning' as const },
    { name: 'edge-eu-01', region: 'eu-west', cpu: 0.31, mem: 0.48, status: 'success' as const },
    { name: 'edge-ap-01', region: 'ap-south', cpu: 0.55, mem: 0.69, status: 'success' as const },
];

const stubServices = [
    {
        name: 'API Gateway',
        status: 'success' as const,
        sparkline: [98, 99, 99, 100, 100, 99, 100, 100, 100, 99, 100, 100],
    },
    {
        name: 'Auth Service',
        status: 'success' as const,
        sparkline: [99, 100, 100, 99, 100, 100, 100, 100, 99, 100, 100, 100],
    },
    {
        name: 'Billing',
        status: 'warning' as const,
        sparkline: [100, 99, 98, 96, 94, 95, 96, 95, 94, 93, 95, 96],
    },
    {
        name: 'Notifications',
        status: 'success' as const,
        sparkline: [99, 99, 100, 100, 100, 99, 100, 100, 100, 100, 100, 100],
    },
];

// Stubs only list widgets whose owning phase hasn't shipped yet.
// `Deployment timeline` graduated when Phase 4 (specs 020–022) shipped
// — its real surface is the `Deployments` sidebar entry / `/deployments`
// page. `Website performance` stays here until spec 025 lands the
// dedicated Overview widget on top of the spec-023 monitor data.
const visualizationStubs = [
    { label: 'World map', icon: Globe, phase: 'Phase 8' },
    { label: 'Resource utilization', icon: Activity, phase: 'Phase 6' },
    { label: 'Website performance', icon: LineChart, phase: 'Phase 5 · spec 025' },
    { label: 'System metrics', icon: Gauge, phase: 'Phase 8' },
] as const;
</script>

<template>
    <Head title="Overview" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <span
                    class="text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan"
                >
                    Phase 0
                </span>
                <h1 class="text-lg font-semibold text-text-primary">Overview</h1>
            </div>
        </template>

        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <!-- ─────────────── KPI row (6 cards) ─────────────── -->
            <section
                aria-label="Key metrics"
                class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6"
            >
                <KpiCard
                    :icon="FolderKanban"
                    accent="cyan"
                    label="Projects"
                    :value="String(dashboard.projects.active)"
                    secondary="Active"
                    :status="dashboard.projects.status"
                    status-label="Healthy"
                    :trend="{
                        direction:
                            dashboard.projects.new_this_week > 0 ? 'up' : 'flat',
                        value: `+${dashboard.projects.new_this_week} new`,
                    }"
                    :sparkline="dashboard.projects.sparkline"
                />
                <KpiCard
                    :icon="Rocket"
                    accent="blue"
                    label="Deployments (24h)"
                    :value="String(dashboard.deployments.successful_24h)"
                    :secondary="
                        dashboard.deployments.success_rate_24h === null
                            ? '—% success'
                            : `${dashboard.deployments.success_rate_24h}% success`
                    "
                    :status="dashboard.deployments.status"
                    status-label="On track"
                    :trend="{
                        direction:
                            dashboard.deployments.change_percent >= 0
                                ? 'up'
                                : 'down',
                        value: `${dashboard.deployments.change_percent >= 0 ? '+' : ''}${dashboard.deployments.change_percent}%`,
                    }"
                    :sparkline="dashboard.deployments.sparkline"
                />
                <KpiCard
                    :icon="ShieldCheck"
                    accent="success"
                    label="Services"
                    :value="String(dashboard.services.running)"
                    secondary="Running"
                    :status="dashboard.services.status"
                    :status-label="`${dashboard.services.health_percent}% healthy`"
                    :trend="{
                        direction: 'flat',
                        value: `${dashboard.services.health_percent}%`,
                    }"
                    :sparkline="dashboard.services.sparkline"
                />
                <KpiCard
                    :icon="Server"
                    accent="purple"
                    label="Hosts"
                    :value="String(dashboard.hosts.online)"
                    secondary="Online"
                    :status="dashboard.hosts.status"
                    status-label="Steady"
                    :trend="{
                        direction: dashboard.hosts.new > 0 ? 'up' : 'flat',
                        value: `+${dashboard.hosts.new} new`,
                    }"
                    :sparkline="dashboard.hosts.sparkline"
                />
                <KpiCard
                    :icon="Bell"
                    accent="danger"
                    label="Alerts"
                    :value="String(dashboard.alerts.active)"
                    secondary="Active"
                    :status="dashboard.alerts.status"
                    :status-label="`${dashboard.alerts.critical} critical`"
                    :trend="{
                        direction: dashboard.alerts.active > 0 ? 'up' : 'flat',
                        value: `${dashboard.alerts.active} open`,
                    }"
                    :sparkline="dashboard.alerts.sparkline"
                />
                <KpiCard
                    :icon="HeartPulse"
                    accent="magenta"
                    label="Uptime"
                    :value="`${dashboard.uptime.overall}%`"
                    secondary="30-day"
                    :status="dashboard.uptime.status"
                    status-label="SLA met"
                    :trend="{
                        direction: dashboard.uptime.change >= 0 ? 'up' : 'down',
                        value: `${dashboard.uptime.change >= 0 ? '+' : ''}${dashboard.uptime.change}%`,
                    }"
                    :sparkline="dashboard.uptime.sparkline"
                />
            </section>

            <!-- ─────────────── Stub widgets ─────────────── -->
            <!-- Each card below is a populated placeholder. The canonical
                 implementation ships with the phase named in the footer. -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
                <!-- Issues & PRs — real data from `WorkItemsForUserQuery`
                     (spec 016). Top 4 open work items across the user's
                     repos; the wider `/work-items` page hosts the full
                     queue + filters. -->
                <section
                    aria-label="Issues & PRs"
                    class="glass-card flex flex-col gap-4 p-5 lg:col-span-7"
                >
                    <header class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <GitPullRequest
                                class="h-4 w-4 text-accent-cyan"
                                aria-hidden="true"
                            />
                            <h2 class="text-sm font-semibold text-text-primary">
                                Issues &amp; Pull Requests
                            </h2>
                        </div>
                        <span class="hidden font-mono text-[11px] text-text-muted sm:inline">
                            {{ topWorkItems.length }} open
                        </span>
                    </header>
                    <ul
                        v-if="topWorkItems.length > 0"
                        class="flex flex-col gap-2"
                    >
                        <li
                            v-for="item in topWorkItems"
                            :key="item.id"
                        >
                            <a
                                :href="item.html_url ?? '#'"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="flex items-center gap-3 rounded-lg border border-border-subtle bg-background-panel-hover/40 p-3 transition hover:border-accent-cyan/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                            >
                                <StatusBadge
                                    :tone="item.kind === 'pull_request' ? 'info' : 'muted'"
                                >
                                    {{ item.kind === 'pull_request' ? 'PR' : 'Issue' }}
                                </StatusBadge>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm text-text-primary">
                                        <span class="font-mono text-text-muted">
                                            #{{ item.number }}
                                        </span>
                                        {{ item.title }}
                                    </p>
                                    <p
                                        class="truncate font-mono text-[11px] text-text-muted"
                                    >
                                        <span v-if="item.repository">
                                            {{ item.repository.full_name }}
                                            <span v-if="item.author_login">
                                                · @{{ item.author_login }}
                                            </span>
                                        </span>
                                        <span v-if="item.updated_at_github">
                                            · Updated {{ item.updated_at_github }}
                                        </span>
                                    </p>
                                </div>
                            </a>
                        </li>
                    </ul>
                    <p
                        v-else
                        class="rounded-lg border border-dashed border-border-subtle bg-background-panel-hover/30 p-4 text-sm text-text-muted"
                    >
                        No open issues or pull requests yet — connect a
                        GitHub repository to start syncing them.
                    </p>
                    <footer class="text-[11px] text-text-muted">
                        Showing the most recent open items. The full queue
                        lives at <span class="font-mono">/work-items</span>.
                    </footer>
                </section>

                <!-- Top Repositories -->
                <section
                    aria-label="Top Repositories"
                    class="glass-card flex flex-col gap-4 p-5 lg:col-span-5"
                >
                    <header class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <GitBranch
                                class="h-4 w-4 text-accent-purple"
                                aria-hidden="true"
                            />
                            <h2 class="text-sm font-semibold text-text-primary">
                                Top Repositories
                            </h2>
                        </div>
                        <span class="hidden font-mono text-[11px] text-text-muted sm:inline">
                            By stars · live
                        </span>
                    </header>
                    <!-- At < sm we stack name+commits over a full-width bar
                         so the bar isn't squeezed to nothing on small phones. -->
                    <ul
                        v-if="dashboard.topRepositories.length > 0"
                        class="flex flex-col gap-3 sm:gap-3"
                    >
                        <li
                            v-for="repo in dashboard.topRepositories"
                            :key="repo.name"
                            class="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:gap-3"
                        >
                            <div
                                class="flex items-baseline justify-between gap-3 sm:contents"
                            >
                                <span
                                    class="truncate font-mono text-xs text-text-secondary sm:w-40 sm:shrink-0"
                                >
                                    {{ repo.name }}
                                </span>
                                <span
                                    class="shrink-0 text-right font-mono text-[11px] tabular-nums text-text-muted sm:order-2 sm:w-20"
                                >
                                    {{ repo.commits }} stars
                                </span>
                            </div>
                            <div
                                class="relative h-2 flex-1 overflow-hidden rounded-full bg-background-panel-hover sm:order-1"
                            >
                                <span
                                    class="block h-full rounded-full bg-gradient-to-r from-accent-cyan to-accent-magenta"
                                    :style="{
                                        width: `${Math.round(repo.share * 100)}%`,
                                    }"
                                />
                            </div>
                        </li>
                    </ul>
                    <p
                        v-else
                        class="rounded-lg border border-dashed border-border-subtle bg-background-panel-hover/30 p-4 text-center text-xs text-text-muted"
                    >
                        Link a repository on a project to populate this.
                    </p>
                </section>

                <!-- Container Hosts -->
                <section
                    aria-label="Container Hosts"
                    class="glass-card flex flex-col gap-4 p-5 lg:col-span-6"
                >
                    <header class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <Server
                                class="h-4 w-4 text-accent-blue"
                                aria-hidden="true"
                            />
                            <h2 class="text-sm font-semibold text-text-primary">
                                Container Hosts
                            </h2>
                        </div>
                        <span class="hidden font-mono text-[11px] text-text-muted sm:inline">
                            {{ stubHosts.length }} hosts · mock
                        </span>
                    </header>
                    <ul class="flex flex-col gap-3">
                        <li
                            v-for="host in stubHosts"
                            :key="host.name"
                            class="grid grid-cols-[auto_1fr_auto] items-center gap-3"
                        >
                            <StatusBadge :tone="host.status" dot-only />
                            <div class="min-w-0">
                                <p
                                    class="truncate font-mono text-xs text-text-primary"
                                >
                                    {{ host.name }}
                                </p>
                                <p
                                    class="truncate text-[11px] text-text-muted"
                                >
                                    {{ host.region }}
                                </p>
                            </div>
                            <div class="flex flex-col gap-1 text-[10px]">
                                <div
                                    class="flex items-center gap-2 font-mono text-text-muted"
                                >
                                    <span class="w-6 shrink-0">CPU</span>
                                    <span
                                        class="relative h-1.5 w-20 overflow-hidden rounded-full bg-background-panel-hover"
                                    >
                                        <span
                                            class="block h-full rounded-full bg-accent-cyan"
                                            :style="{
                                                width: `${Math.round(host.cpu * 100)}%`,
                                            }"
                                        />
                                    </span>
                                </div>
                                <div
                                    class="flex items-center gap-2 font-mono text-text-muted"
                                >
                                    <span class="w-6 shrink-0">MEM</span>
                                    <span
                                        class="relative h-1.5 w-20 overflow-hidden rounded-full bg-background-panel-hover"
                                    >
                                        <span
                                            class="block h-full rounded-full bg-accent-purple"
                                            :style="{
                                                width: `${Math.round(host.mem * 100)}%`,
                                            }"
                                        />
                                    </span>
                                </div>
                            </div>
                        </li>
                    </ul>
                    <footer class="text-[11px] text-text-muted">
                        Full widget lands with phase 6 — Docker Host Agent.
                    </footer>
                </section>

                <!-- Service Health -->
                <section
                    aria-label="Service Health"
                    class="glass-card flex flex-col gap-4 p-5 lg:col-span-6"
                >
                    <header class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <HeartPulse
                                class="h-4 w-4 text-status-success"
                                aria-hidden="true"
                            />
                            <h2 class="text-sm font-semibold text-text-primary">
                                Service Health
                            </h2>
                        </div>
                        <span class="hidden font-mono text-[11px] text-text-muted sm:inline">
                            {{ stubServices.length }} services · mock
                        </span>
                    </header>
                    <!-- Sparkline column shrinks 100→140px from sm to lg
                         so the row template doesn't squeeze the service name
                         on narrow viewports. -->
                    <ul class="flex flex-col gap-3">
                        <li
                            v-for="svc in stubServices"
                            :key="svc.name"
                            class="grid grid-cols-[auto_1fr_100px] items-center gap-3 lg:grid-cols-[auto_1fr_140px]"
                        >
                            <StatusBadge :tone="svc.status" dot-only />
                            <span class="truncate text-sm text-text-primary">
                                {{ svc.name }}
                            </span>
                            <Sparkline
                                :points="svc.sparkline"
                                :accent="
                                    svc.status === 'warning'
                                        ? 'magenta'
                                        : 'success'
                                "
                                :height="20"
                            />
                        </li>
                    </ul>
                    <footer class="text-[11px] text-text-muted">
                        Full widget lands with phase 6 — Docker Host Agent.
                    </footer>
                </section>

                <!-- Activity Heatmap — 7 days × 6 four-hour buckets per §8.11 -->
                <section
                    aria-label="Activity Heatmap"
                    class="glass-card flex flex-col gap-4 p-5 lg:col-span-12"
                >
                    <header class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <Activity
                                class="h-4 w-4 text-accent-magenta"
                                aria-hidden="true"
                            />
                            <h2 class="text-sm font-semibold text-text-primary">
                                Activity Heatmap
                            </h2>
                        </div>
                        <span class="hidden font-mono text-[11px] text-text-muted sm:inline">
                            7 days · 4-hour buckets · last 90 days
                        </span>
                    </header>
                    <ActivityHeatmap :data="activityHeatmap" />
                </section>

                <!-- Catch-all placeholder for chart-heavy widgets that need
                     dedicated specs (map, charts, gauges, timeline). One
                     card to keep the page dense without sprawling. -->
                <section
                    aria-label="Visualizations placeholder"
                    class="glass-card flex flex-col gap-3 p-5 lg:col-span-12"
                >
                    <header class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <BarChart3
                                class="h-4 w-4 text-accent-magenta"
                                aria-hidden="true"
                            />
                            <h2 class="text-sm font-semibold text-text-primary">
                                Visualizations
                            </h2>
                        </div>
                        <StatusBadge tone="muted">Pending</StatusBadge>
                    </header>
                    <div
                        class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5"
                    >
                        <div
                            v-for="placeholder in visualizationStubs"
                            :key="placeholder.label"
                            class="flex flex-col gap-2 rounded-lg border border-dashed border-border-subtle bg-background-panel-hover/30 p-3"
                        >
                            <component
                                :is="placeholder.icon"
                                class="h-4 w-4 text-text-muted"
                                aria-hidden="true"
                            />
                            <p class="text-xs text-text-secondary">
                                {{ placeholder.label }}
                            </p>
                            <p
                                class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                            >
                                {{ placeholder.phase }}
                            </p>
                        </div>
                    </div>
                    <footer class="text-[11px] text-text-muted">
                        Chart, map, and gauge widgets land with their owning
                        phases.
                    </footer>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
