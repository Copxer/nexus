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
use Illuminate\Support\Str;
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

    /** Spec 037 — 3 attempts on transient failures. */
    public int $tries = 3;

    public function __construct(public readonly int $repositoryId) {}

    /** Spec 037 — 1 min / 5 min / 15 min exponential backoff. */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

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
            $this->markFailed($repository, 'No GitHub connection — reconnect in Settings to sync pull requests.');

            return;
        }

        $repository->forceFill([
            'prs_sync_status' => RepositorySyncStatus::Syncing->value,
            'prs_sync_error' => null,
            'prs_sync_failed_at' => null,
        ])->save();

        try {
            $action->execute($repository, new GitHubClient($connection));

            $repository->forceFill([
                'prs_sync_status' => RepositorySyncStatus::Synced->value,
                'prs_synced_at' => now(),
                'prs_sync_error' => null,
                'prs_sync_failed_at' => null,
            ])->save();
        } catch (GitHubApiException $e) {
            // Spec 037 — see SyncRepositoryIssuesJob for the rationale.
            if ($e->isUnauthorized()) {
                $this->expireConnection($connection);

                Log::warning('GitHub PRs sync — unauthorized', [
                    'repository_id' => $repository->id,
                    'full_name' => $repository->full_name,
                ]);

                $this->markUnauthorized($repository, $e->getMessage());

                return;
            }

            // Rate-limited: see SyncRepositoryIssuesJob for the
            // `release()` semantics. Persistent rate-limiting exhausts
            // `$tries` and falls through to `failed()`.
            if ($e->wasRateLimited()) {
                $delay = max($e->secondsUntilReset(), 60);
                $delay = min($delay, 3600);

                Log::info('GitHub PRs sync — rate-limited; releasing', [
                    'repository_id' => $repository->id,
                    'full_name' => $repository->full_name,
                    'release_seconds' => $delay,
                ]);

                $this->markRateLimited($repository, $e->getMessage());
                $this->release($delay);

                return;
            }

            Log::warning('GitHub PRs sync — transient API failure', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
                'status' => $e->statusCode,
                'attempt' => $this->attempts(),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        } catch (Throwable $e) {
            Log::error('GitHub PRs sync — unexpected error', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
                'exception' => $e::class,
                'attempt' => $this->attempts(),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /** Spec 037 — terminal-failure handler after `$tries` exhausted. */
    public function failed(Throwable $e): void
    {
        $repository = Repository::query()->find($this->repositoryId);

        if ($repository === null) {
            return;
        }

        if ($e instanceof GitHubApiException && $e->isUnauthorized()) {
            $this->markUnauthorized($repository, $e->getMessage());

            return;
        }

        $reason = $e->getMessage() !== '' ? $e->getMessage() : $e::class;

        $this->markFailed($repository, $reason);
    }

    private function resolveConnection(Repository $repository): ?GithubConnection
    {
        $repository->loadMissing('project.owner.githubConnection');

        return $repository->project?->owner?->githubConnection;
    }

    /**
     * Flip to `failed` and persist the failure reason for the UI.
     *
     * We deliberately do NOT update `prs_synced_at` — that column means
     * "last successful sync" and feeds the Repository PRs tab's
     * "Last sync N min ago" indicator.
     *
     * `prs_sync_error` is hard-capped at 500 chars; the full message is
     * still in Pail at the call site.
     */
    private function markFailed(Repository $repository, ?string $reason = null): void
    {
        $repository->forceFill([
            'prs_sync_status' => RepositorySyncStatus::Failed->value,
            'prs_sync_error' => $reason !== null ? Str::limit($reason, 500, '…') : null,
            'prs_sync_failed_at' => now(),
        ])->save();
    }

    private function markRateLimited(Repository $repository, ?string $reason = null): void
    {
        $repository->forceFill([
            'prs_sync_status' => RepositorySyncStatus::RateLimited->value,
            'prs_sync_error' => $reason !== null ? Str::limit($reason, 500, '…') : null,
        ])->save();
    }

    private function markUnauthorized(Repository $repository, ?string $reason = null): void
    {
        $repository->forceFill([
            'prs_sync_status' => RepositorySyncStatus::Unauthorized->value,
            'prs_sync_error' => $reason !== null ? Str::limit($reason, 500, '…') : null,
            'prs_sync_failed_at' => now(),
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
