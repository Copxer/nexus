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
 * Lifecycle (spec 037 — gained §18 retry semantics):
 *   pending → syncing → synced (happy path)
 *   pending → syncing → unauthorized (401 — terminal; no retry)
 *   pending → syncing → rate_limited (transient; release until reset)
 *   pending → syncing → ... → failed (caught transient throwable after
 *                                     `$tries` exhausted)
 *
 * Retry matrix (spec 037, §18.3):
 *   - `$tries = 3` for transient failures (5xx, timeouts, unknown).
 *   - Backoff is exponential: 1 min, 5 min, 15 min between attempts.
 *   - 401 short-circuits (token won't fix itself) — terminal.
 *   - 429 / rate-limited 403 → `release()` until `X-RateLimit-Reset`
 *     without consuming a retry attempt. The job re-runs after
 *     GitHub's stated window.
 *   - `failed()` runs after all tries are exhausted; persists the
 *     terminal status (typically `failed`, occasionally `unauthorized`
 *     for 401 reached via an out-of-band path).
 *
 * On 401 we additionally clear the connection's `access_token` and zero
 * out `expires_at` so spec-013's Settings card surfaces the Reconnect
 * CTA.
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

    /** Spec 037 — 3 attempts on transient failures. */
    public int $tries = 3;

    public function __construct(public readonly int $repositoryId) {}

    /** Spec 037 — 1 min / 5 min / 15 min exponential backoff. */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

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
            // Spec 037 — branch §18.2 error vocabulary.
            //
            // 401 is terminal: the token won't self-heal across retries
            // and burning two more attempts on a guaranteed 401 just
            // delays the visible "Reconnect" CTA. Mark + return.
            if ($e->isUnauthorized()) {
                $this->expireConnection($connection);

                Log::warning('GitHub issues sync — unauthorized', [
                    'repository_id' => $repository->id,
                    'full_name' => $repository->full_name,
                ]);

                $this->markUnauthorized($repository, $e->getMessage());

                return;
            }

            // Rate-limited: release back to the queue with a delay
            // calibrated to GitHub's `X-RateLimit-Reset`. `release()`
            // does NOT consume a `$tries` attempt — the next run is a
            // fresh first attempt against a healed quota.
            if ($e->wasRateLimited()) {
                $delay = max($e->secondsUntilReset(), 60);
                // Hard cap so a misbehaving reset header can't park the
                // job for hours.
                $delay = min($delay, 3600);

                Log::info('GitHub issues sync — rate-limited; releasing', [
                    'repository_id' => $repository->id,
                    'full_name' => $repository->full_name,
                    'release_seconds' => $delay,
                ]);

                $this->markRateLimited($repository, $e->getMessage());
                $this->release($delay);

                return;
            }

            Log::warning('GitHub issues sync — transient API failure', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
                'status' => $e->statusCode,
                'attempt' => $this->attempts(),
                'message' => $e->getMessage(),
            ]);

            // Other API errors (5xx, schema drift, etc.) — re-throw so
            // the retry pipeline runs the configured `$backoff`. After
            // `$tries` is exhausted, `failed()` persists the terminal
            // `Failed` status.
            throw $e;
        } catch (Throwable $e) {
            Log::error('GitHub issues sync — unexpected error', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
                'exception' => $e::class,
                'attempt' => $this->attempts(),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Spec 037 — terminal-failure handler. Laravel calls this once the
     * retry pipeline has exhausted `$tries`. The 401 + rate-limited
     * paths short-circuit before this fires; the only way in is a
     * persistent transient failure (5xx, timeout, etc.) that survived
     * all three attempts.
     */
    public function failed(Throwable $e): void
    {
        $repository = Repository::query()->find($this->repositoryId);

        if ($repository === null) {
            return;
        }

        // Defensive: if the failure path delivers a 401 we somehow
        // missed in `handle()` (eg. a Throwable wrapped over a
        // GitHubApiException), still mark `Unauthorized` so the UI
        // shows the right CTA.
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

    private function markRateLimited(Repository $repository, ?string $reason = null): void
    {
        $repository->forceFill([
            'issues_sync_status' => RepositorySyncStatus::RateLimited->value,
            'issues_sync_error' => $reason !== null ? Str::limit($reason, 500, '…') : null,
            // Don't stamp `issues_sync_failed_at` — rate-limit isn't a
            // failure, just a deferral. The next run will overwrite
            // the status if it succeeds.
        ])->save();
    }

    private function markUnauthorized(Repository $repository, ?string $reason = null): void
    {
        $repository->forceFill([
            'issues_sync_status' => RepositorySyncStatus::Unauthorized->value,
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
