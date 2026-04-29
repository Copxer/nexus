<?php

namespace App\Domain\GitHub\Queries;

use App\Models\GithubIssue;
use App\Models\Repository;

/**
 * Trim the `github_issues` rows for a repository down to the shape the
 * Repository Show page's Issues tab needs. Returns plain arrays so the
 * controller doesn't have to know about Eloquent magic.
 *
 * Cap at 50 most-recent-by-`updated_at_github` for MVP — pagination
 * lands when a real user blows past that.
 */
class IssuesForRepositoryQuery
{
    /** Soft cap on rows returned to the page. */
    private const LIMIT = 50;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(Repository $repository): array
    {
        return GithubIssue::query()
            ->where('repository_id', $repository->id)
            ->orderByDesc('updated_at_github')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (GithubIssue $issue) => [
                'id' => $issue->id,
                'number' => $issue->number,
                'title' => $issue->title,
                'state' => $issue->state?->value,
                'author_login' => $issue->author_login,
                'comments_count' => $issue->comments_count,
                'updated_at_github' => $issue->updated_at_github?->diffForHumans(),
                'html_url' => "https://github.com/{$repository->full_name}/issues/{$issue->number}",
            ])
            ->all();
    }
}
