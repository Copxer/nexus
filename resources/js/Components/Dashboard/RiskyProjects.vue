<script setup lang="ts">
import HealthScoreBadge from '@/Components/Project/HealthScoreBadge.vue';
import { projectIcon } from '@/lib/projectIcons';
import type { RiskyProjectRow } from '@/types';
import { Link, router } from '@inertiajs/vue3';
import { FolderKanban, RefreshCw, ShieldCheck } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Spec 035 — Overview "Risky projects" panel. The query layer
 * (`GetOverviewDashboardQuery::riskyProjects`) sorts ascending by
 * `health_score` with nulls last and caps at 6, so this component is
 * pure render: project chip + name + `HealthScoreBadge` + last-
 * activity line. Each row links to the project's Show page.
 *
 * Empty-state placeholder ("All projects healthy") triggers when:
 *   - the user has no projects, OR
 *   - every project sits at score ≥ 70 (the §14.2 "good" band floor,
 *     `healthy` + `good`) or has no score yet (`null`).
 *
 * A "good"-banded project at score 75 is intentionally *not* shown:
 * §14.2 calls it "good", not "needs attention". The panel surfaces
 * only `degraded` / `warning` / `critical` rows that genuinely warrant
 * the user's eyes today.
 */
const props = defineProps<{
    projects: RiskyProjectRow[];
    canRegenerate: boolean;
}>();

const HIDING_BANDS = new Set(['healthy', 'good']);

const shouldShowList = computed<boolean>(() => {
    if (props.projects.length === 0) {
        return false;
    }

    return props.projects.some(
        (row) => row.health_band !== null && !HIDING_BANDS.has(row.health_band),
    );
});

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

const fallbackSummary = (project: RiskyProjectRow): string => {
    if (project.health_score === null) {
        return 'Nexus has not computed a health score for this project yet.';
    }

    return `Nexus is showing a ${project.health_band ?? 'unknown'} health band at ${project.health_score}/100. Regenerate an AI explanation to summarize the current drivers.`;
};

const regenerateExplanation = (project: RiskyProjectRow): void => {
    router.post(
        route('overview.projects.health-explanation.regenerate', project.slug),
        {},
        { preserveScroll: true },
    );
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
                v-if="!shouldShowList && props.projects.length > 0"
                class="h-4 w-4 text-status-success"
                aria-hidden="true"
            />
        </header>

        <ul
            v-if="shouldShowList"
            class="space-y-2"
        >
            <li
                v-for="project in props.projects.filter((p) => p.health_band !== null && !HIDING_BANDS.has(p.health_band))"
                :key="project.id"
            >
                <article
                    class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 transition hover:border-accent-cyan/40"
                >
                    <div class="flex items-center justify-between gap-3">
                        <Link
                            :href="route('projects.show', project.slug)"
                            class="group flex min-w-0 items-center gap-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        >
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
                        </Link>
                        <HealthScoreBadge
                            :score="project.health_score"
                            :band="project.health_band"
                        />
                    </div>

                    <details class="group/explanation mt-2 rounded-md border border-border-subtle bg-background-panel/70 px-3 py-2">
                        <summary class="cursor-pointer list-none text-[11px] font-semibold uppercase tracking-[0.2em] text-accent-cyan transition hover:text-accent-cyan/80">
                            Why?
                        </summary>

                        <div class="mt-3 space-y-3 text-xs text-text-secondary">
                            <p v-if="project.health_explanation?.status === 'pending'">
                                AI explanation is being generated. Refresh will update this card when the job finishes.
                            </p>
                            <p v-else-if="project.health_explanation?.status === 'failed'">
                                Explanation failed{{ project.health_explanation.failed_at ? ` ${project.health_explanation.failed_at}` : '' }}.
                                <span v-if="project.health_explanation.error_message">
                                    {{ project.health_explanation.error_message }}
                                </span>
                            </p>
                            <p v-else-if="project.health_explanation?.status === 'explained' && project.health_explanation.summary">
                                {{ project.health_explanation.summary }}
                            </p>
                            <p v-else>
                                {{ fallbackSummary(project) }}
                            </p>

                            <div v-if="project.health_explanation?.drivers.length" class="space-y-1">
                                <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted">
                                    Drivers
                                </p>
                                <ul class="space-y-1">
                                    <li
                                        v-for="driver in project.health_explanation.drivers"
                                        :key="driver"
                                    >
                                        {{ driver }}
                                    </li>
                                </ul>
                            </div>

                            <div v-if="project.health_explanation?.recommended_actions.length" class="space-y-1">
                                <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted">
                                    Recommended actions
                                </p>
                                <ul class="space-y-1">
                                    <li
                                        v-for="action in project.health_explanation.recommended_actions"
                                        :key="action"
                                    >
                                        {{ action }}
                                    </li>
                                </ul>
                            </div>

                            <div class="flex flex-col gap-2 border-t border-border-subtle pt-3 sm:flex-row sm:items-center sm:justify-between">
                                <span class="font-mono text-[10px] text-text-muted">
                                    <template v-if="project.health_explanation?.explained_at">
                                        Explained {{ project.health_explanation.explained_at }}
                                    </template>
                                    <template v-else-if="project.health_explanation?.status === 'pending'">
                                        Pending generation
                                    </template>
                                    <template v-else-if="project.health_explanation?.status === 'failed'">
                                        Failed generation
                                    </template>
                                    <template v-else>
                                        No AI explanation yet
                                    </template>
                                </span>

                                <button
                                    v-if="props.canRegenerate"
                                    type="button"
                                    class="inline-flex items-center justify-center gap-1.5 rounded-md border border-accent-cyan/30 px-2 py-1 font-mono text-[10px] uppercase tracking-[0.16em] text-accent-cyan transition hover:border-accent-cyan/60 hover:bg-accent-cyan/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                    @click="regenerateExplanation(project)"
                                >
                                    <RefreshCw class="h-3 w-3" aria-hidden="true" />
                                    Regenerate
                                </button>
                            </div>
                        </div>
                    </details>
                </article>
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
