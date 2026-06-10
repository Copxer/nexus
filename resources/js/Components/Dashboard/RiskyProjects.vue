<script setup lang="ts">
import HealthScoreBadge from '@/Components/Project/HealthScoreBadge.vue';
import { projectIcon } from '@/lib/projectIcons';
import type { RiskyProjectRow } from '@/types';
import { Link } from '@inertiajs/vue3';
import { FolderKanban, ShieldCheck } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Spec 035 — Overview "Risky projects" panel. The query layer
 * (`GetOverviewDashboardQuery::riskyProjects`) already sorts ascending
 * by `health_score` with nulls last and caps at 6, so this component
 * is pure render: project chip + name + `HealthScoreBadge` + last-
 * activity line. Each row links to the project's Show page.
 *
 * Empty state triggers when the array is empty (user has no projects)
 * or when every row sits in the "healthy" band (score ≥ 90). The
 * panel hides quietly rather than surfacing a "no risky projects"
 * card — Overview's grid stays tight.
 */
const props = defineProps<{
    projects: RiskyProjectRow[];
}>();

const everythingHealthy = computed<boolean>(
    () =>
        props.projects.length > 0
        && props.projects.every(
            (row) => row.health_band === 'healthy' || row.health_band === null,
        ),
);

const iconAccentClass = (color: string | null): string => {
    switch (color) {
        case 'cyan':
            return 'text-accent-cyan';
        case 'blue':
            return 'text-accent-blue';
        case 'purple':
            return 'text-accent-purple';
        case 'magenta':
            return 'text-accent-magenta';
        case 'success':
            return 'text-status-success';
        case 'danger':
            return 'text-status-danger';
        default:
            return 'text-text-secondary';
    }
};
</script>

<template>
    <section class="glass-card p-5">
        <header class="mb-4 flex items-center justify-between">
            <div class="flex flex-col gap-1">
                <span
                    class="text-[10px] font-semibold uppercase tracking-[0.32em] text-text-muted"
                >
                    Health
                </span>
                <h3 class="text-sm font-semibold text-text-primary">
                    Risky projects
                </h3>
            </div>
            <ShieldCheck
                v-if="everythingHealthy"
                class="h-4 w-4 text-status-success"
                aria-hidden="true"
            />
        </header>

        <ul
            v-if="props.projects.length > 0 && !everythingHealthy"
            class="space-y-2"
        >
            <li
                v-for="project in props.projects"
                :key="project.id"
            >
                <Link
                    :href="route('projects.show', project.slug)"
                    class="group flex items-center justify-between gap-3 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 transition hover:border-accent-cyan/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <span
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-border-subtle bg-background-panel"
                        >
                            <component
                                :is="projectIcon(project.icon) ?? FolderKanban"
                                class="h-4 w-4"
                                :class="iconAccentClass(project.color)"
                                aria-hidden="true"
                            />
                        </span>
                        <div class="flex min-w-0 flex-col">
                            <span
                                class="truncate text-sm font-semibold text-text-primary group-hover:text-accent-cyan"
                            >
                                {{ project.name }}
                            </span>
                            <span
                                v-if="project.last_activity_at"
                                class="truncate text-[11px] text-text-muted"
                            >
                                {{ project.last_activity_at }}
                            </span>
                        </div>
                    </div>
                    <HealthScoreBadge
                        :score="project.health_score"
                        :band="project.health_band"
                    />
                </Link>
            </li>
        </ul>

        <div
            v-else
            class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-border-subtle bg-background-panel-hover/40 px-3 py-6 text-center"
        >
            <ShieldCheck
                class="h-5 w-5 text-status-success"
                aria-hidden="true"
            />
            <p class="text-sm font-semibold text-text-primary">
                All projects healthy
            </p>
            <p class="text-[11px] text-text-muted">
                Nothing needs your attention right now.
            </p>
        </div>
    </section>
</template>
