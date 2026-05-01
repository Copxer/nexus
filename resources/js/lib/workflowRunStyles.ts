/**
 * Shared style helpers for GitHub Actions workflow runs.
 *
 * Used by:
 *   - `Pages/Repositories/Show.vue` (per-repo Workflow Runs tab — spec 020)
 *   - `Pages/Deployments/Index.vue` + `DeploymentDrawer.vue` (cross-repo
 *     timeline — spec 021)
 *   - `Pages/Projects/Show.vue` (per-project Deployments tab —
 *     fix/project-tabs)
 *
 * Centralizing avoids drift when the GitHub conclusion / status enum
 * grows (e.g. `startup_failure`). The PHP-side counterparts live on
 * `WorkflowRunConclusion::badgeTone()` + `WorkflowRunStatus::badgeTone()`
 * — keep the two renderers in sync when adding cases.
 */

export type WorkflowRunStatusValue =
    | 'queued'
    | 'in_progress'
    | 'completed'
    | string
    | null;

export type WorkflowRunConclusionValue =
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

export const conclusionTone = (
    conclusion: WorkflowRunConclusionValue,
): 'success' | 'danger' | 'warning' | 'muted' =>
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

export const runStatusTone = (
    status: WorkflowRunStatusValue,
): 'muted' | 'info' | 'success' =>
    (
        ({
            queued: 'muted',
            in_progress: 'info',
            completed: 'success',
        }) as const
    )[status ?? ''] ?? 'muted';

/** Display label for a conclusion badge — `timed_out` reads cleaner
 *  as `timed out`. Falls back to the raw value for unknown enums. */
export const conclusionLabel = (
    conclusion: WorkflowRunConclusionValue,
): string => (conclusion === null ? '—' : conclusion.replace(/_/g, ' '));

/**
 * Status-dot Tailwind class for a row that may have either a status
 * (in-flight) or a conclusion (terminal). The `animate-pulse` on the
 * in-progress accent gives a subtle "this is moving" cue.
 */
export const runStatusDotClass = (row: {
    status: WorkflowRunStatusValue;
    conclusion: WorkflowRunConclusionValue;
}): string => {
    if (row.conclusion === 'success') return 'bg-status-success';
    if (row.conclusion === 'failure') return 'bg-status-danger';
    if (
        row.conclusion === 'cancelled' ||
        row.conclusion === 'timed_out' ||
        row.conclusion === 'action_required'
    )
        return 'bg-status-warning';
    if (row.status === 'in_progress') return 'bg-accent-cyan animate-pulse';
    return 'bg-text-muted';
};
