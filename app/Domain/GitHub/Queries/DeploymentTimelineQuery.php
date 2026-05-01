<?php

namespace App\Domain\GitHub\Queries;

use App\Models\Repository;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Support\Collection;

/**
 * Cross-repository workflow-run feed powering `/deployments`. Joins
 * `workflow_runs` filtered by the repos the user can see (phase-1:
 * repos owned by the user's projects), tagging each row with project
 * + repository chips so the timeline can render without N+1.
 *
 * Filters supported (driven from the query string by
 * `DeploymentController`):
 *   - `project_id` (optional, scopes to one project)
 *   - `repository_id` (optional, scopes to one repo within the user's set)
 *   - `status` ∈ `queued|in_progress|completed`
 *   - `conclusion` ∈ `success|failure|cancelled|timed_out|action_required|stale|neutral|skipped`
 *   - `branch` (optional, exact match on `head_branch`)
 *
 * Sort is locked to `run_started_at desc, github_id desc` — matches
 * the per-repo Workflow Runs tab and the cross-repo timeline expects
 * the same axis. Capped at 100 rows; cursor pagination is a follow-up.
 */
class DeploymentTimelineQuery
{
    private const LIMIT = 100;

    /**
     * @param  array{project_id?: int|null, repository_id?: int|null, status?: string|null, conclusion?: string|null, branch?: string|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function execute(User $user, array $filters = []): array
    {
        $repositoryIds = $this->visibleRepositoryIds($user, $filters['project_id'] ?? null);

        if ($repositoryIds->isEmpty()) {
            return [];
        }

        $repositoryId = $filters['repository_id'] ?? null;

        if ($repositoryId !== null && ! $repositoryIds->contains($repositoryId)) {
            // Filter to a repo the user can't see — return empty rather
            // than throwing; the form builder is the one that should
            // prevent invalid pairings.
            return [];
        }

        $scopeIds = $repositoryId !== null
            ? collect([$repositoryId])
            : $repositoryIds;

        $query = WorkflowRun::query()
            ->with([
                'repository:id,full_name,name,html_url,project_id',
                'repository.project:id,slug,name,color,icon',
            ])
            ->whereIn('repository_id', $scopeIds);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['conclusion'])) {
            $query->where('conclusion', $filters['conclusion']);
        }

        if (! empty($filters['branch'])) {
            $query->where('head_branch', $filters['branch']);
        }

        return $query
            ->orderByDesc('run_started_at')
            ->orderByDesc('github_id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (WorkflowRun $run) => [
                'id' => $run->id,
                'github_id' => $run->github_id,
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
                // Raw ISO timestamp powers client-side day-grouping headers
                // on the timeline. Using `diffForHumans` for grouping would
                // bucket "3 hours ago" and "5 hours ago" into different
                // groups — wrong shape.
                'run_started_at_iso' => $run->run_started_at?->toIso8601String(),
                'run_updated_at' => $run->run_updated_at?->diffForHumans(),
                'run_updated_at_iso' => $run->run_updated_at?->toIso8601String(),
                'run_completed_at_iso' => $run->run_completed_at?->toIso8601String(),
                // Duration in seconds — null until completion. Drawer
                // formats this client-side (`4m 12s`).
                'duration_seconds' => $run->run_completed_at && $run->run_started_at
                    ? (int) abs($run->run_completed_at->diffInSeconds($run->run_started_at))
                    : null,
                'repository' => $run->repository ? [
                    'id' => $run->repository->id,
                    'full_name' => $run->repository->full_name,
                    'name' => $run->repository->name,
                    'html_url' => $run->repository->html_url,
                ] : null,
                'project' => $run->repository?->project ? [
                    'id' => $run->repository->project->id,
                    'slug' => $run->repository->project->slug,
                    'name' => $run->repository->project->name,
                    'color' => $run->repository->project->color,
                    'icon' => $run->repository->project->icon,
                ] : null,
            ])
            ->all();
    }

    /**
     * Repositories the user can see, optionally further scoped to one
     * project. Phase-1 ties visibility to the user's own projects;
     * multi-team scoping ships with teams.
     */
    private function visibleRepositoryIds(User $user, ?int $projectId): Collection
    {
        return Repository::query()
            ->whereHas('project', function ($query) use ($user, $projectId) {
                $query->where('owner_user_id', $user->id);
                if ($projectId !== null) {
                    $query->where('id', $projectId);
                }
            })
            ->pluck('id');
    }
}
