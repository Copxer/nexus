<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import {
    CircleDot,
    ExternalLink,
    GitPullRequest,
    Inbox,
    MessageSquare,
    Search,
} from 'lucide-vue-next';
import { reactive, watch } from 'vue';

interface RepositoryRef {
    id: number;
    full_name: string;
    name: string;
}

interface WorkItem {
    id: string;
    kind: 'issue' | 'pull_request';
    number: number;
    title: string;
    state: 'open' | 'closed' | 'merged' | string | null;
    author_login: string | null;
    comments_count: number;
    draft?: boolean;
    updated_at_github: string | null;
    html_url: string | null;
    repository: RepositoryRef | null;
}

interface Filters {
    kind: 'issues' | 'pulls' | 'all';
    state: 'open' | 'closed' | 'merged' | 'all';
    repository_id: number | null;
    q: string | null;
}

const props = defineProps<{
    items: WorkItem[];
    repositories: { id: number; full_name: string }[];
    filters: Filters;
}>();

const local = reactive<Filters>({
    kind: props.filters.kind,
    state: props.filters.state,
    repository_id: props.filters.repository_id,
    q: props.filters.q,
});

const itemStateTone = (item: WorkItem) => {
    if (item.kind === 'pull_request') {
        return (
            (
                ({
                    open: 'info',
                    merged: 'success',
                    closed: 'muted',
                }) as const
            )[item.state ?? ''] ?? 'muted'
        );
    }
    return item.state === 'open' ? 'info' : 'muted';
};

// Push filter changes to the server. `preserveState: false` re-renders
// the page with fresh `items`; `replace: true` keeps the back button
// from accumulating filter steps.
const applyFilters = () => {
    router.get(
        route('work-items.index'),
        {
            kind: local.kind,
            state: local.state,
            repository_id: local.repository_id ?? undefined,
            q: local.q || undefined,
        },
        {
            preserveState: false,
            preserveScroll: true,
            replace: true,
        },
    );
};

// Debounced free-text search: only fire when the user pauses typing,
// otherwise every keystroke would re-render. The other filters (kind,
// state, repo) fire immediately on change.
let searchTimer: ReturnType<typeof setTimeout> | null = null;
watch(
    () => local.q,
    () => {
        if (searchTimer !== null) clearTimeout(searchTimer);
        searchTimer = setTimeout(applyFilters, 350);
    },
);
</script>

<template>
    <Head title="Work Items" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <span
                    class="text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan"
                >
                    Phase 2
                </span>
                <h1 class="text-lg font-semibold text-text-primary">
                    Work Items
                </h1>
            </div>
        </template>

        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <!-- Filters -->
            <section
                aria-label="Filters"
                class="glass-card flex flex-col gap-4 p-5 sm:flex-row sm:flex-wrap sm:items-end"
            >
                <!-- Kind tabs -->
                <div class="flex flex-col gap-1">
                    <label
                        class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                    >
                        Kind
                    </label>
                    <div class="flex gap-1.5">
                        <button
                            v-for="opt in [
                                { value: 'all', label: 'All' },
                                { value: 'issues', label: 'Issues' },
                                { value: 'pulls', label: 'Pull Requests' },
                            ]"
                            :key="opt.value"
                            type="button"
                            :class="[
                                'inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.2em] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60',
                                local.kind === opt.value
                                    ? 'border-accent-cyan/60 bg-accent-cyan/15 text-accent-cyan'
                                    : 'border-border-subtle bg-background-panel-hover text-text-secondary hover:text-text-primary',
                            ]"
                            @click="
                                local.kind = opt.value as Filters['kind'];
                                applyFilters();
                            "
                        >
                            {{ opt.label }}
                        </button>
                    </div>
                </div>

                <!-- State -->
                <div class="flex flex-col gap-1">
                    <label
                        for="state-filter"
                        class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                    >
                        State
                    </label>
                    <select
                        id="state-filter"
                        v-model="local.state"
                        class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        @change="applyFilters"
                    >
                        <option value="open">Open</option>
                        <option value="closed">Closed</option>
                        <option value="merged">Merged</option>
                        <option value="all">All</option>
                    </select>
                </div>

                <!-- Repository -->
                <div class="flex flex-col gap-1">
                    <label
                        for="repo-filter"
                        class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                    >
                        Repository
                    </label>
                    <select
                        id="repo-filter"
                        v-model="local.repository_id"
                        class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        @change="applyFilters"
                    >
                        <option :value="null">All repositories</option>
                        <option
                            v-for="repo in repositories"
                            :key="repo.id"
                            :value="repo.id"
                        >
                            {{ repo.full_name }}
                        </option>
                    </select>
                </div>

                <!-- Search -->
                <div class="flex flex-1 flex-col gap-1 sm:min-w-[220px]">
                    <label
                        for="search-input"
                        class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                    >
                        Search
                    </label>
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted"
                            aria-hidden="true"
                        />
                        <input
                            id="search-input"
                            v-model="local.q"
                            type="text"
                            placeholder="Title or #number"
                            class="w-full rounded-lg border border-border-subtle bg-background-panel-hover py-2 pl-10 pr-3 text-sm text-text-primary placeholder:text-text-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        />
                    </div>
                </div>
            </section>

            <!-- Item list -->
            <section
                aria-label="Items"
                class="glass-card p-0"
            >
                <ul
                    v-if="items.length > 0"
                    class="divide-y divide-border-subtle"
                >
                    <li
                        v-for="item in items"
                        :key="item.id"
                        class="flex flex-col gap-2 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <CircleDot
                                v-if="item.kind === 'issue'"
                                class="mt-1 h-4 w-4 shrink-0"
                                :class="
                                    item.state === 'open'
                                        ? 'text-accent-cyan'
                                        : 'text-text-muted'
                                "
                                aria-hidden="true"
                            />
                            <GitPullRequest
                                v-else
                                class="mt-1 h-4 w-4 shrink-0"
                                :class="{
                                    'text-accent-cyan': item.state === 'open',
                                    'text-status-success':
                                        item.state === 'merged',
                                    'text-text-muted':
                                        item.state === 'closed' || !item.state,
                                }"
                                aria-hidden="true"
                            />
                            <div class="flex min-w-0 flex-col gap-1">
                                <a
                                    v-if="item.html_url"
                                    :href="item.html_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="truncate text-sm font-semibold text-text-primary transition hover:text-accent-cyan"
                                >
                                    <span class="font-mono text-text-muted">#{{ item.number }}</span>
                                    {{ item.title }}
                                </a>
                                <span
                                    v-else
                                    class="truncate text-sm font-semibold text-text-primary"
                                >
                                    <span class="font-mono text-text-muted">#{{ item.number }}</span>
                                    {{ item.title }}
                                </span>
                                <p class="text-xs text-text-muted">
                                    <span
                                        v-if="item.repository"
                                        class="font-mono text-text-secondary"
                                    >
                                        {{ item.repository.full_name }}
                                    </span>
                                    <template v-if="item.author_login">
                                        ·
                                        <span class="font-mono text-text-secondary">@{{ item.author_login }}</span>
                                    </template>
                                    · Updated {{ item.updated_at_github ?? '—' }}
                                </p>
                            </div>
                        </div>
                        <div
                            class="flex flex-shrink-0 items-center gap-3 text-xs text-text-muted"
                        >
                            <StatusBadge
                                v-if="item.kind === 'pull_request' && item.draft"
                                tone="muted"
                            >
                                draft
                            </StatusBadge>
                            <StatusBadge :tone="itemStateTone(item)">
                                {{ item.state }}
                            </StatusBadge>
                            <span class="inline-flex items-center gap-1">
                                <MessageSquare
                                    class="h-3.5 w-3.5"
                                    aria-hidden="true"
                                />
                                {{ item.comments_count }}
                            </span>
                            <a
                                v-if="item.html_url"
                                :href="item.html_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-text-muted transition hover:text-accent-cyan"
                                :aria-label="`Open ${item.kind === 'pull_request' ? 'pull request' : 'issue'} #${item.number} on GitHub`"
                            >
                                <ExternalLink class="h-4 w-4" aria-hidden="true" />
                            </a>
                        </div>
                    </li>
                </ul>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-6 py-16 text-center"
                >
                    <Inbox
                        class="h-10 w-10 text-text-muted"
                        aria-hidden="true"
                    />
                    <p class="text-sm font-semibold text-text-primary">
                        No work items match your filters.
                    </p>
                    <p class="text-xs text-text-muted">
                        Import a repository on a project to start syncing
                        issues and pull requests, or widen the state filter.
                    </p>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
