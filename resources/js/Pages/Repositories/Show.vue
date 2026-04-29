<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import { projectIcon } from '@/lib/projectIcons';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ExternalLink,
    FolderKanban,
    GitBranch,
    GitFork,
    GitPullRequest,
    MessageSquare,
    Star,
    Trash2,
} from 'lucide-vue-next';

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
    project: {
        id: number;
        slug: string;
        name: string;
        color: string | null;
        icon: string | null;
    } | null;
}

const props = defineProps<{
    repository: RepositoryShape;
    canDelete: boolean;
}>();

const syncStatusTone = (status: string | null) =>
    (
        ({
            pending: 'muted',
            syncing: 'info',
            synced: 'success',
            failed: 'danger',
        }) as const
    )[status ?? ''] ?? 'muted';

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

            <!-- Counts -->
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

            <!-- Sync timestamps -->
            <section class="glass-card p-6 sm:p-8">
                <h3 class="text-sm font-semibold text-text-primary">
                    Sync activity
                </h3>
                <p class="mt-2 text-sm text-text-secondary">
                    GitHub auto-sync arrives with phase 2. Until then these
                    timestamps reflect manual links and the seed data.
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
        </div>
    </AppLayout>
</template>
