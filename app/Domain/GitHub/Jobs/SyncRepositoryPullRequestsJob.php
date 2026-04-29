<?php

namespace App\Domain\GitHub\Jobs;

use App\Domain\GitHub\Actions\SyncRepositoryPullRequestsAction;
use App\Domain\GitHub\Exceptions\GitHubApiException;
use App\Domain\GitHub\Services\GitHubClient;
use App\Enums\RepositorySyncStatus;
use App\Models\GithubConnection;
use App\Models\Repository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sync a single Repository's pull requests from GitHub into
 * `github_pull_requests`. Parallel to spec 015's
 * `SyncRepositoryIssuesJob` — same lifecycle, same 401 plumbing,
 * same preserve-on-failure timestamp guarantee.
 *
 *   pending → syncing → synced (happy path)
 *   pending → syncing → failed (caught GitHubApiException or Throwable)
 *
 * On 401 we additionally clear the connection's `access_token` and
 * zero out `expires_at` so spec-013's Settings card surfaces the
 * Reconnect CTA.
 *
 * `prs_synced_at` is only stamped on success — the "Last sync" UI
 * (Repository PRs tab) must mean "last successful sync."
 */
class SyncRepositoryPullRequestsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $repositoryId) {}

    public function handle(SyncRepositoryPullRequestsAction $action): void
    {
        $repository = Repository::query()->find($this->repositoryId);

        if ($repository === null) {
            return;
        }

        $connection = $this->resolveConnection($repository);

        if ($connection === null) {
            Log::warning('GitHub PRs sync skipped — no connection', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
            ]);
            $this->markFailed($repository);

            return;
        }

        $repository->forceFill([
            'prs_sync_status' => RepositorySyncStatus::Syncing->value,
        ])->save();

        try {
            $action->execute($repository, new GitHubClient($connection));

            $repository->forceFill([
                'prs_sync_status' => RepositorySyncStatus::Synced->value,
                'prs_synced_at' => now(),
            ])->save();
        } catch (GitHubApiException $e) {
            if ($e->isUnauthorized()) {
                $this->expireConnection($connection);
            }

            Log::warning('GitHub PRs sync failed', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
                'status' => $e->statusCode,
                'message' => $e->getMessage(),
            ]);

            $this->markFailed($repository);
        } catch (Throwable $e) {
            Log::error('GitHub PRs sync errored', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->markFailed($repository);
        }
    }

    private function resolveConnection(Repository $repository): ?GithubConnection
    {
        $repository->loadMissing('project.owner.githubConnection');

        return $repository->project?->owner?->githubConnection;
    }

    /**
     * Flip to `failed`. We deliberately do NOT update `prs_synced_at`
     * — that column means "last successful sync" and feeds the
     * Repository PRs tab's "Last sync N min ago" indicator.
     */
    private function markFailed(Repository $repository): void
    {
        $repository->forceFill([
            'prs_sync_status' => RepositorySyncStatus::Failed->value,
        ])->save();
    }

    private function expireConnection(GithubConnection $connection): void
    {
        $connection->forceFill([
            'access_token' => '',
            'expires_at' => now(),
        ])->save();
    }
}
