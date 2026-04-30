<?php

namespace App\Domain\GitHub\Jobs;

use App\Domain\GitHub\Actions\SyncRepositoryIssuesAction;
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
 * Sync a single Repository's issues from GitHub into `github_issues`.
 *
 * Lifecycle (mirrors spec 014's repo-metadata sync):
 *   pending → syncing → synced (happy path)
 *   pending → syncing → failed (caught GitHubApiException or Throwable)
 *
 * On 401 we additionally clear the connection's `access_token` and zero
 * out `expires_at` so spec-013's Settings card surfaces the Reconnect
 * CTA. The job itself still ends in `failed` status.
 *
 * `issues_synced_at` is only stamped on success — a failed run keeps
 * the previous successful timestamp (or null if never synced) so the
 * "Last sync" UI never lies about freshness.
 *
 * Idempotent: the action upserts on `(repository_id, github_id)` so
 * replays land the same rows in the same final state.
 */
class SyncRepositoryIssuesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Single attempt; mark `failed` on any error. Retries land later. */
    public int $tries = 1;

    public function __construct(public readonly int $repositoryId) {}

    public function handle(SyncRepositoryIssuesAction $action): void
    {
        $repository = Repository::query()->find($this->repositoryId);

        if ($repository === null) {
            return;
        }

        $connection = $this->resolveConnection($repository);

        if ($connection === null) {
            Log::warning('GitHub issues sync skipped — no connection', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
            ]);
            $this->markFailed($repository, 'No GitHub connection — reconnect in Settings to sync issues.');

            return;
        }

        $repository->forceFill([
            'issues_sync_status' => RepositorySyncStatus::Syncing->value,
            'issues_sync_error' => null,
            'issues_sync_failed_at' => null,
        ])->save();

        try {
            $action->execute($repository, new GitHubClient($connection));

            $repository->forceFill([
                'issues_sync_status' => RepositorySyncStatus::Synced->value,
                'issues_synced_at' => now(),
                'issues_sync_error' => null,
                'issues_sync_failed_at' => null,
            ])->save();
        } catch (GitHubApiException $e) {
            if ($e->isUnauthorized()) {
                $this->expireConnection($connection);
            }

            Log::warning('GitHub issues sync failed', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
                'status' => $e->statusCode,
                'message' => $e->getMessage(),
            ]);

            $this->markFailed($repository, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('GitHub issues sync errored', [
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
     * We deliberately do NOT update `issues_synced_at` — that column
     * means "last successful sync" and feeds the Repository Issues tab's
     * "Last sync N min ago" indicator. A failed run keeps the previous
     * successful timestamp.
     *
     * `issues_sync_error` is hard-capped at 500 chars; the full message
     * is still in Pail at the call site.
     */
    private function markFailed(Repository $repository, ?string $reason = null): void
    {
        $repository->forceFill([
            'issues_sync_status' => RepositorySyncStatus::Failed->value,
            'issues_sync_error' => $reason !== null ? Str::limit($reason, 500, '…') : null,
            'issues_sync_failed_at' => now(),
        ])->save();
    }

    /**
     * Same plumbing as spec 014's repo-metadata sync: blank the token
     * and set `expires_at` to "now" so `isAccessTokenValid()` flips
     * false and the Settings card's Reconnect CTA renders.
     */
    private function expireConnection(GithubConnection $connection): void
    {
        $connection->forceFill([
            'access_token' => '',
            'expires_at' => now(),
        ])->save();
    }
}
