<?php

namespace App\Domain\GitHub\Queries;

use App\Models\GithubPullRequest;
use App\Models\Repository;

/**
 * Trim the `github_pull_requests` rows for one repository down to the
 * shape the Repository show page's PRs tab needs. Parallel to spec
 * 015's `IssuesForRepositoryQuery`.
 *
 * Returns plain arrays (not Eloquent models) so the controller doesn't
 * have to know about Eloquent magic when building the Inertia payload.
 */
class PullRequestsForRepositoryQuery
{
    /** Soft cap on rows; pagination lands when a real user blows past. */
    private const LIMIT = 50;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(Repository $repository): array
    {
        return GithubPullRequest::query()
            ->where('repository_id', $repository->id)
            ->orderByDesc('updated_at_github')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (GithubPullRequest $pr) => [
                'id' => $pr->id,
                'number' => $pr->number,
                'title' => $pr->title,
                'state' => $pr->state?->value,
                'author_login' => $pr->author_login,
                'base_branch' => $pr->base_branch,
                'head_branch' => $pr->head_branch,
                'draft' => $pr->draft,
                'comments_count' => $pr->comments_count,
                'updated_at_github' => $pr->updated_at_github?->diffForHumans(),
                'html_url' => "https://github.com/{$repository->full_name}/pull/{$pr->number}",
            ])
            ->all();
    }
}
