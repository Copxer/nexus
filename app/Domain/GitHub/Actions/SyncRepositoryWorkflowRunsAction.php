<?php

namespace App\Domain\GitHub\Actions;

use App\Domain\GitHub\Services\GitHubClient;
use App\Models\Repository;
use App\Models\WorkflowRun;

/**
 * Sync one repository's GitHub Actions workflow runs into the local
 * `workflow_runs` table. Parallel structure to spec 015's
 * `SyncRepositoryIssuesAction` and spec 016's
 * `SyncRepositoryPullRequestsAction`.
 *
 * GitHub's `/actions/runs` endpoint doesn't support `?since=`, so we
 * always full-fetch (capped at 100 most-recent rows). Idempotent: the
 * upsert keys on `(repository_id, github_id)` so the same delivery
 * lands on the same row across re-syncs and webhook upserts.
 *
 * Returns the number of runs persisted (insert + update both count).
 *
 * Wraps `GitHubApiException` from the client up to the caller (the job
 * catches it and flips `workflow_runs_sync_status` to failed; on 401
 * the connection is also expired).
 */
class SyncRepositoryWorkflowRunsAction
{
    public function __construct(
        private readonly NormalizeGitHubWorkflowRunAction $normalizer,
    ) {}

    public function execute(Repository $repository, GitHubClient $client): int
    {
        $payload = $client->listWorkflowRuns($repository->full_name);

        $count = 0;

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $normalized = $this->normalizer->execute($entry);

            if ($normalized === null) {
                continue;
            }

            WorkflowRun::query()->updateOrCreate(
                [
                    'repository_id' => $repository->id,
                    'github_id' => $normalized['github_id'],
                ],
                $normalized,
            );

            $count++;
        }

        return $count;
    }
}
