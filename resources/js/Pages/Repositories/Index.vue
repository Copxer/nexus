<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import { projectIcon } from '@/lib/projectIcons';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ExternalLink,
    FolderKanban,
    GitBranch,
    GitPullRequest,
    MessageSquare,
} from 'lucide-vue-next';
import { computed } from 'vue';

interface RepositoryRow {
    id: number;
    owner: string;
    name: string;
    full_name: string;
    html_url: string;
    default_branch: string;
    visibility: string;
    language: string | null;
    description: string | null;
    open_issues_count: number;
    open_prs_count: number;
    last_pushed_at: string | null;
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
    repositories: RepositoryRow[];
}>();

const hasRepositories = computed(() => props.repositories.length > 0);

const syncStatusTone = (status: string | null) =>
    (
        ({
            pending: 'muted',
            syncing: 'info',
            synced: 'success',
            failed: 'danger',
        }) as const
    )[status ?? ''] ?? 'muted';

// Project chip uses the same accent vocabulary as KpiCard / Project cards.
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
</script>

<template>
    <Head title="Repositories" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <span
                    class="text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan"
                >
                    Phase 1
                </span>
                <h1 class="text-lg font-semibold text-text-primary">
                    Repositories
                </h1>
            </div>
        </template>

        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header class="flex items-center justify-between gap-3">
                <p class="text-sm text-text-secondary">
                    {{ repositories.length }}
                    {{ repositories.length === 1 ? 'repository' : 'repositories' }}
                </p>
                <p class="font-mono text-[11px] text-text-muted">
                    Manual links · GitHub auto-sync arrives with phase 2
                </p>
            </header>

            <!-- List -->
            <section
                v-if="hasRepositories"
                aria-label="Repositories"
                class="glass-card flex flex-col divide-y divide-border-subtle"
            >
                <Link
                    v-for="repo in repositories"
                    :key="repo.id"
                    :href="route('repositories.show', repo.full_name)"
                    class="group flex flex-col gap-3 p-5 transition first:rounded-t-2xl last:rounded-b-2xl hover:bg-background-panel-hover/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60 md:grid md:grid-cols-[minmax(0,3fr)_minmax(0,2fr)_auto_auto_auto] md:items-center md:gap-4"
                >
                    <!-- Repo identity -->
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <GitBranch
                                class="h-4 w-4 shrink-0 text-accent-purple"
                                aria-hidden="true"
                            />
                            <p
                                class="truncate font-mono text-sm text-text-primary"
                            >
                                {{ repo.full_name }}
                            </p>
                        </div>
                        <p
                            v-if="repo.description"
                            class="mt-1 line-clamp-1 text-[11px] text-text-muted md:line-clamp-1"
                        >
                            {{ repo.description }}
                        </p>
                    </div>

                    <!-- Linked project chip -->
                    <div
                        v-if="repo.project"
                        class="flex items-center gap-2 text-xs text-text-secondary"
                    >
                        <component
                            :is="projectIcon(repo.project.icon) ?? FolderKanban"
                            class="h-3.5 w-3.5 shrink-0"
                            :class="projectAccentClass(repo.project.color)"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{ repo.project.name }}</span>
                    </div>
                    <div v-else class="text-xs text-text-muted">—</div>

                    <!-- Counts -->
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

                    <!-- Language + sync status -->
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

                    <!-- Pushed-at + external link icon -->
                    <div
                        class="flex items-center gap-2 text-[11px] text-text-muted"
                    >
                        <span v-if="repo.last_pushed_at" class="shrink-0">
                            {{ repo.last_pushed_at }}
                        </span>
                        <ExternalLink
                            class="h-3.5 w-3.5 shrink-0 opacity-0 transition group-hover:opacity-100"
                            aria-hidden="true"
                        />
                    </div>
                </Link>
            </section>

            <!-- Empty state -->
            <section
                v-else
                aria-label="No repositories yet"
                class="glass-card flex flex-col items-center gap-3 p-10 text-center"
            >
                <span
                    class="flex h-12 w-12 items-center justify-center rounded-full border border-border-subtle bg-slate-950/40"
                >
                    <GitBranch
                        class="h-5 w-5 text-text-muted"
                        aria-hidden="true"
                    />
                </span>
                <h2 class="text-base font-semibold text-text-primary">
                    No repositories linked yet
                </h2>
                <p class="max-w-sm text-sm text-text-muted">
                    Open a project and link a GitHub repository from its
                    Repositories tab. Real GitHub metadata will sync in once
                    the integration ships in phase 2.
                </p>
                <Link
                    :href="route('projects.index')"
                    class="mt-2 inline-flex items-center gap-2 rounded-lg border border-accent-cyan/40 bg-accent-cyan/15 px-3 py-2 text-sm font-semibold text-accent-cyan transition hover:border-accent-cyan/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                >
                    Browse projects
                </Link>
            </section>
        </div>
    </AppLayout>
</template>
