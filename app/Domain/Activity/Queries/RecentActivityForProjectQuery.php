<?php

namespace App\Domain\Activity\Queries;

use App\Domain\Activity\ActivityEventPresenter;
use App\Models\ActivityEvent;
use App\Models\Project;

/**
 * Read-side query for the per-project Activity tab on `Projects/Show`.
 * Same shape as `RecentActivityForUserQuery` but scoped to events on
 * one project's repositories — joins `activity_events` →
 * `repositories` → `projects` and filters by `projects.id`.
 *
 * Output matches `ActivityEventPresenter` (the right-rail / `/activity`
 * page contract) so the project tab can reuse `<ActivityFeed>` 1:1.
 */
class RecentActivityForProjectQuery
{
    /** Same cap as the right-rail; the tab is a focused inline view. */
    public const TAB_LIMIT = 20;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(Project $project, int $limit = self::TAB_LIMIT): array
    {
        return ActivityEvent::query()
            ->with('repository:id,full_name')
            ->whereHas('repository', fn ($q) => $q->where('project_id', $project->id))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ActivityEvent $event) => ActivityEventPresenter::present($event))
            ->all();
    }
}
