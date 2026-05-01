<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import { projectIcon } from '@/lib/projectIcons';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ChevronLeft,
    CircleDot,
    ExternalLink,
    FolderKanban,
    GitBranch,
    GitFork,
    GitPullRequest,
    LayoutGrid,
    MessageSquare,
    RefreshCcw,
    Star,
    Trash2,
    Workflow,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

interface RepositoryShape {
    id: number;
    owner: string;
    name: string;
    full_name: string;
    html_url: string;
    default_branch: string;
    visibility: string;
    language: string | null;
    description: string | null;
    stars_count: number;
    forks_count: number;
    open_issues_count: number;
    open_prs_count: number;
    last_pushed_at: string | null;
    last_synced_at: string | null;
    sync_status: string | null;
    sync_error: string | null;
    sync_failed_at: string | null;
    project: {
        id: number;
        slug: string;
        name: string;
        color: string | null;
        icon: string | null;
    } | null;
}

interface IssueRow {
    id: number;
    number: number;
    title: string;
    state: 'open' | 'closed' | string | null;
    author_login: string | null;
    comments_count: number;
    updated_at_github: string | null;
    html_url: string;
}

interface PullRequestRow {
    id: number;
    number: number;
    title: string;
    state: 'open' | 'closed' | 'merged' | string | null;
    author_login: string | null;
    base_branch: string;
    head_branch: string;
    draft: boolean;
    comments_count: number;
    updated_at_github: string | null;
    html_url: string;
}

interface WorkflowRunRow {
    id: number;
    run_number: number;
    name: string;
    event: string;
    status:
        | 'queued'
        | 'in_progress'
        | 'completed'
        | string
        | null;
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
    run_updated_at: string | null;
}

interface SyncShape {
    status: string | null;
    synced_at: string | null;
    error: string | null;
    failed_at: string | null;
}

const props = defineProps<{
    repository: RepositoryShape;
    canDelete: boolean;
    canSync: boolean;
    issues: IssueRow[];
    issuesSync: SyncShape;
    pullRequests: PullRequestRow[];
    pullRequestsSync: SyncShape;
    workflowRuns: WorkflowRunRow[];
    workflowRunsSync: SyncShape;
}>();

const tab = ref<'overview' | 'issues' | 'pulls' | 'workflow-runs'>('overview');

const syncStatusTone = (status: string | null) =>
    (
        ({
            pending: 'muted',
            syncing: 'info',
            synced: 'success',
            failed: 'danger',
        }) as const
    )[status ?? ''] ?? 'muted';

const issueStateTone = (state: string | null) =>
    state === 'open' ? 'info' : 'muted';

const prStateTone = (state: string | null) =>
    (
        ({
            open: 'info',
            merged: 'success',
            closed: 'muted',
        }) as const
    )[state ?? ''] ?? 'muted';

const workflowConclusionTone = (conclusion: string | null) =>
    (
        ({
            success: 'success',
            failure: 'danger',
            cancelled: 'warning',
            timed_out: 'warning',
            action_required: 'warning',
            stale: 'muted',
            neutral: 'muted',
            skipped: 'muted',
        }) as const
    )[conclusion ?? ''] ?? 'muted';

// Tone for the run-status badge shown when a run has no conclusion
// yet — `WorkflowRunConclusion` is null pre-completion. Keys match
// `WorkflowRunStatus::badgeTone()` on the PHP side so the two
// renderers agree.
const workflowStatusTone = (status: string | null) =>
    (
        ({
            queued: 'muted',
            in_progress: 'info',
            completed: 'success',
        }) as const
    )[status ?? ''] ?? 'muted';

// Display label for the conclusion badge — `timed_out` reads cleaner
// as `timed out`, etc. Falls back to a humanized form for unknowns.
const workflowConclusionLabel = (conclusion: string | null) =>
    conclusion === null ? '—' : conclusion.replace(/_/g, ' ');

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

const issuesCount = computed(() => props.issues.length);
const pullRequestsCount = computed(() => props.pullRequests.length);
const workflowRunsCount = computed(() => props.workflowRuns.length);
const isIssuesSyncing = computed(() => props.issuesSync.status === 'syncing');
const isPullRequestsSyncing = computed(
    () => props.pullRequestsSync.status === 'syncing',
);
const isWorkflowRunsSyncing = computed(
    () => props.workflowRunsSync.status === 'syncing',
);
const isRepositorySyncing = computed(
    () => props.repository.sync_status === 'syncing',
);

const confirmDelete = () => {
    if (!props.canDelete) return;
    if (
        !window.confirm(
            `Unlink ${props.repository.full_name} from this project? You can re-link it later.`,
        )
    ) {
        return;
    }
    router.delete(route('repositories.destroy', props.repository.full_name));
};

const runIssuesSync = () => {
    if (!props.canSync) return;
    router.post(
        route('repositories.issues.sync', props.repository.full_name),
        {},
        { preserveScroll: true },
    );
};

const runPullRequestsSync = () => {
    if (!props.canSync) return;
    router.post(
        route('repositories.pulls.sync', props.repository.full_name),
        {},
        { preserveScroll: true },
    );
};

const runWorkflowRunsSync = () => {
    if (!props.canSync) return;
    router.post(
        route('repositories.workflow-runs.sync', props.repository.full_name),
        {},
        { preserveScroll: true },
    );
};

const runRepositorySync = () => {
    if (!props.canSync) return;
    router.post(
        route('repositories.sync', props.repository.full_name),
        {},
        { preserveScroll: true },
    );
};

// While ANY of the four sync flows (repository / issues / PRs / workflow
// runs) is in `syncing` state, poll the controller every 2.5s for the
// latest status — a partial Inertia reload that re-fetches just the
// sync-related props so the page updates without flashing or losing
// scroll. Stops on its own as soon as all four flip out of `syncing`.
// Spec 019 will replace this with Reverb broadcasts.
const POLL_INTERVAL_MS = 2500;
let pollHandle: ReturnType<typeof setInterval> | null = null;

const anySyncing = computed(
    () =>
        isRepositorySyncing.value ||
        isIssuesSyncing.value ||
        isPullRequestsSyncing.value ||
        isWorkflowRunsSyncing.value,
);

const stopPolling = () => {
    if (pollHandle !== null) {
        clearInterval(pollHandle);
        pollHandle = null;
    }
};

const startPolling = () => {
    if (pollHandle !== null) return;
    pollHandle = setInterval(() => {
        // `router.reload` is a same-route partial fetch — no navigation, so
        // scroll + component state are preserved by default. `only` keeps
        // the payload tiny (just the sync-related props plus the lists
        // they affect once the sync lands).
        router.reload({
            only: [
                'repository',
                'issuesSync',
                'pullRequestsSync',
                'workflowRunsSync',
                'workflowRuns',
            ],
        });
    }, POLL_INTERVAL_MS);
};

watch(
    anySyncing,
    (now) => {
        if (now) startPolling();
        else stopPolling();
    },
    { immediate: true },
);

onBeforeUnmount(stopPolling);
</script>

<template>
    <Head :title="repository.full_name" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <Link
                    :href="route('repositories.index')"
                    class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan transition hover:text-accent-cyan/80"
                >
                    <ChevronLeft class="h-3 w-3" aria-hidden="true" />
                    Repositories
                </Link>
                <h1
                    class="truncate font-mono text-lg font-semibold text-text-primary"
                >
                    {{ repository.full_name }}
                </h1>
            </div>
        </template>

        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <!-- Detail header -->
            <header class="glass-card flex flex-col gap-5 p-6 sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-start gap-4">
                        <span
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-border-subtle bg-background-panel-hover"
                        >
                            <GitBranch
                                class="h-6 w-6 text-accent-purple"
                                aria-hidden="true"
                            />
                        </span>
                        <div class="flex min-w-0 flex-col gap-2">
                            <h2
                                class="font-mono text-xl font-semibold text-text-primary"
                            >
                                {{ repository.full_name }}
                            </h2>
                            <p
                                v-if="repository.description"
                                class="text-sm text-text-secondary"
                            >
                                {{ repository.description }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <a
                            :href="repository.html_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm font-semibold text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        >
                            <ExternalLink class="h-4 w-4" aria-hidden="true" />
                            View on GitHub
                        </a>
                        <button
                            v-if="canSync"
                            type="button"
                            :disabled="isRepositorySyncing"
                            class="inline-flex items-center gap-2 rounded-lg border border-accent-cyan/40 bg-accent-cyan/15 px-3 py-2 text-sm font-semibold text-accent-cyan transition hover:border-accent-cyan/60 disabled:cursor-not-allowed disabled:opacity-60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                            @click="runRepositorySync"
                        >
                            <RefreshCcw
                                class="h-4 w-4"
                                :class="{ 'animate-spin': isRepositorySyncing }"
                                aria-hidden="true"
                            />
                            {{ isRepositorySyncing ? 'Syncing…' : 'Run sync' }}
                        </button>
                        <button
                            v-if="canDelete"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-status-danger/40 bg-status-danger/10 px-3 py-2 text-sm font-semibold text-status-danger transition hover:bg-status-danger/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-status-danger/60"
                            @click="confirmDelete"
                        >
                            <Trash2 class="h-4 w-4" aria-hidden="true" />
                            Unlink
                        </button>
                    </div>
                </div>

                <!-- Linked project + sync status strip -->
                <dl
                    class="grid grid-cols-2 gap-4 border-t border-border-subtle pt-5 text-sm md:grid-cols-4"
                >
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Project
                        </dt>
                        <dd v-if="repository.project">
                            <Link
                                :href="route('projects.show', repository.project.slug)"
                                class="inline-flex items-center gap-2 text-text-secondary transition hover:text-text-primary"
                            >
                                <component
                                    :is="
                                        projectIcon(repository.project.icon) ??
                                        FolderKanban
                                    "
                                    class="h-3.5 w-3.5"
                                    :class="
                                        projectAccentClass(repository.project.color)
                                    "
                                    aria-hidden="true"
                                />
                                <span class="truncate">{{ repository.project.name }}</span>
                            </Link>
                        </dd>
                        <dd v-else class="text-text-muted">—</dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Default branch
                        </dt>
                        <dd class="font-mono text-text-secondary">
                            {{ repository.default_branch }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Language
                        </dt>
                        <dd class="text-text-secondary">
                            {{ repository.language ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Sync status
                        </dt>
                        <dd>
                            <StatusBadge
                                v-if="repository.sync_status"
                                :tone="syncStatusTone(repository.sync_status)"
                            >
                                {{ repository.sync_status }}
                            </StatusBadge>
                        </dd>
                    </div>
                </dl>
            </header>

            <!-- Repository metadata sync failure -->
            <section
                v-if="repository.sync_status === 'failed' && repository.sync_error"
                aria-live="polite"
                class="flex items-start gap-3 rounded-lg border border-status-danger/40 bg-status-danger/10 p-4"
            >
                <AlertTriangle
                    class="mt-0.5 h-4 w-4 shrink-0 text-status-danger"
                    aria-hidden="true"
                />
                <div class="flex min-w-0 flex-col gap-1 text-sm">
                    <p class="font-semibold text-status-danger">
                        Last repository sync failed<span
                            v-if="repository.sync_failed_at"
                            class="font-normal text-text-muted"
                        >
                            · {{ repository.sync_failed_at }}</span>
                    </p>
                    <p class="break-words font-mono text-xs text-text-secondary">
                        {{ repository.sync_error }}
                    </p>
                </div>
            </section>

            <!-- Tabs -->
            <nav
                aria-label="Repository tabs"
                class="flex flex-wrap items-center gap-2"
            >
                <button
                    type="button"
                    :class="[
                        'inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.22em] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60',
                        tab === 'overview'
                            ? 'border-accent-cyan/60 bg-accent-cyan/15 text-accent-cyan'
                            : 'border-border-subtle bg-background-panel-hover text-text-secondary hover:text-text-primary',
                    ]"
                    @click="tab = 'overview'"
                >
                    <LayoutGrid class="h-3.5 w-3.5" aria-hidden="true" />
                    Overview
                </button>
                <button
                    type="button"
                    :class="[
                        'inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.22em] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60',
                        tab === 'issues'
                            ? 'border-accent-cyan/60 bg-accent-cyan/15 text-accent-cyan'
                            : 'border-border-subtle bg-background-panel-hover text-text-secondary hover:text-text-primary',
                    ]"
                    @click="tab = 'issues'"
                >
                    <CircleDot class="h-3.5 w-3.5" aria-hidden="true" />
                    Issues
                    <span
                        v-if="issuesCount > 0"
                        class="rounded-full border border-current/40 px-1.5 py-0.5 text-[10px] font-mono"
                    >
                        {{ issuesCount }}
                    </span>
                </button>
                <button
                    type="button"
                    :class="[
                        'inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.22em] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60',
                        tab === 'pulls'
                            ? 'border-accent-cyan/60 bg-accent-cyan/15 text-accent-cyan'
                            : 'border-border-subtle bg-background-panel-hover text-text-secondary hover:text-text-primary',
                    ]"
                    @click="tab = 'pulls'"
                >
                    <GitPullRequest class="h-3.5 w-3.5" aria-hidden="true" />
                    Pull Requests
                    <span
                        v-if="pullRequestsCount > 0"
                        class="rounded-full border border-current/40 px-1.5 py-0.5 text-[10px] font-mono"
                    >
                        {{ pullRequestsCount }}
                    </span>
                </button>
                <button
                    type="button"
                    :class="[
                        'inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.22em] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60',
                        tab === 'workflow-runs'
                            ? 'border-accent-cyan/60 bg-accent-cyan/15 text-accent-cyan'
                            : 'border-border-subtle bg-background-panel-hover text-text-secondary hover:text-text-primary',
                    ]"
                    @click="tab = 'workflow-runs'"
                >
                    <Workflow class="h-3.5 w-3.5" aria-hidden="true" />
                    Workflow Runs
                    <span
                        v-if="workflowRunsCount > 0"
                        class="rounded-full border border-current/40 px-1.5 py-0.5 text-[10px] font-mono"
                    >
                        {{ workflowRunsCount }}
                    </span>
                </button>
            </nav>

            <!-- Overview panel -->
            <template v-if="tab === 'overview'">
                <section
                    aria-label="Activity counts"
                    class="grid grid-cols-2 gap-4 md:grid-cols-4"
                >
                    <div class="glass-card flex flex-col gap-2 p-5">
                        <div class="flex items-center gap-2 text-text-muted">
                            <Star class="h-4 w-4 text-accent-cyan" aria-hidden="true" />
                            <span class="font-mono text-[10px] uppercase tracking-[0.18em]">
                                Stars
                            </span>
                        </div>
                        <span class="font-display text-2xl font-semibold tabular-nums text-text-primary">
                            {{ repository.stars_count }}
                        </span>
                    </div>
                    <div class="glass-card flex flex-col gap-2 p-5">
                        <div class="flex items-center gap-2 text-text-muted">
                            <GitFork class="h-4 w-4 text-accent-purple" aria-hidden="true" />
                            <span class="font-mono text-[10px] uppercase tracking-[0.18em]">
                                Forks
                            </span>
                        </div>
                        <span class="font-display text-2xl font-semibold tabular-nums text-text-primary">
                            {{ repository.forks_count }}
                        </span>
                    </div>
                    <div class="glass-card flex flex-col gap-2 p-5">
                        <div class="flex items-center gap-2 text-text-muted">
                            <MessageSquare class="h-4 w-4 text-accent-magenta" aria-hidden="true" />
                            <span class="font-mono text-[10px] uppercase tracking-[0.18em]">
                                Open issues
                            </span>
                        </div>
                        <span class="font-display text-2xl font-semibold tabular-nums text-text-primary">
                            {{ repository.open_issues_count }}
                        </span>
                    </div>
                    <div class="glass-card flex flex-col gap-2 p-5">
                        <div class="flex items-center gap-2 text-text-muted">
                            <GitPullRequest class="h-4 w-4 text-accent-blue" aria-hidden="true" />
                            <span class="font-mono text-[10px] uppercase tracking-[0.18em]">
                                Open PRs
                            </span>
                        </div>
                        <span class="font-display text-2xl font-semibold tabular-nums text-text-primary">
                            {{ repository.open_prs_count }}
                        </span>
                    </div>
                </section>

                <section class="glass-card p-6 sm:p-8">
                    <h3 class="text-sm font-semibold text-text-primary">
                        Sync activity
                    </h3>
                    <p class="mt-2 text-sm text-text-secondary">
                        Repository metadata refreshes via spec 014's sync job
                        on import and on demand. Spec 015 adds the issues
                        mirror — see the Issues tab.
                    </p>
                    <dl
                        class="mt-6 grid grid-cols-2 gap-4 text-sm md:grid-cols-3"
                    >
                        <div class="flex flex-col gap-1">
                            <dt
                                class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                            >
                                Visibility
                            </dt>
                            <dd class="text-text-secondary">
                                {{ repository.visibility }}
                            </dd>
                        </div>
                        <div class="flex flex-col gap-1">
                            <dt
                                class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                            >
                                Last pushed
                            </dt>
                            <dd class="text-text-secondary">
                                {{ repository.last_pushed_at ?? '—' }}
                            </dd>
                        </div>
                        <div class="flex flex-col gap-1">
                            <dt
                                class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                            >
                                Last synced
                            </dt>
                            <dd class="text-text-secondary">
                                {{ repository.last_synced_at ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </template>

            <!-- Issues panel -->
            <template v-else-if="tab === 'issues'">
                <section aria-label="Issues" class="glass-card p-6 sm:p-8">
                    <header
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle pb-4"
                    >
                        <div class="flex items-center gap-3">
                            <h3 class="text-sm font-semibold text-text-primary">
                                Issues mirror
                            </h3>
                            <StatusBadge
                                v-if="issuesSync.status"
                                :tone="syncStatusTone(issuesSync.status)"
                            >
                                {{ issuesSync.status }}
                            </StatusBadge>
                            <span
                                v-if="issuesSync.synced_at"
                                class="text-xs text-text-muted"
                            >
                                Last sync
                                <span class="font-mono text-text-secondary">{{ issuesSync.synced_at }}</span>
                            </span>
                            <span
                                v-else-if="issuesSync.status === 'failed' && issuesSync.failed_at"
                                class="text-xs text-text-muted"
                            >
                                Failed
                                <span class="font-mono text-status-danger">{{ issuesSync.failed_at }}</span>
                            </span>
                        </div>
                        <button
                            v-if="canSync"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-accent-cyan/40 bg-accent-cyan/15 px-3 py-2 text-sm font-semibold text-accent-cyan transition hover:border-accent-cyan/60 disabled:cursor-not-allowed disabled:opacity-60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                            :disabled="isIssuesSyncing"
                            @click="runIssuesSync"
                        >
                            <RefreshCcw class="h-4 w-4" aria-hidden="true" />
                            {{ isIssuesSyncing ? 'Syncing…' : 'Run sync' }}
                        </button>
                    </header>

                    <p
                        v-if="issuesSync.status === 'failed' && issuesSync.error"
                        class="mt-4 flex items-start gap-2 rounded-lg border border-status-danger/40 bg-status-danger/10 p-3 text-xs"
                    >
                        <AlertTriangle
                            class="mt-0.5 h-3.5 w-3.5 shrink-0 text-status-danger"
                            aria-hidden="true"
                        />
                        <span class="break-words font-mono text-text-secondary">
                            {{ issuesSync.error }}
                        </span>
                    </p>

                    <ul
                        v-if="issues.length > 0"
                        class="mt-2 divide-y divide-border-subtle"
                    >
                        <li
                            v-for="issue in issues"
                            :key="issue.id"
                            class="flex flex-col gap-2 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <CircleDot
                                    class="mt-1 h-4 w-4 shrink-0"
                                    :class="
                                        issue.state === 'open'
                                            ? 'text-accent-cyan'
                                            : 'text-text-muted'
                                    "
                                    aria-hidden="true"
                                />
                                <div class="flex min-w-0 flex-col gap-1">
                                    <a
                                        :href="issue.html_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="truncate text-sm font-semibold text-text-primary transition hover:text-accent-cyan"
                                    >
                                        <span class="font-mono text-text-muted">#{{ issue.number }}</span>
                                        {{ issue.title }}
                                    </a>
                                    <p class="text-xs text-text-muted">
                                        <span v-if="issue.author_login">
                                            <span class="font-mono text-text-secondary">@{{ issue.author_login }}</span>
                                            ·
                                        </span>
                                        Updated {{ issue.updated_at_github ?? '—' }}
                                    </p>
                                </div>
                            </div>
                            <div
                                class="flex flex-shrink-0 items-center gap-3 text-xs text-text-muted"
                            >
                                <StatusBadge :tone="issueStateTone(issue.state)">
                                    {{ issue.state }}
                                </StatusBadge>
                                <span class="inline-flex items-center gap-1">
                                    <MessageSquare class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ issue.comments_count }}
                                </span>
                                <a
                                    :href="issue.html_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-text-muted transition hover:text-accent-cyan"
                                    :aria-label="`Open issue #${issue.number} on GitHub`"
                                >
                                    <ExternalLink class="h-4 w-4" aria-hidden="true" />
                                </a>
                            </div>
                        </li>
                    </ul>

                    <p
                        v-else
                        class="mt-6 rounded-lg border border-dashed border-border-subtle bg-background-panel-hover/30 p-4 text-sm text-text-muted"
                    >
                        <span v-if="issuesSync.status === 'pending'">
                            Issues haven't been synced yet.
                        </span>
                        <span v-else-if="issuesSync.status === 'failed'">
                            The last issues sync failed.
                        </span>
                        <span v-else>No issues mirrored for this repository.</span>
                        <span v-if="canSync"> Click <span class="font-mono">Run sync</span> to fetch from GitHub.</span>
                    </p>
                </section>
            </template>

            <!-- Pull Requests panel -->
            <template v-else-if="tab === 'pulls'">
                <section aria-label="Pull Requests" class="glass-card p-6 sm:p-8">
                    <header
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle pb-4"
                    >
                        <div class="flex items-center gap-3">
                            <h3 class="text-sm font-semibold text-text-primary">
                                Pull requests mirror
                            </h3>
                            <StatusBadge
                                v-if="pullRequestsSync.status"
                                :tone="syncStatusTone(pullRequestsSync.status)"
                            >
                                {{ pullRequestsSync.status }}
                            </StatusBadge>
                            <span
                                v-if="pullRequestsSync.synced_at"
                                class="text-xs text-text-muted"
                            >
                                Last sync
                                <span class="font-mono text-text-secondary">{{ pullRequestsSync.synced_at }}</span>
                            </span>
                            <span
                                v-else-if="pullRequestsSync.status === 'failed' && pullRequestsSync.failed_at"
                                class="text-xs text-text-muted"
                            >
                                Failed
                                <span class="font-mono text-status-danger">{{ pullRequestsSync.failed_at }}</span>
                            </span>
                        </div>
                        <button
                            v-if="canSync"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-accent-cyan/40 bg-accent-cyan/15 px-3 py-2 text-sm font-semibold text-accent-cyan transition hover:border-accent-cyan/60 disabled:cursor-not-allowed disabled:opacity-60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                            :disabled="isPullRequestsSyncing"
                            @click="runPullRequestsSync"
                        >
                            <RefreshCcw class="h-4 w-4" aria-hidden="true" />
                            {{ isPullRequestsSyncing ? 'Syncing…' : 'Run sync' }}
                        </button>
                    </header>

                    <p
                        v-if="pullRequestsSync.status === 'failed' && pullRequestsSync.error"
                        class="mt-4 flex items-start gap-2 rounded-lg border border-status-danger/40 bg-status-danger/10 p-3 text-xs"
                    >
                        <AlertTriangle
                            class="mt-0.5 h-3.5 w-3.5 shrink-0 text-status-danger"
                            aria-hidden="true"
                        />
                        <span class="break-words font-mono text-text-secondary">
                            {{ pullRequestsSync.error }}
                        </span>
                    </p>

                    <ul
                        v-if="pullRequests.length > 0"
                        class="mt-2 divide-y divide-border-subtle"
                    >
                        <li
                            v-for="pr in pullRequests"
                            :key="pr.id"
                            class="flex flex-col gap-2 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <GitPullRequest
                                    class="mt-1 h-4 w-4 shrink-0"
                                    :class="{
                                        'text-accent-cyan': pr.state === 'open',
                                        'text-status-success': pr.state === 'merged',
                                        'text-text-muted':
                                            pr.state === 'closed' || !pr.state,
                                    }"
                                    aria-hidden="true"
                                />
                                <div class="flex min-w-0 flex-col gap-1">
                                    <a
                                        :href="pr.html_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="truncate text-sm font-semibold text-text-primary transition hover:text-accent-cyan"
                                    >
                                        <span class="font-mono text-text-muted">#{{ pr.number }}</span>
                                        {{ pr.title }}
                                    </a>
                                    <p class="text-xs text-text-muted">
                                        <span v-if="pr.author_login">
                                            <span class="font-mono text-text-secondary">@{{ pr.author_login }}</span>
                                            ·
                                        </span>
                                        <span class="font-mono text-text-secondary">
                                            {{ pr.base_branch }} ← {{ pr.head_branch }}
                                        </span>
                                        · Updated {{ pr.updated_at_github ?? '—' }}
                                    </p>
                                </div>
                            </div>
                            <div
                                class="flex flex-shrink-0 items-center gap-3 text-xs text-text-muted"
                            >
                                <StatusBadge
                                    v-if="pr.draft"
                                    tone="muted"
                                >
                                    draft
                                </StatusBadge>
                                <StatusBadge :tone="prStateTone(pr.state)">
                                    {{ pr.state }}
                                </StatusBadge>
                                <span class="inline-flex items-center gap-1">
                                    <MessageSquare class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ pr.comments_count }}
                                </span>
                                <a
                                    :href="pr.html_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-text-muted transition hover:text-accent-cyan"
                                    :aria-label="`Open pull request #${pr.number} on GitHub`"
                                >
                                    <ExternalLink class="h-4 w-4" aria-hidden="true" />
                                </a>
                            </div>
                        </li>
                    </ul>

                    <p
                        v-else
                        class="mt-6 rounded-lg border border-dashed border-border-subtle bg-background-panel-hover/30 p-4 text-sm text-text-muted"
                    >
                        <span v-if="pullRequestsSync.status === 'pending'">
                            Pull requests haven't been synced yet.
                        </span>
                        <span v-else-if="pullRequestsSync.status === 'failed'">
                            The last pull requests sync failed.
                        </span>
                        <span v-else>No pull requests mirrored for this repository.</span>
                        <span v-if="canSync"> Click <span class="font-mono">Run sync</span> to fetch from GitHub.</span>
                    </p>
                </section>
            </template>

            <!-- Workflow Runs panel -->
            <template v-else-if="tab === 'workflow-runs'">
                <section aria-label="Workflow Runs" class="glass-card p-6 sm:p-8">
                    <header
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle pb-4"
                    >
                        <div class="flex items-center gap-3">
                            <h3 class="text-sm font-semibold text-text-primary">
                                Workflow runs mirror
                            </h3>
                            <StatusBadge
                                v-if="workflowRunsSync.status"
                                :tone="syncStatusTone(workflowRunsSync.status)"
                            >
                                {{ workflowRunsSync.status }}
                            </StatusBadge>
                            <span
                                v-if="workflowRunsSync.synced_at"
                                class="text-xs text-text-muted"
                            >
                                Last sync
                                <span class="font-mono text-text-secondary">{{ workflowRunsSync.synced_at }}</span>
                            </span>
                            <span
                                v-else-if="workflowRunsSync.status === 'failed' && workflowRunsSync.failed_at"
                                class="text-xs text-text-muted"
                            >
                                Failed
                                <span class="font-mono text-status-danger">{{ workflowRunsSync.failed_at }}</span>
                            </span>
                        </div>
                        <button
                            v-if="canSync"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-accent-cyan/40 bg-accent-cyan/15 px-3 py-2 text-sm font-semibold text-accent-cyan transition hover:border-accent-cyan/60 disabled:cursor-not-allowed disabled:opacity-60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                            :disabled="isWorkflowRunsSyncing"
                            @click="runWorkflowRunsSync"
                        >
                            <RefreshCcw class="h-4 w-4" aria-hidden="true" />
                            {{ isWorkflowRunsSyncing ? 'Syncing…' : 'Run sync' }}
                        </button>
                    </header>

                    <p
                        v-if="workflowRunsSync.status === 'failed' && workflowRunsSync.error"
                        class="mt-4 flex items-start gap-2 rounded-lg border border-status-danger/40 bg-status-danger/10 p-3 text-xs"
                    >
                        <AlertTriangle
                            class="mt-0.5 h-3.5 w-3.5 shrink-0 text-status-danger"
                            aria-hidden="true"
                        />
                        <span class="break-words font-mono text-text-secondary">
                            {{ workflowRunsSync.error }}
                        </span>
                    </p>

                    <ul
                        v-if="workflowRuns.length > 0"
                        class="mt-2 divide-y divide-border-subtle"
                    >
                        <li
                            v-for="run in workflowRuns"
                            :key="run.id"
                            class="flex flex-col gap-2 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <Workflow
                                    class="mt-1 h-4 w-4 shrink-0"
                                    :class="{
                                        'text-status-success':
                                            run.conclusion === 'success',
                                        'text-status-danger':
                                            run.conclusion === 'failure',
                                        'text-status-warning':
                                            run.conclusion === 'cancelled' ||
                                            run.conclusion === 'timed_out' ||
                                            run.conclusion === 'action_required',
                                        'text-accent-cyan':
                                            run.status === 'in_progress',
                                        'text-text-muted':
                                            !run.conclusion &&
                                            run.status !== 'in_progress',
                                    }"
                                    aria-hidden="true"
                                />
                                <div class="flex min-w-0 flex-col gap-1">
                                    <a
                                        :href="run.html_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="truncate text-sm font-semibold text-text-primary transition hover:text-accent-cyan"
                                    >
                                        <span class="font-mono text-text-muted">#{{ run.run_number }}</span>
                                        {{ run.name }}
                                    </a>
                                    <p class="text-xs text-text-muted">
                                        <span v-if="run.actor_login">
                                            <span class="font-mono text-text-secondary">@{{ run.actor_login }}</span>
                                            ·
                                        </span>
                                        <span class="font-mono text-text-secondary">
                                            {{ run.event }}
                                        </span>
                                        <span v-if="run.head_branch">
                                            ·
                                            <span class="font-mono text-text-secondary">
                                                {{ run.head_branch }}
                                            </span>
                                        </span>
                                        · Started {{ run.run_started_at ?? '—' }}
                                    </p>
                                </div>
                            </div>
                            <div
                                class="flex flex-shrink-0 items-center gap-3 text-xs text-text-muted"
                            >
                                <StatusBadge
                                    v-if="run.conclusion"
                                    :tone="workflowConclusionTone(run.conclusion)"
                                >
                                    {{ workflowConclusionLabel(run.conclusion) }}
                                </StatusBadge>
                                <StatusBadge
                                    v-else
                                    :tone="workflowStatusTone(run.status)"
                                >
                                    {{ run.status }}
                                </StatusBadge>
                                <a
                                    :href="run.html_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-text-muted transition hover:text-accent-cyan"
                                    :aria-label="`Open workflow run #${run.run_number} on GitHub`"
                                >
                                    <ExternalLink class="h-4 w-4" aria-hidden="true" />
                                </a>
                            </div>
                        </li>
                    </ul>

                    <p
                        v-else
                        class="mt-6 rounded-lg border border-dashed border-border-subtle bg-background-panel-hover/30 p-4 text-sm text-text-muted"
                    >
                        <span v-if="workflowRunsSync.status === 'pending'">
                            Workflow runs haven't been synced yet.
                        </span>
                        <span v-else-if="workflowRunsSync.status === 'failed'">
                            The last workflow runs sync failed.
                        </span>
                        <span v-else>No workflow runs mirrored for this repository.</span>
                        <span v-if="canSync"> Click <span class="font-mono">Run sync</span> to fetch from GitHub.</span>
                    </p>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
