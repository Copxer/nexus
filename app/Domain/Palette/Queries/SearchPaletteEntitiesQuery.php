<?php

namespace App\Domain\Palette\Queries;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\GithubIssue;
use App\Models\GithubPullRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Spec 043 — async server-side search across the two entity kinds
 * that scale past what the shared prop can carry (alerts + work
 * items = issues + pull requests).
 *
 * Every branch is scoped to the auth user's projects. `AlertSource::System`
 * alerts (spec 038 self-checks with no project) are included since the
 * operator needs to reach them too.
 *
 * LIKE wildcards inside `q` (`%` / `_`) are not escaped: the palette
 * result set is capped per kind + the endpoint is throttled 30/min,
 * so wildcard-expansion is benign at worst. Sanitizing would need
 * driver-specific `ESCAPE` clauses (SQLite ≠ MySQL default) and
 * doesn't buy the palette anything.
 */
class SearchPaletteEntitiesQuery
{
    public const RESULT_CAP_PER_KIND = 8;

    /**
     * @return array{
     *   workItems: list<array<string, mixed>>,
     *   alerts: list<array<string, mixed>>,
     * }
     */
    public function execute(User $user, string $query): array
    {
        $q = trim($query);
        if ($q === '') {
            return ['workItems' => [], 'alerts' => []];
        }

        $projectIds = Project::query()
            ->where('owner_user_id', $user->id)
            ->pluck('id');

        return [
            'workItems' => $this->searchWorkItems($projectIds, $q),
            'alerts' => $this->searchAlerts($projectIds, $q),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchWorkItems(Collection $projectIds, string $q): array
    {
        if ($projectIds->isEmpty()) {
            return [];
        }

        $needle = '%'.$q.'%';

        $issues = GithubIssue::query()
            ->whereHas(
                'repository',
                fn ($qb) => $qb->whereIn('project_id', $projectIds),
            )
            ->where('title', 'like', $needle)
            ->orderByDesc('updated_at_github')
            ->limit(self::RESULT_CAP_PER_KIND)
            ->with('repository:id,full_name')
            ->get(['id', 'repository_id', 'title', 'number', 'state'])
            ->map(fn (GithubIssue $i): array => [
                'kind' => 'issue',
                'id' => $i->id,
                'label' => "Issue · #{$i->number} {$i->title}",
                'subtitle' => $i->repository?->full_name,
                'url' => route('work-items.index', ['q' => "#{$i->number}"]),
                'badge' => $i->state?->value,
            ])
            ->all();

        $pulls = GithubPullRequest::query()
            ->whereHas(
                'repository',
                fn ($qb) => $qb->whereIn('project_id', $projectIds),
            )
            ->where('title', 'like', $needle)
            ->orderByDesc('updated_at_github')
            ->limit(self::RESULT_CAP_PER_KIND)
            ->with('repository:id,full_name')
            ->get(['id', 'repository_id', 'title', 'number', 'state'])
            ->map(fn (GithubPullRequest $p): array => [
                'kind' => 'pull_request',
                'id' => $p->id,
                'label' => "PR · #{$p->number} {$p->title}",
                'subtitle' => $p->repository?->full_name,
                'url' => route('work-items.index', ['q' => "#{$p->number}"]),
                'badge' => $p->state?->value,
            ])
            ->all();

        return array_slice(array_merge($issues, $pulls), 0, self::RESULT_CAP_PER_KIND);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchAlerts(Collection $projectIds, string $q): array
    {
        $needle = '%'.$q.'%';

        return Alert::query()
            ->where(function ($qb) use ($projectIds): void {
                $qb->whereIn('project_id', $projectIds)
                    ->orWhere('source', AlertSource::System->value);
            })
            ->where(function ($qb) use ($needle): void {
                $qb->where('title', 'like', $needle)
                    ->orWhere('type', 'like', $needle);
            })
            ->whereIn('status', [AlertStatus::Open->value, AlertStatus::Acknowledged->value])
            ->orderByDesc('triggered_at')
            ->limit(self::RESULT_CAP_PER_KIND)
            ->get(['id', 'title', 'type', 'severity', 'status', 'source'])
            ->map(fn (Alert $a): array => [
                'kind' => 'alert',
                'id' => $a->id,
                'label' => "Alert · {$a->title}",
                'subtitle' => "{$a->type} · ".$a->status->value,
                'url' => route('alerts.index'),
                'badge' => $a->severity->value,
            ])
            ->all();
    }
}
