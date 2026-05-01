<?php

namespace App\Domain\GitHub\Queries;

use App\Models\Repository;
use App\Models\WorkflowRun;

/**
 * Trim the `workflow_runs` rows for one repository down to the shape
 * the Repository show page's Workflow Runs tab needs. Parallel to
 * spec 015's `IssuesForRepositoryQuery` and spec 016's
 * `PullRequestsForRepositoryQuery`.
 *
 * Sort: most-recent-first by `run_started_at`, with `github_id desc`
 * as the deterministic tie-break for runs sharing a started_at (or
 * both null). Spec 021's cross-repo timeline reuses the same axis.
 *
 * Returns plain arrays so the controller doesn't have to know about
 * Eloquent magic when building the Inertia payload.
 */
class WorkflowRunsForRepositoryQuery
{
    /** Soft cap on rows; pagination lands when a real user blows past. */
    private const LIMIT = 100;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(Repository $repository): array
    {
        return WorkflowRun::query()
            ->where('repository_id', $repository->id)
            ->orderByDesc('run_started_at')
            ->orderByDesc('github_id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (WorkflowRun $run) => [
                'id' => $run->id,
                'run_number' => $run->run_number,
                'name' => $run->name,
                'event' => $run->event,
                'status' => $run->status?->value,
                'conclusion' => $run->conclusion?->value,
                'head_branch' => $run->head_branch,
                'head_sha' => $run->head_sha,
                'actor_login' => $run->actor_login,
                'html_url' => $run->html_url,
                'run_started_at' => $run->run_started_at?->diffForHumans(),
                'run_updated_at' => $run->run_updated_at?->diffForHumans(),
            ])
            ->all();
    }
}
