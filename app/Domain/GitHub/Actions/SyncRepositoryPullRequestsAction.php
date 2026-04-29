<?php

namespace App\Domain\GitHub\Actions;

use App\Domain\GitHub\Services\GitHubClient;
use App\Models\GithubPullRequest;
use App\Models\Repository;

/**
 * Sync one repository's pull requests into the local
 * `github_pull_requests` table. Parallel structure to spec 015's
 * `SyncRepositoryIssuesAction`.
 *
 * Differences from the issues action:
 *   - GitHub's `/pulls` endpoint doesn't support `?since=`, so we
 *     always full-fetch (capped at 100 most-recently-updated rows).
 *   - The endpoint only returns PRs, so the normalizer never returns
 *     null — the "drop PRs" branch from the issues path doesn't apply.
 *
 * Returns the number of PRs persisted (insert + update both count).
 *
 * Wraps `GitHubApiException` from the client up to the caller (the job
 * catches it and flips `prs_sync_status` to failed; on 401 the
 * connection is also expired).
 */
class SyncRepositoryPullRequestsAction
{
    public function __construct(
        private readonly NormalizeGitHubPullRequestAction $normalizer,
    ) {}

    public function execute(Repository $repository, GitHubClient $client): int
    {
        $payload = $client->listPullRequests($repository->full_name);

        $now = now();
        $count = 0;

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $normalized = $this->normalizer->execute($entry);

            if ($normalized === null) {
                continue;
            }

            GithubPullRequest::query()->updateOrCreate(
                [
                    'repository_id' => $repository->id,
                    'github_id' => $normalized['github_id'],
                ],
                [
                    ...$normalized,
                    'synced_at' => $now,
                ],
            );

            $count++;
        }

        return $count;
    }
}
