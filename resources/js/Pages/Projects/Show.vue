<script setup lang="ts">
import ActivityFeed from '@/Components/Activity/ActivityFeed.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import { projectIcon } from '@/lib/projectIcons';
import {
    conclusionLabel,
    conclusionTone,
    runStatusDotClass,
    runStatusTone,
} from '@/lib/workflowRunStyles';
import { websiteStatusTone as monitorStatusTone } from '@/lib/websiteStyles';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { ActivityEvent } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    ArrowRight,
    BarChart3,
    ChevronLeft,
    ExternalLink,
    FolderKanban,
    GitBranch,
    GitPullRequest,
    Globe,
    MessageSquare,
    Pencil,
    Plus,
    Rocket,
    Server,
    Settings,
    Trash2,
    Workflow,
    X,
    type LucideIcon,
} from 'lucide-vue-next';
import { ref } from 'vue';

interface ProjectShape {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    status: string | null;
    priority: string | null;
    environment: string | null;
    color: string | null;
    icon: string | null;
    health_score: number | null;
    last_activity_at: string | null;
    owner: { id: number; name: string; initials: string } | null;
}

interface RepositoryRow {
    id: number;
    owner: string;
    name: string;
    full_name: string;
    html_url: string;
    default_branch: string;
    visibility: string;
    language: string | null;
    open_issues_count: number;
    open_prs_count: number;
    last_pushed_at: string | null;
    sync_status: string | null;
}

interface DeploymentRow {
    id: number;
    run_number: number;
    name: string;
    event: string;
    status: string | null;
    conclusion: string | null;
    head_branch: string | null;
    actor_login: string | null;
    html_url: string;
    run_started_at: string | null;
    repository: { id: number; full_name: string; name: string } | null;
}

interface MonitorRow {
    id: number;
    name: string;
    url: string;
    method: string;
    status:
        | 'pending'
        | 'up'
        | 'down'
        | 'slow'
        | 'error'
        | string
        | null;
    last_checked_at: string | null;
}

const props = defineProps<{
    project: ProjectShape;
    canUpdate: boolean;
    canDelete: boolean;
    repositories: RepositoryRow[];
    hasGithubConnection: boolean;
    projectActivity: ActivityEvent[];
    projectDeployments: DeploymentRow[];
    projectMonitors: MonitorRow[];
}>();

const linkForm = useForm({
    project_id: props.project.id,
    repository: '',
});

const linkRepository = () => {
    linkForm.post(route('repositories.store'), {
        preserveScroll: true,
        onSuccess: () => {
            linkForm.reset('repository');
        },
    });
};

const confirmUnlink = (repo: RepositoryRow) => {
    if (!props.canUpdate) return;
    if (
        !window.confirm(
            `Unlink ${repo.full_name} from ${props.project.name}? You can re-link it later.`,
        )
    ) {
        return;
    }
    router.delete(route('repositories.destroy', repo.full_name), {
        preserveScroll: true,
    });
};

const syncStatusTone = (status: string | null) =>
    (
        ({
            pending: 'muted',
            syncing: 'info',
            synced: 'success',
            failed: 'danger',
        }) as const
    )[status ?? ''] ?? 'muted';

// `monitorStatusTone` lives in `@/lib/websiteStyles` so the four
// consumers (this page + Monitoring/Websites/Index + Show + future
// fourth) stay in sync when the WebsiteStatus enum grows a new case.

// Tab definitions. Overview + Settings have content this spec; the others
// advertise the phase that ships their real implementation.
type TabKey =
    | 'overview'
    | 'repositories'
    | 'deployments'
    | 'hosts'
    | 'monitoring'
    | 'activity'
    | 'settings';

const tabs: { key: TabKey; label: string; icon: LucideIcon; pendingPhase: string | null }[] = [
    { key: 'overview', label: 'Overview', icon: BarChart3, pendingPhase: null },
    { key: 'repositories', label: 'Repositories', icon: GitBranch, pendingPhase: null },
    { key: 'deployments', label: 'Deployments', icon: Rocket, pendingPhase: null },
    { key: 'hosts', label: 'Hosts', icon: Server, pendingPhase: 'phase 6' },
    { key: 'monitoring', label: 'Monitoring', icon: Globe, pendingPhase: null },
    { key: 'activity', label: 'Activity', icon: Activity, pendingPhase: null },
    { key: 'settings', label: 'Settings', icon: Settings, pendingPhase: null },
];

// Tone helpers live in `@/lib/workflowRunStyles` so the four
// consumers (this page + Repositories/Show + Deployments/Index +
// DeploymentDrawer) stay in sync when the GitHub conclusion enum
// grows a new case.

const activeTab = ref<TabKey>('overview');

const statusTone = (status: string | null) =>
    (
        ({
            active: 'success',
            maintenance: 'warning',
            paused: 'info',
            archived: 'muted',
        }) as const
    )[status ?? ''] ?? 'muted';

const priorityTone = (priority: string | null) =>
    (
        ({
            low: 'muted',
            medium: 'info',
            high: 'warning',
            critical: 'danger',
        }) as const
    )[priority ?? ''] ?? 'muted';

const iconAccentClass = (color: string | null) =>
    (
        ({
            cyan: 'text-accent-cyan shadow-[0_0_22px_rgba(34,211,238,0.45)]',
            blue: 'text-accent-blue shadow-[0_0_22px_rgba(56,189,248,0.45)]',
            purple: 'text-accent-purple shadow-[0_0_22px_rgba(139,92,246,0.45)]',
            magenta: 'text-accent-magenta shadow-[0_0_22px_rgba(217,70,239,0.45)]',
            success: 'text-status-success shadow-glow-success',
            warning: 'text-status-warning',
        }) as const
    )[color ?? ''] ?? 'text-text-muted';

const headerIcon = projectIcon(props.project.icon) ?? FolderKanban;

const confirmDelete = () => {
    if (!props.canDelete) return;
    if (
        !window.confirm(
            `Delete "${props.project.name}"? This action cannot be undone.`,
        )
    ) {
        return;
    }
    router.delete(route('projects.destroy', props.project.slug));
};
</script>

<template>
    <Head :title="project.name" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <Link
                    :href="route('projects.index')"
                    class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan transition hover:text-accent-cyan/80"
                >
                    <ChevronLeft class="h-3 w-3" aria-hidden="true" />
                    Projects
                </Link>
                <h1 class="truncate text-lg font-semibold text-text-primary">
                    {{ project.name }}
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
                            <component
                                :is="headerIcon"
                                class="h-6 w-6"
                                :class="iconAccentClass(project.color)"
                                aria-hidden="true"
                            />
                        </span>
                        <div class="flex min-w-0 flex-col gap-2">
                            <h2 class="text-xl font-semibold text-text-primary">
                                {{ project.name }}
                            </h2>
                            <p
                                v-if="project.description"
                                class="text-sm text-text-secondary"
                            >
                                {{ project.description }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link
                            v-if="canUpdate"
                            :href="route('projects.edit', project.slug)"
                            class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm font-semibold text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        >
                            <Pencil class="h-4 w-4" aria-hidden="true" />
                            Edit
                        </Link>
                        <button
                            v-if="canDelete"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-status-danger/40 bg-status-danger/10 px-3 py-2 text-sm font-semibold text-status-danger transition hover:bg-status-danger/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-status-danger/60"
                            @click="confirmDelete"
                        >
                            <Trash2 class="h-4 w-4" aria-hidden="true" />
                            Delete
                        </button>
                    </div>
                </div>

                <!-- Status / priority / owner / activity strip -->
                <dl
                    class="grid grid-cols-2 gap-4 border-t border-border-subtle pt-5 text-sm md:grid-cols-4"
                >
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Status
                        </dt>
                        <dd>
                            <StatusBadge
                                v-if="project.status"
                                :tone="statusTone(project.status)"
                            >
                                {{ project.status }}
                            </StatusBadge>
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Priority
                        </dt>
                        <dd>
                            <StatusBadge
                                v-if="project.priority"
                                :tone="priorityTone(project.priority)"
                            >
                                {{ project.priority }}
                            </StatusBadge>
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Owner
                        </dt>
                        <dd
                            v-if="project.owner"
                            class="flex items-center gap-2 text-text-secondary"
                        >
                            <span
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-accent-cyan/30 bg-accent-cyan/10 font-mono text-[10px] font-semibold text-accent-cyan"
                            >
                                {{ project.owner.initials }}
                            </span>
                            <span class="truncate">{{ project.owner.name }}</span>
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Last activity
                        </dt>
                        <dd class="text-text-secondary">
                            {{ project.last_activity_at ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </header>

            <!-- Tab nav -->
            <nav aria-label="Project tabs" class="flex flex-wrap gap-2">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.18em] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    :class="
                        activeTab === tab.key
                            ? 'border-accent-cyan/50 bg-accent-cyan/10 text-accent-cyan'
                            : 'border-border-subtle bg-background-panel-hover/40 text-text-muted hover:text-text-primary'
                    "
                    @click="activeTab = tab.key"
                >
                    <component
                        :is="tab.icon"
                        class="h-3.5 w-3.5"
                        aria-hidden="true"
                    />
                    {{ tab.label }}
                </button>
            </nav>

            <!-- Tab panels -->
            <section v-if="activeTab === 'overview'" aria-label="Overview" class="glass-card p-6 sm:p-8">
                <h3 class="text-sm font-semibold text-text-primary">
                    Project overview
                </h3>
                <p class="mt-2 text-sm text-text-secondary">
                    Repository count, deployment health, alert volume, and the
                    activity stream populate here as their owning phases ship.
                </p>
                <dl
                    class="mt-6 grid grid-cols-2 gap-4 text-sm md:grid-cols-3"
                >
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Environment
                        </dt>
                        <dd class="text-text-secondary">
                            {{ project.environment ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Health score
                        </dt>
                        <dd class="text-text-secondary">
                            {{
                                project.health_score === null
                                    ? '—'
                                    : `${project.health_score} / 100`
                            }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Slug
                        </dt>
                        <dd class="font-mono text-text-secondary">
                            {{ project.slug }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section
                v-else-if="activeTab === 'repositories'"
                aria-label="Repositories"
                class="flex flex-col gap-4"
            >
                <!-- Manual link form (project owner only) + Import-from-GitHub
                     CTA when the user has a connection wired up. -->
                <div v-if="canUpdate" class="flex flex-col gap-3">
                    <Link
                        v-if="hasGithubConnection"
                        :href="route('projects.repositories.import.index', project.slug)"
                        class="glass-card flex items-center justify-between gap-4 p-5 transition hover:border-accent-cyan/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    >
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-10 w-10 items-center justify-center rounded-xl border border-border-subtle bg-background-panel-hover"
                            >
                                <GitBranch
                                    class="h-5 w-5 text-accent-purple"
                                    aria-hidden="true"
                                />
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-text-primary">
                                    Import from GitHub
                                </p>
                                <p class="text-[11px] text-text-muted">
                                    Pick from the repositories your connected
                                    GitHub account can see.
                                </p>
                            </div>
                        </div>
                        <span
                            class="inline-flex items-center gap-1 text-xs font-semibold text-accent-cyan"
                        >
                            Browse
                            <ChevronLeft
                                class="h-3.5 w-3.5 rotate-180"
                                aria-hidden="true"
                            />
                        </span>
                    </Link>

                    <form
                        class="glass-card flex flex-col gap-3 p-5 sm:flex-row sm:items-end"
                        @submit.prevent="linkRepository"
                    >
                        <div class="flex-1">
                            <InputLabel
                                for="repository-input"
                                value="Link a GitHub repository manually"
                            />
                            <TextInput
                                id="repository-input"
                                v-model="linkForm.repository"
                                type="text"
                                class="mt-1 block w-full"
                                placeholder="https://github.com/owner/name  or  owner/name"
                                autocomplete="off"
                            />
                            <InputError
                                class="mt-2"
                                :message="linkForm.errors.repository"
                            />
                        </div>
                        <PrimaryButton :disabled="linkForm.processing">
                            <Plus class="me-1 h-4 w-4" aria-hidden="true" />
                            Link repository
                        </PrimaryButton>
                    </form>
                </div>

                <!-- Linked repositories list -->
                <div
                    v-if="repositories.length > 0"
                    aria-label="Linked repositories"
                    class="glass-card flex flex-col divide-y divide-border-subtle"
                >
                    <div
                        v-for="repo in repositories"
                        :key="repo.id"
                        class="flex flex-col gap-3 p-5 first:rounded-t-2xl last:rounded-b-2xl md:grid md:grid-cols-[minmax(0,3fr)_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-center md:gap-4"
                    >
                        <Link
                            :href="route('repositories.show', repo.full_name)"
                            class="group flex min-w-0 items-center gap-2 transition focus:outline-none focus-visible:text-accent-cyan"
                        >
                            <GitBranch
                                class="h-4 w-4 shrink-0 text-accent-purple"
                                aria-hidden="true"
                            />
                            <p
                                class="truncate font-mono text-sm text-text-primary group-hover:text-accent-cyan"
                            >
                                {{ repo.full_name }}
                            </p>
                        </Link>
                        <div class="flex items-center gap-3 text-[11px] text-text-muted">
                            <span class="inline-flex items-center gap-1 font-mono tabular-nums">
                                <MessageSquare class="h-3 w-3" aria-hidden="true" />
                                {{ repo.open_issues_count }}
                            </span>
                            <span class="inline-flex items-center gap-1 font-mono tabular-nums">
                                <GitPullRequest class="h-3 w-3" aria-hidden="true" />
                                {{ repo.open_prs_count }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span
                                v-if="repo.language"
                                class="rounded-full border border-border-subtle px-2 py-0.5 font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                            >
                                {{ repo.language }}
                            </span>
                            <StatusBadge
                                v-if="repo.sync_status"
                                :tone="syncStatusTone(repo.sync_status)"
                            >
                                {{ repo.sync_status }}
                            </StatusBadge>
                        </div>
                        <div class="flex items-center gap-1">
                            <a
                                :href="repo.html_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-border-subtle bg-background-panel-hover text-text-muted transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                aria-label="Open on GitHub"
                            >
                                <ExternalLink class="h-3.5 w-3.5" aria-hidden="true" />
                            </a>
                            <button
                                v-if="canUpdate"
                                type="button"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-status-danger/30 bg-status-danger/10 text-status-danger transition hover:bg-status-danger/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-status-danger/60"
                                :aria-label="`Unlink ${repo.full_name}`"
                                @click="confirmUnlink(repo)"
                            >
                                <X class="h-3.5 w-3.5" aria-hidden="true" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Empty state -->
                <div
                    v-else
                    class="glass-card flex flex-col items-center gap-3 p-8 text-center"
                >
                    <span
                        class="flex h-10 w-10 items-center justify-center rounded-full border border-border-subtle bg-slate-950/40"
                    >
                        <GitBranch
                            class="h-4 w-4 text-text-muted"
                            aria-hidden="true"
                        />
                    </span>
                    <p class="text-sm text-text-secondary">
                        No repositories linked yet.
                    </p>
                    <p
                        v-if="canUpdate"
                        class="max-w-sm text-xs text-text-muted"
                    >
                        Paste a GitHub URL or
                        <code class="font-mono">owner/name</code> above to link
                        one. Real metadata syncs in once phase 2 ships.
                    </p>
                    <p v-else class="max-w-sm text-xs text-text-muted">
                        Only the project owner can link repositories.
                    </p>
                </div>
            </section>

            <section v-else-if="activeTab === 'settings'" aria-label="Settings" class="glass-card p-6 sm:p-8">
                <h3 class="text-sm font-semibold text-text-primary">
                    Settings
                </h3>
                <p class="mt-2 text-sm text-text-secondary">
                    Use the
                    <strong class="text-text-primary">Edit</strong> button at
                    the top of the page to rename, restyle, or change the
                    status / priority of this project. Deleting a project
                    cannot be undone.
                </p>
            </section>

            <!-- Activity panel — events from this project's repos. -->
            <section
                v-else-if="activeTab === 'activity'"
                aria-label="Activity"
                class="glass-card p-6 sm:p-8"
            >
                <header class="mb-4 flex items-center justify-between gap-3">
                    <div class="flex flex-col gap-1">
                        <h3 class="text-sm font-semibold text-text-primary">
                            Project activity
                        </h3>
                        <p class="text-xs text-text-muted">
                            Up to 20 recent events from this project's
                            repositories.
                        </p>
                    </div>
                    <Link
                        :href="route('activity.index')"
                        class="inline-flex items-center gap-1.5 rounded-md border border-border-subtle bg-background-panel-hover px-2.5 py-1.5 text-xs font-semibold text-text-secondary transition hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    >
                        Browse all activity
                        <ArrowRight class="h-3.5 w-3.5" aria-hidden="true" />
                    </Link>
                </header>

                <ActivityFeed
                    v-if="projectActivity.length > 0"
                    :events="projectActivity"
                />
                <div
                    v-else
                    class="flex flex-col items-center justify-center gap-3 px-6 py-12 text-center"
                >
                    <span
                        class="flex h-12 w-12 items-center justify-center rounded-full border border-border-subtle bg-slate-950/60"
                    >
                        <Activity
                            class="h-5 w-5 text-text-muted"
                            aria-hidden="true"
                        />
                    </span>
                    <p class="text-sm font-medium text-text-secondary">
                        No events yet
                    </p>
                    <p class="max-w-sm text-xs text-text-muted">
                        Events land here once a webhook from one of this
                        project's repositories fires.
                    </p>
                </div>
            </section>

            <!-- Deployments panel — workflow runs from this project's repos. -->
            <section
                v-else-if="activeTab === 'deployments'"
                aria-label="Deployments"
                class="glass-card p-6 sm:p-8"
            >
                <header class="mb-4 flex items-center justify-between gap-3">
                    <div class="flex flex-col gap-1">
                        <h3 class="text-sm font-semibold text-text-primary">
                            Project deployments
                        </h3>
                        <p class="text-xs text-text-muted">
                            Recent GitHub Actions workflow runs across this
                            project's repositories.
                        </p>
                    </div>
                    <Link
                        :href="
                            route('deployments.index', {
                                project_id: project.id,
                            })
                        "
                        class="inline-flex items-center gap-1.5 rounded-md border border-border-subtle bg-background-panel-hover px-2.5 py-1.5 text-xs font-semibold text-text-secondary transition hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    >
                        View all
                        <ArrowRight class="h-3.5 w-3.5" aria-hidden="true" />
                    </Link>
                </header>

                <ul
                    v-if="projectDeployments.length > 0"
                    class="divide-y divide-border-subtle"
                >
                    <li
                        v-for="row in projectDeployments.slice(0, 10)"
                        :key="row.id"
                        class="flex items-center gap-4 py-3"
                    >
                        <span
                            class="h-2.5 w-2.5 shrink-0 rounded-full"
                            :class="runStatusDotClass(row)"
                            aria-hidden="true"
                        />
                        <Workflow
                            class="h-4 w-4 shrink-0 text-text-muted"
                            aria-hidden="true"
                        />
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <a
                                :href="row.html_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="truncate text-sm font-semibold text-text-primary transition hover:text-accent-cyan"
                            >
                                <span class="font-mono text-text-muted">#{{ row.run_number }}</span>
                                {{ row.name }}
                            </a>
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
                                <span class="font-mono">{{ row.event }}</span>
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
                        <div class="flex shrink-0 items-center gap-2">
                            <StatusBadge
                                v-if="row.conclusion"
                                :tone="conclusionTone(row.conclusion)"
                            >
                                {{ conclusionLabel(row.conclusion) }}
                            </StatusBadge>
                            <StatusBadge
                                v-else
                                :tone="runStatusTone(row.status)"
                            >
                                {{ row.status }}
                            </StatusBadge>
                            <a
                                :href="row.html_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-text-muted transition hover:text-accent-cyan"
                                :aria-label="`Open run #${row.run_number} on GitHub`"
                            >
                                <ExternalLink
                                    class="h-4 w-4"
                                    aria-hidden="true"
                                />
                            </a>
                        </div>
                    </li>
                </ul>

                <div
                    v-else
                    class="flex flex-col items-center justify-center gap-3 px-6 py-12 text-center"
                >
                    <span
                        class="flex h-12 w-12 items-center justify-center rounded-full border border-border-subtle bg-slate-950/60"
                    >
                        <Rocket
                            class="h-5 w-5 text-text-muted"
                            aria-hidden="true"
                        />
                    </span>
                    <p class="text-sm font-medium text-text-secondary">
                        No workflow runs yet
                    </p>
                    <p class="max-w-sm text-xs text-text-muted">
                        Trigger a GitHub Action on one of this project's
                        repositories — runs appear here in real-time.
                    </p>
                </div>
            </section>

            <!-- Monitoring panel — website monitors under this project. -->
            <section
                v-else-if="activeTab === 'monitoring'"
                aria-label="Monitoring"
                class="glass-card p-6 sm:p-8"
            >
                <header class="mb-4 flex items-center justify-between gap-3">
                    <div class="flex flex-col gap-1">
                        <h3 class="text-sm font-semibold text-text-primary">
                            Project monitors
                        </h3>
                        <p class="text-xs text-text-muted">
                            Website health checks under this project. Up to
                            20 shown — full list at
                            <span class="font-mono">/monitoring/websites</span>.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link
                            v-if="canUpdate"
                            :href="
                                route('monitoring.websites.create', {
                                    project_id: project.id,
                                })
                            "
                            class="inline-flex items-center gap-1.5 rounded-md border border-accent-cyan/40 bg-accent-cyan/15 px-2.5 py-1.5 text-xs font-semibold text-accent-cyan transition hover:border-accent-cyan/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        >
                            <Plus class="h-3.5 w-3.5" aria-hidden="true" />
                            Add monitor
                        </Link>
                        <Link
                            :href="route('monitoring.websites.index')"
                            class="inline-flex items-center gap-1.5 rounded-md border border-border-subtle bg-background-panel-hover px-2.5 py-1.5 text-xs font-semibold text-text-secondary transition hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        >
                            Browse all
                            <ArrowRight class="h-3.5 w-3.5" aria-hidden="true" />
                        </Link>
                    </div>
                </header>

                <ul
                    v-if="projectMonitors.length > 0"
                    class="divide-y divide-border-subtle"
                >
                    <li
                        v-for="monitor in projectMonitors"
                        :key="monitor.id"
                        class="flex items-center gap-4 py-3"
                    >
                        <Globe
                            class="h-4 w-4 shrink-0 text-text-muted"
                            aria-hidden="true"
                        />
                        <Link
                            :href="route('monitoring.websites.show', monitor.id)"
                            class="flex min-w-0 flex-1 flex-col gap-1 transition hover:text-accent-cyan"
                        >
                            <span class="truncate text-sm font-semibold text-text-primary">
                                {{ monitor.name }}
                            </span>
                            <p
                                class="flex flex-wrap items-center gap-x-2 truncate text-xs text-text-muted"
                            >
                                <span class="font-mono text-text-secondary">{{ monitor.method }}</span>
                                <span class="truncate font-mono">{{ monitor.url }}</span>
                                <span v-if="monitor.last_checked_at">
                                    · Last check {{ monitor.last_checked_at }}
                                </span>
                            </p>
                        </Link>
                        <StatusBadge :tone="monitorStatusTone(monitor.status)">
                            {{ monitor.status }}
                        </StatusBadge>
                    </li>
                </ul>

                <div
                    v-else
                    class="flex flex-col items-center justify-center gap-3 px-6 py-12 text-center"
                >
                    <span
                        class="flex h-12 w-12 items-center justify-center rounded-full border border-border-subtle bg-slate-950/60"
                    >
                        <Globe
                            class="h-5 w-5 text-text-muted"
                            aria-hidden="true"
                        />
                    </span>
                    <p class="text-sm font-medium text-text-secondary">
                        No monitors yet
                    </p>
                    <p class="max-w-sm text-xs text-text-muted">
                        Add a website URL to start tracking response time
                        and uptime under this project.
                    </p>
                </div>
            </section>

            <!-- Phase-pending placeholder for hosts / monitoring tabs. -->
            <section
                v-else
                :aria-label="`${activeTab} (coming soon)`"
                class="glass-card flex flex-col items-center gap-3 p-10 text-center"
            >
                <h3 class="text-sm font-semibold text-text-primary">
                    Coming up later
                </h3>
                <p class="max-w-sm text-sm text-text-muted">
                    The
                    <strong class="text-text-secondary">{{
                        tabs.find((t) => t.key === activeTab)?.label
                    }}</strong>
                    tab populates with real data when
                    <strong class="text-text-secondary">{{
                        tabs.find((t) => t.key === activeTab)?.pendingPhase
                    }}</strong>
                    ships.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
