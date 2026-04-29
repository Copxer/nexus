<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ExternalLink,
    GitBranch,
    Github,
    Lock,
    Plus,
    Search,
    Star,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface ProjectShape {
    id: number;
    slug: string;
    name: string;
}

interface ImportableRepo {
    id: number | null;
    full_name: string;
    description: string | null;
    language: string | null;
    private: boolean;
    stars_count: number;
    forks_count: number;
    pushed_at: string | null;
    html_url: string | null;
    is_already_linked: boolean;
    linked_to_this_project: boolean;
}

const props = defineProps<{
    project: ProjectShape;
    repositories: ImportableRepo[];
}>();

const query = ref('');
const importing = ref<string | null>(null);

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (q.length === 0) return props.repositories;
    return props.repositories.filter((repo) =>
        repo.full_name.toLowerCase().includes(q)
        || (repo.description ?? '').toLowerCase().includes(q)
        || (repo.language ?? '').toLowerCase().includes(q),
    );
});

const importRepository = (repo: ImportableRepo) => {
    importing.value = repo.full_name;
    router.post(
        route('projects.repositories.import.store', props.project.slug),
        { full_name: repo.full_name },
        {
            onFinish: () => {
                importing.value = null;
            },
        },
    );
};

const formatPushed = (iso: string | null): string => {
    if (!iso) return '';
    try {
        const date = new Date(iso);
        const days = Math.floor(
            (Date.now() - date.getTime()) / (1000 * 60 * 60 * 24),
        );
        if (days < 1) return 'today';
        if (days === 1) return 'yesterday';
        if (days < 30) return `${days}d ago`;
        const months = Math.floor(days / 30);
        return months === 1 ? '1 mo ago' : `${months} mo ago`;
    } catch {
        return '';
    }
};
</script>

<template>
    <Head title="Import from GitHub" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <Link
                    :href="route('projects.show', project.slug)"
                    class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan transition hover:text-accent-cyan/80"
                >
                    <ChevronLeft class="h-3 w-3" aria-hidden="true" />
                    {{ project.name }}
                </Link>
                <h1 class="text-lg font-semibold text-text-primary">
                    Import from GitHub
                </h1>
            </div>
        </template>

        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header
                class="glass-card flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-border-subtle bg-background-panel-hover"
                    >
                        <Github
                            class="h-5 w-5 text-text-primary"
                            aria-hidden="true"
                        />
                    </span>
                    <div>
                        <p class="text-sm text-text-secondary">
                            <span class="font-mono">{{ repositories.length }}</span>
                            repositories visible to your GitHub account
                        </p>
                        <p class="font-mono text-[11px] text-text-muted">
                            Sorted by recently pushed · already-linked rows
                            disabled
                        </p>
                    </div>
                </div>
                <div class="relative w-full sm:w-72">
                    <Search
                        class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted"
                        aria-hidden="true"
                    />
                    <input
                        v-model="query"
                        type="search"
                        placeholder="Filter by name, language…"
                        aria-label="Filter repositories"
                        class="w-full rounded-lg border border-border-subtle bg-slate-950/60 ps-9 pe-3 py-2 text-sm text-text-primary placeholder:text-text-muted shadow-inner shadow-black/20 transition focus:border-accent-cyan focus:ring-2 focus:ring-accent-cyan/40"
                    />
                </div>
            </header>

            <!-- List -->
            <section
                v-if="filtered.length > 0"
                aria-label="GitHub repositories"
                class="glass-card flex flex-col divide-y divide-border-subtle"
            >
                <article
                    v-for="repo in filtered"
                    :key="repo.id ?? repo.full_name"
                    class="flex flex-col gap-3 p-5 transition first:rounded-t-2xl last:rounded-b-2xl md:grid md:grid-cols-[minmax(0,3fr)_minmax(0,1fr)_auto_auto] md:items-center md:gap-4"
                >
                    <!-- Identity -->
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
                            <Lock
                                v-if="repo.private"
                                class="h-3 w-3 shrink-0 text-text-muted"
                                :title="`Private`"
                                aria-label="Private repository"
                            />
                        </div>
                        <p
                            v-if="repo.description"
                            class="mt-1 line-clamp-1 text-[11px] text-text-muted"
                        >
                            {{ repo.description }}
                        </p>
                    </div>

                    <!-- Language -->
                    <div class="text-xs text-text-muted">
                        {{ repo.language ?? '—' }}
                    </div>

                    <!-- Stars + pushed -->
                    <div
                        class="flex items-center gap-3 text-[11px] text-text-muted"
                    >
                        <span
                            class="inline-flex items-center gap-1 font-mono tabular-nums"
                        >
                            <Star class="h-3 w-3" aria-hidden="true" />
                            {{ repo.stars_count }}
                        </span>
                        <span
                            v-if="repo.pushed_at"
                            class="font-mono"
                        >
                            {{ formatPushed(repo.pushed_at) }}
                        </span>
                        <a
                            v-if="repo.html_url"
                            :href="repo.html_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-text-muted transition hover:text-accent-cyan"
                            :aria-label="`Open ${repo.full_name} on GitHub`"
                        >
                            <ExternalLink
                                class="h-3.5 w-3.5"
                                aria-hidden="true"
                            />
                        </a>
                    </div>

                    <!-- Action -->
                    <div class="flex items-center justify-end">
                        <StatusBadge
                            v-if="repo.linked_to_this_project"
                            tone="info"
                        >
                            Already in this project
                        </StatusBadge>
                        <StatusBadge
                            v-else-if="repo.is_already_linked"
                            tone="muted"
                        >
                            Linked elsewhere
                        </StatusBadge>
                        <button
                            v-else
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-accent-cyan/40 bg-accent-cyan/15 px-3 py-1.5 text-xs font-semibold text-accent-cyan transition hover:border-accent-cyan/60 disabled:cursor-not-allowed disabled:opacity-60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                            :disabled="importing !== null"
                            @click="importRepository(repo)"
                        >
                            <Plus class="h-3.5 w-3.5" aria-hidden="true" />
                            <span v-if="importing === repo.full_name">
                                Importing…
                            </span>
                            <span v-else>Import</span>
                        </button>
                    </div>
                </article>
            </section>

            <!-- Empty state -->
            <section
                v-else-if="repositories.length === 0"
                aria-label="No repositories"
                class="glass-card flex flex-col items-center gap-3 p-10 text-center"
            >
                <span
                    class="flex h-12 w-12 items-center justify-center rounded-full border border-border-subtle bg-slate-950/40"
                >
                    <Github
                        class="h-5 w-5 text-text-muted"
                        aria-hidden="true"
                    />
                </span>
                <h2 class="text-base font-semibold text-text-primary">
                    No repositories visible
                </h2>
                <p class="max-w-sm text-sm text-text-muted">
                    Your GitHub account doesn't see any repositories with the
                    granted scopes. Connect a different account or grant
                    additional access from GitHub.
                </p>
            </section>

            <!-- Filter empty state -->
            <section
                v-else
                aria-label="No matches"
                class="rounded-lg border border-dashed border-border-subtle bg-background-panel-hover/30 p-6 text-center text-sm text-text-muted"
            >
                No repositories match
                <code class="font-mono text-text-secondary">{{ query }}</code>.
            </section>
        </div>
    </AppLayout>
</template>
