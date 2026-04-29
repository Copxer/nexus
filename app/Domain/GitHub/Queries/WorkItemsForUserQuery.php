<?php

namespace App\Domain\GitHub\Queries;

use App\Models\GithubIssue;
use App\Models\GithubPullRequest;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Cross-repository work-queue feeding `/work-items`. Joins
 * `github_issues` + `github_pull_requests` filtered by the repos the
 * user can see (phase-1: repos owned by the user's projects), tagging
 * each row with `kind: 'issue' | 'pull_request'` so the page can
 * render either uniformly.
 *
 * Filters supported by the page (driven from query string by
 * `WorkItemController`):
 *   - `kind` ∈ `issues|pulls|all`
 *   - `state` ∈ `open|closed|merged|all`
 *   - `repository_id` (optional, scopes to one repo)
 *   - `q` (optional, free-text on title or `#number`)
 *
 * Sort is locked to `updated_at_github desc` and capped at 100 rows
 * across both kinds (no pagination yet — phase 9 polish).
 */
class WorkItemsForUserQuery
{
    private const LIMIT = 100;

    /**
     * @param  array{kind?: string, state?: string, repository_id?: int|null, q?: string|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function execute(User $user, array $filters = []): array
    {
        $kind = $filters['kind'] ?? 'all';
        $state = $filters['state'] ?? 'open';
        $repositoryId = $filters['repository_id'] ?? null;
        $search = $filters['q'] ?? null;

        $repositoryIds = $this->visibleRepositoryIds($user);

        if ($repositoryIds->isEmpty()) {
            return [];
        }

        if ($repositoryId !== null && ! $repositoryIds->contains($repositoryId)) {
            return [];
        }

        $scopeIds = $repositoryId !== null
            ? collect([$repositoryId])
            : $repositoryIds;

        $issues = $kind === 'pulls' ? collect() : $this->fetchIssues($scopeIds, $state, $search);
        $pulls = $kind === 'issues' ? collect() : $this->fetchPullRequests($scopeIds, $state, $search);

        return $issues
            ->concat($pulls)
            ->sortByDesc('updated_at_github_raw')
            ->take(self::LIMIT)
            ->values()
            ->map(function (array $row) {
                unset($row['updated_at_github_raw']);

                return $row;
            })
            ->all();
    }

    /**
     * Repositories the user can see — phase-1 ties this to the user's
     * own projects. Multi-team scoping ships later.
     */
    private function visibleRepositoryIds(User $user): Collection
    {
        return Repository::query()
            ->whereHas('project', fn ($query) => $query->where('owner_user_id', $user->id))
            ->pluck('id');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchIssues(Collection $repositoryIds, string $state, ?string $search): Collection
    {
        $query = GithubIssue::query()
            ->with('repository:id,full_name,name')
            ->whereIn('repository_id', $repositoryIds);

        if ($state !== 'all') {
            // Issues only have `open` / `closed`. Filtering by `merged`
            // returns nothing (issues are never merged), which is the
            // desired behavior — the kind filter handles it cleanly.
            if ($state === 'merged') {
                return collect();
            }
            $query->where('state', $state);
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('title', 'like', "%{$search}%")
                    ->orWhere('number', $this->parseNumberSearch($search));
            });
        }

        return $query
            ->orderByDesc('updated_at_github')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (GithubIssue $issue) => [
                'id' => "issue-{$issue->id}",
                'kind' => 'issue',
                'number' => $issue->number,
                'title' => $issue->title,
                'state' => $issue->state?->value,
                'author_login' => $issue->author_login,
                'comments_count' => $issue->comments_count,
                'updated_at_github' => $issue->updated_at_github?->diffForHumans(),
                'updated_at_github_raw' => $issue->updated_at_github,
                'html_url' => $issue->repository
                    ? "https://github.com/{$issue->repository->full_name}/issues/{$issue->number}"
                    : null,
                'repository' => $issue->repository ? [
                    'id' => $issue->repository->id,
                    'full_name' => $issue->repository->full_name,
                    'name' => $issue->repository->name,
                ] : null,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchPullRequests(Collection $repositoryIds, string $state, ?string $search): Collection
    {
        $query = GithubPullRequest::query()
            ->with('repository:id,full_name,name')
            ->whereIn('repository_id', $repositoryIds);

        if ($state !== 'all') {
            $query->where('state', $state);
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('title', 'like', "%{$search}%")
                    ->orWhere('number', $this->parseNumberSearch($search));
            });
        }

        return $query
            ->orderByDesc('updated_at_github')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (GithubPullRequest $pr) => [
                'id' => "pull-{$pr->id}",
                'kind' => 'pull_request',
                'number' => $pr->number,
                'title' => $pr->title,
                'state' => $pr->state?->value,
                'author_login' => $pr->author_login,
                'comments_count' => $pr->comments_count,
                'draft' => $pr->draft,
                'updated_at_github' => $pr->updated_at_github?->diffForHumans(),
                'updated_at_github_raw' => $pr->updated_at_github,
                'html_url' => $pr->repository
                    ? "https://github.com/{$pr->repository->full_name}/pull/{$pr->number}"
                    : null,
                'repository' => $pr->repository ? [
                    'id' => $pr->repository->id,
                    'full_name' => $pr->repository->full_name,
                    'name' => $pr->repository->name,
                ] : null,
            ]);
    }

    /**
     * Strip leading `#` so users can search "#42" or "42" interchangeably.
     * Returns 0 (which won't match any row) for non-numeric input — the
     * title `LIKE` branch handles those.
     */
    private function parseNumberSearch(string $search): int
    {
        $trimmed = ltrim(trim($search), '#');

        return ctype_digit($trimmed) ? (int) $trimmed : 0;
    }
}
