<?php

namespace App\Domain\Activity\Queries;

use App\Domain\Activity\ActivityEventPresenter;
use App\Models\ActivityEvent;
use App\Models\Alert;
use App\Models\Host;
use App\Models\Project;
use App\Models\Website;

/**
 * Read-side query for the per-project Activity tab on `Projects/Show`.
 * Mirrors `RecentActivityForUserQuery` but scoped to one project — the
 * same four source branches: repository (spec 017/020), monitoring
 * (spec 024), hosts (spec 029), alerts (spec 030).
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
        $projectWebsiteIds = Website::query()
            ->where('project_id', $project->id)
            ->pluck('id')
            ->all();

        $projectHostIds = Host::query()
            ->where('project_id', $project->id)
            ->pluck('id')
            ->all();

        $projectAlertIds = Alert::query()
            ->where('project_id', $project->id)
            ->pluck('id')
            ->all();

        return ActivityEvent::query()
            ->with('repository:id,full_name')
            ->where(function ($q) use ($project, $projectWebsiteIds, $projectHostIds, $projectAlertIds) {
                $q->whereHas('repository', function ($inner) use ($project) {
                    $inner->where('project_id', $project->id);
                });

                if (! empty($projectWebsiteIds)) {
                    $q->orWhere(function ($inner) use ($projectWebsiteIds) {
                        $inner->where('source', 'monitoring')
                            ->whereIn('metadata->website_id', $projectWebsiteIds);
                    });
                }

                if (! empty($projectHostIds)) {
                    $q->orWhere(function ($inner) use ($projectHostIds) {
                        $inner->where('source', 'hosts')
                            ->whereIn('metadata->host_id', $projectHostIds);
                    });
                }

                if (! empty($projectAlertIds)) {
                    $q->orWhere(function ($inner) use ($projectAlertIds) {
                        $inner->where('source', 'alerts')
                            ->whereIn('metadata->alert_id', $projectAlertIds);
                    });
                }
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ActivityEvent $event) => ActivityEventPresenter::present($event))
            ->all();
    }
}
