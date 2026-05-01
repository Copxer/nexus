<?php

namespace App\Domain\GitHub\Jobs;

use App\Domain\GitHub\Actions\SyncRepositoryWorkflowRunsAction;
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
use Illuminate\Support\Str;
use Throwable;

/**
 * Sync a single Repository's GitHub Actions workflow runs into
 * `workflow_runs`. Parallel to spec 015's `SyncRepositoryIssuesJob`
 * and spec 016's `SyncRepositoryPullRequestsJob` — same lifecycle,
 * same 401 plumbing, same preserve-on-failure timestamp guarantee,
 * same error-clearing rules.
 *
 *   pending → syncing → synced (happy path)
 *   pending → syncing → failed (caught GitHubApiException or Throwable)
 *
 * On 401 we additionally clear the connection's `access_token` and
 * zero out `expires_at` so spec-013's Settings card surfaces the
 * Reconnect CTA.
 *
 * `workflow_runs_synced_at` is only stamped on success — the "Last
 * sync" UI must mean "last successful sync." `workflow_runs_sync
 * _error` + `workflow_runs_sync_failed_at` are written on failure
 * (capped at 500 chars) and cleared on a fresh syncing flip + on
 * success, parallel to the rest of the sync flows.
 */
class SyncRepositoryWorkflowRunsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $repositoryId) {}

    public function handle(SyncRepositoryWorkflowRunsAction $action): void
    {
        $repository = Repository::query()->find($this->repositoryId);

        if ($repository === null) {
            return;
        }

        $connection = $this->resolveConnection($repository);

        if ($connection === null) {
            Log::warning('GitHub workflow runs sync skipped — no connection', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
            ]);
            $this->markFailed($repository, 'No GitHub connection — reconnect in Settings to sync workflow runs.');

            return;
        }

        $repository->forceFill([
            'workflow_runs_sync_status' => RepositorySyncStatus::Syncing->value,
            'workflow_runs_sync_error' => null,
            'workflow_runs_sync_failed_at' => null,
        ])->save();

        try {
            $action->execute($repository, new GitHubClient($connection));

            $repository->forceFill([
                'workflow_runs_sync_status' => RepositorySyncStatus::Synced->value,
                'workflow_runs_synced_at' => now(),
                'workflow_runs_sync_error' => null,
                'workflow_runs_sync_failed_at' => null,
            ])->save();
        } catch (GitHubApiException $e) {
            if ($e->isUnauthorized()) {
                $this->expireConnection($connection);
            }

            Log::warning('GitHub workflow runs sync failed', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
                'status' => $e->statusCode,
                'message' => $e->getMessage(),
            ]);

            $this->markFailed($repository, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('GitHub workflow runs sync errored', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->markFailed($repository, $e->getMessage() !== '' ? $e->getMessage() : $e::class);
        }
    }

    private function resolveConnection(Repository $repository): ?GithubConnection
    {
        $repository->loadMissing('project.owner.githubConnection');

        return $repository->project?->owner?->githubConnection;
    }

    /**
     * Flip to `failed` and persist the failure reason for the UI.
     *
     * We deliberately do NOT update `workflow_runs_synced_at` — that
     * column means "last successful sync" and feeds the Repository
     * Workflow Runs tab's "Last sync N min ago" indicator.
     *
     * `workflow_runs_sync_error` is hard-capped at 500 chars; the full
     * message is still in Pail at the call site.
     */
    private function markFailed(Repository $repository, ?string $reason = null): void
    {
        $repository->forceFill([
            'workflow_runs_sync_status' => RepositorySyncStatus::Failed->value,
            'workflow_runs_sync_error' => $reason !== null ? Str::limit($reason, 500, '…') : null,
            'workflow_runs_sync_failed_at' => now(),
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
