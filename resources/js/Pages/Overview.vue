<script setup lang="ts">
import ActivityHeatmap from '@/Components/Activity/ActivityHeatmap.vue';
import KpiCard from '@/Components/Dashboard/KpiCard.vue';
import Sparkline from '@/Components/Dashboard/Sparkline.vue';
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type {
    ActivityEvent,
    ActivityHeatmapPayload,
    DashboardPayload,
} from '@/types';
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

defineProps<{
    dashboard: DashboardPayload;
    recentActivity: ActivityEvent[];
    activityHeatmap: ActivityHeatmapPayload;
}>();

// ----- Hardcoded mock data for the populated stub widgets -----
// These widgets each have their own dedicated spec in later phases. The
// mock data here exists only so the page looks intentional, not skeletal —
// each stub footer points at the phase that will replace it.

const stubIssues = [
    {
        title: 'Login flow rejects valid 2FA codes intermittently',
        repo: 'nexus-web',
        time: '12 min',
        priority: 'critical' as const,
    },
    {
        title: 'Migrate analytics events to BigQuery sink',
        repo: 'nexus-api',
        time: '3 h',
        priority: 'high' as const,
    },
    {
        title: 'Add dark-mode tokens to email templates',
        repo: 'nexus-mail',
        time: '1 d',
        priority: 'medium' as const,
    },
    {
        title: 'Expose feature-flag SDK in the JS bundle',
        repo: 'nexus-flags',
        time: '2 d',
        priority: 'low' as const,
    },
];

const priorityToneMap = {
    critical: 'danger',
    high: 'warning',
    medium: 'info',
    low: 'muted',
} as const;

const stubRepos = [
    { name: 'nexus-web', commits: 124, share: 0.92 },
    { name: 'nexus-api', commits: 98, share: 0.74 },
    { name: 'infra-as-code', commits: 56, share: 0.42 },
    { name: 'nexus-mail', commits: 31, share: 0.23 },
];

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

const visualizationStubs = [
    { label: 'World map', icon: Globe, phase: 'Phase 8' },
    { label: 'Resource utilization', icon: Activity, phase: 'Phase 6' },
    { label: 'Website performance', icon: LineChart, phase: 'Phase 5' },
    { label: 'System metrics', icon: Gauge, phase: 'Phase 8' },
    { label: 'Deployment timeline', icon: Rocket, phase: 'Phase 4' },
] as const;
</script>

<template>
    <Head title="Overview" />

    <AppLayout :activity-events="recentActivity">
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
                    secondary="Successful"
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
                <!-- Issues & PRs -->
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
                        <span class="font-mono text-[11px] text-text-muted">
                            {{ stubIssues.length }} open · mock
                        </span>
                    </header>
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="issue in stubIssues"
                            :key="issue.title"
                            class="flex items-center gap-3 rounded-lg border border-border-subtle bg-background-panel-hover/40 p-3"
                        >
                            <StatusBadge :tone="priorityToneMap[issue.priority]">
                                {{ issue.priority }}
                            </StatusBadge>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm text-text-primary">
                                    {{ issue.title }}
                                </p>
                                <p
                                    class="truncate font-mono text-[11px] text-text-muted"
                                >
                                    {{ issue.repo }} · {{ issue.time }} ago
                                </p>
                            </div>
                        </li>
                    </ul>
                    <footer class="text-[11px] text-text-muted">
                        Full widget lands with phase 2 — Issues &amp; PRs sync.
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
                        <span class="font-mono text-[11px] text-text-muted">
                            7d · mock
                        </span>
                    </header>
                    <ul class="flex flex-col gap-3">
                        <li
                            v-for="repo in stubRepos"
                            :key="repo.name"
                            class="flex items-center gap-3"
                        >
                            <span
                                class="w-32 shrink-0 truncate font-mono text-xs text-text-secondary"
                            >
                                {{ repo.name }}
                            </span>
                            <div
                                class="relative h-2 flex-1 overflow-hidden rounded-full bg-background-panel-hover"
                            >
                                <span
                                    class="block h-full rounded-full bg-gradient-to-r from-accent-cyan to-accent-magenta"
                                    :style="{
                                        width: `${Math.round(repo.share * 100)}%`,
                                    }"
                                />
                            </div>
                            <span
                                class="w-20 shrink-0 text-right font-mono text-[11px] tabular-nums text-text-muted"
                            >
                                {{ repo.commits }} commits
                            </span>
                        </li>
                    </ul>
                    <footer class="text-[11px] text-text-muted">
                        Full widget lands with phase 1 — Repositories.
                    </footer>
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
                        <span class="font-mono text-[11px] text-text-muted">
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
                        <span class="font-mono text-[11px] text-text-muted">
                            {{ stubServices.length }} services · mock
                        </span>
                    </header>
                    <ul class="flex flex-col gap-3">
                        <li
                            v-for="svc in stubServices"
                            :key="svc.name"
                            class="grid grid-cols-[auto_1fr_140px] items-center gap-3"
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
                        Full widget lands with phase 5 — Website Monitoring.
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
                        <span class="font-mono text-[11px] text-text-muted">
                            7 days · 4-hour buckets · mock
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
                        class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-5"
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
