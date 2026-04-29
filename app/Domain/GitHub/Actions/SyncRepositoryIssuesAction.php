<?php

namespace App\Domain\GitHub\Actions;

use App\Domain\GitHub\Services\GitHubClient;
use App\Models\GithubIssue;
use App\Models\Repository;

/**
 * Sync one repository's issues into the local `github_issues` table.
 *
 * - Fetch via `GitHubClient::listIssues($fullName, $since)`.
 * - Drop pull-request rows (the GitHub `/issues` endpoint returns them
 *   alongside issues; `NormalizeGitHubIssueAction` returns null for
 *   those payloads).
 * - Upsert each remaining row keyed on `(repository_id, github_id)`.
 *
 * Returns the number of issues persisted (insert + update both count).
 *
 * Wraps `GitHubApiException` from the client up to the caller (the job
 * catches it and flips the repo's `issues_sync_status` to failed; on
 * 401 the connection is also expired).
 */
class SyncRepositoryIssuesAction
{
    public function __construct(
        private readonly NormalizeGitHubIssueAction $normalizer,
    ) {}

    public function execute(Repository $repository, GitHubClient $client): int
    {
        $payload = $client->listIssues(
            $repository->full_name,
            $repository->issues_synced_at,
        );

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

            GithubIssue::query()->updateOrCreate(
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
