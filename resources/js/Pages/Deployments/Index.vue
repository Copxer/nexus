<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    conclusionLabel,
    conclusionTone,
    runStatusDotClass,
    runStatusTone,
} from '@/lib/workflowRunStyles';
import type { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    ExternalLink,
    FilterX,
    GitBranch,
    RefreshCcw,
    Rocket,
    WifiOff,
    Workflow,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import DeploymentDrawer from './DeploymentDrawer.vue';

interface ProjectRef {
    id: number;
    slug: string;
    name: string;
    color: string | null;
    icon: string | null;
}

interface RepositoryRef {
    id: number;
    full_name: string;
    name: string;
    html_url: string;
}

export interface DeploymentRow {
    id: number;
    github_id: number;
    run_number: number;
    name: string;
    event: string;
    status: 'queued' | 'in_progress' | 'completed' | string | null;
    conclusion:
        | 'success'
        | 'failure'
        | 'cancelled'
        | 'timed_out'
        | 'action_required'
        | 'stale'
        | 'neutral'
        | 'skipped'
        | string
        | null;
    head_branch: string | null;
    head_sha: string;
    actor_login: string | null;
    html_url: string;
    run_started_at: string | null;
    run_started_at_iso: string | null;
    run_updated_at: string | null;
    run_updated_at_iso: string | null;
    run_completed_at_iso: string | null;
    duration_seconds: number | null;
    repository: RepositoryRef | null;
    project: ProjectRef | null;
}

interface FilterState {
    project_id: number | null;
    repository_id: number | null;
    status: string | null;
    conclusion: string | null;
    branch: string | null;
}

interface FilterOptions {
    projects: { id: number; name: string; color: string | null }[];
    repositories: { id: number; full_name: string; project_id: number }[];
}

const props = defineProps<{
    deployments: DeploymentRow[];
    filters: FilterState;
    filterOptions: FilterOptions;
}>();

// Mirror the URL filter shape into reactive locals so dropdowns work
// against `v-model` without poking the prop directly.
const filterDraft = ref<FilterState>({ ...props.filters });

// Status / conclusion are static enums on the PHP side — hard-coded
// here is safer than serializing them through Inertia (no type drift
// to chase).
const statusOptions = [
    { value: 'queued', label: 'Queued' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'completed', label: 'Completed' },
] as const;

const conclusionOptions = [
    { value: 'success', label: 'Success' },
    { value: 'failure', label: 'Failure' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'timed_out', label: 'Timed out' },
    { value: 'action_required', label: 'Action required' },
    { value: 'stale', label: 'Stale' },
    { value: 'neutral', label: 'Neutral' },
    { value: 'skipped', label: 'Skipped' },
] as const;

// Repository dropdown narrows to the selected project's repos
// client-side. No extra controller call.
const visibleRepositories = computed(() => {
    if (filterDraft.value.project_id === null) {
        return props.filterOptions.repositories;
    }
    return props.filterOptions.repositories.filter(
        (r) => r.project_id === filterDraft.value.project_id,
    );
});

const hasActiveFilter = computed(() =>
    Object.values(filterDraft.value).some(
        (v) => v !== null && v !== '' && v !== undefined,
    ),
);

// Apply filter changes by navigating to a new URL — Inertia partial
// reload preserves scroll + state. The server validates + echoes back.
const applyFilters = () => {
    const params: Record<string, string | number> = {};
    for (const [key, value] of Object.entries(filterDraft.value)) {
        if (value !== null && value !== '' && value !== undefined) {
            params[key] = value;
        }
    }
    router.get(route('deployments.index'), params, {
        preserveScroll: true,
        preserveState: true,
        only: ['deployments', 'filters', 'filterOptions'],
    });
};

// When the project changes, blank out the repository — the new
// project's repository set may not include the previously selected one.
const onProjectChange = () => {
    filterDraft.value.repository_id = null;
    applyFilters();
};

const clearFilters = () => {
    filterDraft.value = {
        project_id: null,
        repository_id: null,
        status: null,
        conclusion: null,
        branch: null,
    };
    applyFilters();
};

const refresh = () => {
    router.reload({
        only: ['deployments', 'filterOptions'],
    });
};

// Day grouping. `run_started_at_iso` is the raw timestamp; this
// produces a stable group key (`2026-04-30`) and a render label.
const groupKey = (iso: string | null): string =>
    iso ? iso.slice(0, 10) : 'unknown';

const groupLabel = (key: string): string => {
    if (key === 'unknown') return 'Unknown date';
    const today = new Date().toISOString().slice(0, 10);
    if (key === today) return 'Today';
    const yesterday = new Date(Date.now() - 86_400_000)
        .toISOString()
        .slice(0, 10);
    if (key === yesterday) return 'Yesterday';
    return new Date(key + 'T00:00:00Z').toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};

const grouped = computed(() => {
    const order: string[] = [];
    const buckets = new Map<string, DeploymentRow[]>();
    for (const row of props.deployments) {
        const key = groupKey(row.run_started_at_iso);
        if (!buckets.has(key)) {
            order.push(key);
            buckets.set(key, []);
        }
        buckets.get(key)!.push(row);
    }
    return order.map((key) => ({ key, label: groupLabel(key), rows: buckets.get(key)! }));
});

// Conclusion / status tone helpers + status-dot class live in
// `@/lib/workflowRunStyles` so the cross-page set stays in sync when
// the GitHub conclusion enum grows.
const statusTone = runStatusTone;
const statusDotClass = runStatusDotClass;

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

// Drawer state.
const selectedRow = ref<DeploymentRow | null>(null);
const lastTriggerEl = ref<HTMLElement | null>(null);

const openDrawer = (row: DeploymentRow, ev: Event) => {
    lastTriggerEl.value = ev.currentTarget as HTMLElement;
    selectedRow.value = row;
};

const closeDrawer = () => {
    selectedRow.value = null;
    // Restore focus to the row that opened the drawer.
    requestAnimationFrame(() => {
        lastTriggerEl.value?.focus();
    });
};

// ─── Reverb subscription ────────────────────────────────────────────
// Listen to `users.{id}.deployments`. On any incoming pulse, partial-
// reload the timeline. Server applies the current filter state from
// the URL, so we don't replicate filter logic in JS.
const realtimeConnected = ref<boolean | null>(null);
let teardown: (() => void) | null = null;

onMounted(() => {
    if (typeof window === 'undefined' || !window.Echo) {
        return;
    }
    const page = usePage<PageProps>();
    const userId = page.props.auth?.user?.id;
    if (userId == null) return;

    const channelName = `users.${userId}.deployments`;
    const channel = window.Echo.private(channelName);

    channel.listen('.WorkflowRunUpserted', () => {
        // The pulse payload only carries `run_id` + `repository_id`;
        // we re-fetch the authoritative, filter-aware list and the
        // filter dropdowns. The dropdowns matter when a webhook from a
        // newly-imported repository arrives — without `filterOptions`
        // here, the new repo wouldn't appear in the dropdown until the
        // user navigates away and back.
        router.reload({
            only: ['deployments', 'filterOptions'],
        });
    });

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
        channel.stopListening('.WorkflowRunUpserted');
        window.Echo?.leave(`users.${userId}.deployments`);
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
    <Head title="Deployments" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <span
                    class="text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan"
                >
                    Phase 4
                </span>
                <h1 class="text-lg font-semibold text-text-primary">
                    Deployments
                </h1>
            </div>
        </template>

        <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
            <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
                <div class="flex flex-col gap-2">
                    <h2 class="text-xl font-semibold text-text-primary">
                        Deployment timeline
                    </h2>
                    <p class="text-sm text-text-secondary">
                        Cross-repo workflow runs from your connected projects.
                        Up to 100 entries shown — newer runs land live as
                        webhooks arrive.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <span
                        v-if="realtimeConnected === false"
                        class="inline-flex items-center gap-1.5 rounded-full border border-status-warning/40 bg-status-warning/10 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-status-warning"
                        title="Live updates offline. Use Refresh to see the latest runs."
                    >
                        <WifiOff class="h-3 w-3" aria-hidden="true" />
                        Live offline
                    </span>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-accent-cyan/40 bg-accent-cyan/15 px-3 py-2 text-sm font-semibold text-accent-cyan transition hover:border-accent-cyan/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        @click="refresh"
                    >
                        <RefreshCcw class="h-4 w-4" aria-hidden="true" />
                        Refresh
                    </button>
                </div>
            </header>

            <!-- Filter strip -->
            <section
                class="glass-card mb-6 flex flex-wrap items-end gap-3 p-4"
                aria-label="Filters"
            >
                <label class="flex flex-col gap-1 text-xs">
                    <span
                        class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                    >
                        Project
                    </span>
                    <select
                        v-model.number="filterDraft.project_id"
                        class="min-w-[140px] rounded-md border border-border-subtle bg-background-panel-hover px-2 py-1.5 text-sm text-text-primary focus:border-accent-cyan/60 focus:outline-none"
                        @change="onProjectChange"
                    >
                        <option :value="null">All projects</option>
                        <option
                            v-for="p in filterOptions.projects"
                            :key="p.id"
                            :value="p.id"
                        >
                            {{ p.name }}
                        </option>
                    </select>
                </label>

                <label class="flex flex-col gap-1 text-xs">
                    <span
                        class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                    >
                        Repository
                    </span>
                    <select
                        v-model.number="filterDraft.repository_id"
                        class="min-w-[200px] rounded-md border border-border-subtle bg-background-panel-hover px-2 py-1.5 text-sm text-text-primary focus:border-accent-cyan/60 focus:outline-none"
                        @change="applyFilters"
                    >
                        <option :value="null">All repositories</option>
                        <option
                            v-for="r in visibleRepositories"
                            :key="r.id"
                            :value="r.id"
                        >
                            {{ r.full_name }}
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
                        class="min-w-[120px] rounded-md border border-border-subtle bg-background-panel-hover px-2 py-1.5 text-sm text-text-primary focus:border-accent-cyan/60 focus:outline-none"
                        @change="applyFilters"
                    >
                        <option :value="null">Any status</option>
                        <option
                            v-for="opt in statusOptions"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.label }}
                        </option>
                    </select>
                </label>

                <label class="flex flex-col gap-1 text-xs">
                    <span
                        class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                    >
                        Conclusion
                    </span>
                    <select
                        v-model="filterDraft.conclusion"
                        class="min-w-[140px] rounded-md border border-border-subtle bg-background-panel-hover px-2 py-1.5 text-sm text-text-primary focus:border-accent-cyan/60 focus:outline-none"
                        @change="applyFilters"
                    >
                        <option :value="null">Any conclusion</option>
                        <option
                            v-for="opt in conclusionOptions"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.label }}
                        </option>
                    </select>
                </label>

                <label class="flex flex-col gap-1 text-xs">
                    <span
                        class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                    >
                        Branch
                    </span>
                    <input
                        v-model="filterDraft.branch"
                        type="text"
                        placeholder="main"
                        class="min-w-[140px] rounded-md border border-border-subtle bg-background-panel-hover px-2 py-1.5 text-sm text-text-primary placeholder:text-text-muted focus:border-accent-cyan/60 focus:outline-none"
                        @change="applyFilters"
                    />
                </label>

                <button
                    v-if="hasActiveFilter"
                    type="button"
                    class="inline-flex items-center gap-1.5 self-end rounded-md border border-border-subtle bg-background-panel-hover px-2.5 py-1.5 text-xs font-semibold text-text-secondary transition hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    @click="clearFilters"
                >
                    <FilterX class="h-3.5 w-3.5" aria-hidden="true" />
                    Clear
                </button>
            </section>

            <!-- Empty states -->
            <div
                v-if="deployments.length === 0 && !hasActiveFilter"
                class="glass-card flex flex-col items-center justify-center gap-3 px-6 py-16 text-center"
            >
                <span
                    class="flex h-12 w-12 items-center justify-center rounded-full border border-border-subtle bg-slate-950/60"
                >
                    <Rocket class="h-5 w-5 text-text-muted" aria-hidden="true" />
                </span>
                <p class="text-sm font-medium text-text-secondary">
                    No workflow runs yet
                </p>
                <p class="max-w-sm text-xs text-text-muted">
                    Import a repository or trigger a GitHub Action — runs will
                    land here once the webhook fires.
                </p>
            </div>

            <div
                v-else-if="deployments.length === 0"
                class="glass-card flex flex-col items-center justify-center gap-3 px-6 py-16 text-center"
            >
                <span
                    class="flex h-12 w-12 items-center justify-center rounded-full border border-border-subtle bg-slate-950/60"
                >
                    <AlertCircle
                        class="h-5 w-5 text-text-muted"
                        aria-hidden="true"
                    />
                </span>
                <p class="text-sm font-medium text-text-secondary">
                    No deployments match these filters
                </p>
                <button
                    type="button"
                    class="mt-1 inline-flex items-center gap-1.5 rounded-md border border-accent-cyan/40 bg-accent-cyan/15 px-3 py-1.5 text-xs font-semibold text-accent-cyan transition hover:border-accent-cyan/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    @click="clearFilters"
                >
                    <FilterX class="h-3.5 w-3.5" aria-hidden="true" />
                    Clear filters
                </button>
            </div>

            <!-- Timeline -->
            <div v-else class="flex flex-col gap-6">
                <section
                    v-for="group in grouped"
                    :key="group.key"
                    aria-label="Deployments group"
                >
                    <h3
                        class="mb-3 flex items-center gap-3 font-mono text-[10px] uppercase tracking-[0.22em] text-text-muted"
                    >
                        <span>{{ group.label }}</span>
                        <span
                            class="h-px flex-1 bg-border-subtle"
                            aria-hidden="true"
                        />
                    </h3>
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="row in group.rows"
                            :key="row.id"
                        >
                            <button
                                type="button"
                                class="glass-card flex w-full items-center gap-4 px-4 py-3 text-left transition hover:border-accent-cyan/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                @click="(ev) => openDrawer(row, ev)"
                            >
                                <span
                                    class="h-2.5 w-2.5 shrink-0 rounded-full"
                                    :class="statusDotClass(row)"
                                    aria-hidden="true"
                                />
                                <Workflow
                                    class="h-4 w-4 shrink-0 text-text-muted"
                                    aria-hidden="true"
                                />
                                <div class="flex min-w-0 flex-1 flex-col gap-1">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="truncate text-sm font-semibold text-text-primary"
                                        >
                                            <span
                                                class="font-mono text-text-muted"
                                                >#{{ row.run_number }}</span
                                            >
                                            {{ row.name }}
                                        </span>
                                        <span
                                            v-if="row.project"
                                            class="inline-flex items-center gap-1 rounded-full border border-current/30 px-1.5 py-0.5 text-[10px] font-mono uppercase tracking-[0.18em]"
                                            :class="
                                                projectAccentClass(
                                                    row.project.color,
                                                )
                                            "
                                        >
                                            {{ row.project.name }}
                                        </span>
                                    </div>
                                    <p
                                        class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-text-muted"
                                    >
                                        <span
                                            v-if="row.repository"
                                            class="font-mono text-text-secondary"
                                        >
                                            {{ row.repository.full_name }}
                                        </span>
                                        <span
                                            v-if="row.head_branch"
                                            class="inline-flex items-center gap-1 font-mono"
                                        >
                                            <GitBranch
                                                class="h-3 w-3"
                                                aria-hidden="true"
                                            />
                                            {{ row.head_branch }}
                                        </span>
                                        <span class="font-mono">{{
                                            row.event
                                        }}</span>
                                        <span v-if="row.actor_login">
                                            <span
                                                class="font-mono text-text-secondary"
                                            >
                                                @{{ row.actor_login }}
                                            </span>
                                        </span>
                                        <span v-if="row.run_started_at">
                                            · Started {{ row.run_started_at }}
                                        </span>
                                    </p>
                                </div>
                                <div
                                    class="flex shrink-0 items-center gap-2"
                                >
                                    <StatusBadge
                                        v-if="row.conclusion"
                                        :tone="conclusionTone(row.conclusion)"
                                    >
                                        {{ conclusionLabel(row.conclusion) }}
                                    </StatusBadge>
                                    <StatusBadge
                                        v-else
                                        :tone="statusTone(row.status)"
                                    >
                                        {{ row.status }}
                                    </StatusBadge>
                                    <a
                                        :href="row.html_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-text-muted transition hover:text-accent-cyan"
                                        :aria-label="`Open run #${row.run_number} on GitHub`"
                                        @click.stop
                                    >
                                        <ExternalLink
                                            class="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                    </a>
                                </div>
                            </button>
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <DeploymentDrawer
            :run="selectedRow"
            @close="closeDrawer"
        />
    </AppLayout>
</template>
