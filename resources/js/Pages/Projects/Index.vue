<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import { projectIcon } from '@/lib/projectIcons';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { FolderKanban, Plus } from 'lucide-vue-next';
import { computed } from 'vue';

interface ProjectCard {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    status: string | null;
    priority: string | null;
    environment: string | null;
    color: string | null;
    icon: string | null;
    last_activity_at: string | null;
    owner: { id: number; name: string; initials: string } | null;
}

const props = defineProps<{
    projects: ProjectCard[];
}>();

// Map status / priority strings to badge tones the dashboard uses.
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

// Map the picked color token to the icon glow class used elsewhere
// (mirrors the KpiCard accent-glow approach).
const iconAccentClass = (color: string | null) =>
    (
        ({
            cyan: 'text-accent-cyan shadow-[0_0_20px_rgba(34,211,238,0.45)]',
            blue: 'text-accent-blue shadow-[0_0_20px_rgba(56,189,248,0.45)]',
            purple: 'text-accent-purple shadow-[0_0_20px_rgba(139,92,246,0.45)]',
            magenta: 'text-accent-magenta shadow-[0_0_20px_rgba(217,70,239,0.45)]',
            success: 'text-status-success shadow-glow-success',
            warning: 'text-status-warning',
        }) as const
    )[color ?? ''] ?? 'text-text-muted';

// Resolve icon-name string → Lucide component, with a fallback to FolderKanban.
const resolveIcon = (name: string | null) => projectIcon(name) ?? FolderKanban;

const hasProjects = computed(() => props.projects.length > 0);
</script>

<template>
    <Head title="Projects" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <span
                    class="text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan"
                >
                    Phase 1
                </span>
                <h1 class="text-lg font-semibold text-text-primary">Projects</h1>
            </div>
        </template>

        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <!-- Header row: count + create CTA -->
            <header class="flex items-center justify-between gap-3">
                <p class="text-sm text-text-secondary">
                    {{ projects.length }}
                    {{ projects.length === 1 ? 'project' : 'projects' }}
                </p>
                <Link
                    :href="route('projects.create')"
                    class="inline-flex items-center gap-2 rounded-lg border border-accent-cyan/40 bg-accent-cyan/15 px-3 py-2 text-sm font-semibold text-accent-cyan transition hover:border-accent-cyan/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                >
                    <Plus class="h-4 w-4" aria-hidden="true" />
                    Create project
                </Link>
            </header>

            <!-- Card grid -->
            <section
                v-if="hasProjects"
                aria-label="Projects"
                class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3"
            >
                <Link
                    v-for="project in projects"
                    :key="project.id"
                    :href="route('projects.show', project.slug)"
                    class="glass-card group flex flex-col gap-4 p-5 transition hover:border-accent-cyan/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                >
                    <div class="flex items-start justify-between gap-3">
                        <span
                            class="flex h-10 w-10 items-center justify-center rounded-xl border border-border-subtle bg-background-panel-hover transition group-hover:border-accent-cyan/30"
                        >
                            <component
                                :is="resolveIcon(project.icon)"
                                class="h-5 w-5 transition"
                                :class="iconAccentClass(project.color)"
                                aria-hidden="true"
                            />
                        </span>
                        <div class="flex flex-col items-end gap-1.5">
                            <StatusBadge
                                v-if="project.status"
                                :tone="statusTone(project.status)"
                            >
                                {{ project.status }}
                            </StatusBadge>
                            <StatusBadge
                                v-if="project.priority"
                                :tone="priorityTone(project.priority)"
                            >
                                {{ project.priority }}
                            </StatusBadge>
                        </div>
                    </div>

                    <div class="flex min-w-0 flex-col gap-1.5">
                        <h2
                            class="truncate text-base font-semibold text-text-primary"
                        >
                            {{ project.name }}
                        </h2>
                        <p
                            v-if="project.description"
                            class="line-clamp-2 text-sm text-text-secondary"
                        >
                            {{ project.description }}
                        </p>
                        <p
                            v-if="project.environment"
                            class="font-mono text-[11px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            {{ project.environment }}
                        </p>
                    </div>

                    <footer
                        class="mt-auto flex items-center justify-between gap-3 text-xs text-text-muted"
                    >
                        <div
                            v-if="project.owner"
                            class="flex items-center gap-2"
                        >
                            <span
                                class="flex h-6 w-6 items-center justify-center rounded-full border border-accent-cyan/30 bg-accent-cyan/10 font-mono text-[10px] font-semibold text-accent-cyan"
                            >
                                {{ project.owner.initials }}
                            </span>
                            <span class="truncate">{{ project.owner.name }}</span>
                        </div>
                        <span v-if="project.last_activity_at" class="shrink-0">
                            {{ project.last_activity_at }}
                        </span>
                    </footer>
                </Link>
            </section>

            <!-- Empty state -->
            <section
                v-else
                aria-label="No projects yet"
                class="glass-card flex flex-col items-center gap-3 p-10 text-center"
            >
                <span
                    class="flex h-12 w-12 items-center justify-center rounded-full border border-border-subtle bg-slate-950/40"
                >
                    <FolderKanban
                        class="h-5 w-5 text-text-muted"
                        aria-hidden="true"
                    />
                </span>
                <h2 class="text-base font-semibold text-text-primary">
                    No projects yet
                </h2>
                <p class="max-w-sm text-sm text-text-muted">
                    Projects are the top-level containers for repositories,
                    services, and deployments. Create your first one to start
                    populating the dashboard with real data.
                </p>
                <Link
                    :href="route('projects.create')"
                    class="mt-2 inline-flex items-center gap-2 rounded-lg border border-accent-cyan/40 bg-accent-cyan/15 px-3 py-2 text-sm font-semibold text-accent-cyan transition hover:border-accent-cyan/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                >
                    <Plus class="h-4 w-4" aria-hidden="true" />
                    Create your first project
                </Link>
            </section>
        </div>
    </AppLayout>
</template>
