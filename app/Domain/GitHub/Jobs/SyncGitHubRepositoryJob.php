<?php

namespace App\Domain\GitHub\Jobs;

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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Refresh a single Repository row's metadata from GitHub.
 *
 * Lifecycle:
 *   pending → syncing → synced (happy path)
 *   pending → syncing → failed (caught GitHubApiException or Throwable)
 *
 * On 401 (token revoked / expired) we additionally clear the
 * connection's `access_token` and zero out `expires_at` so spec-013's
 * Settings card surfaces the Reconnect CTA. The job itself still ends
 * in `failed` status — the user re-imports after re-auth.
 *
 * Idempotent: a replay against an already-synced row will simply
 * refresh it.
 */
class SyncGitHubRepositoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Number of retry attempts before the queue gives up. */
    public int $tries = 1;

    public function __construct(public readonly int $repositoryId) {}

    public function handle(): void
    {
        $repository = Repository::query()->find($this->repositoryId);

        if ($repository === null) {
            // Row deleted between dispatch and run. No-op.
            return;
        }

        $connection = $this->resolveConnection($repository);

        if ($connection === null) {
            Log::warning('GitHub repository sync skipped — no connection', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
            ]);
            $this->markFailed($repository, 'No GitHub connection — reconnect in Settings to sync this repository.');

            return;
        }

        $repository->forceFill([
            'sync_status' => RepositorySyncStatus::Syncing->value,
            'sync_error' => null,
            'sync_failed_at' => null,
        ])->save();

        $metadataSynced = false;

        try {
            $payload = (new GitHubClient($connection))
                ->fetchRepository($repository->full_name);

            $this->applyPayload($repository, $payload);

            $repository->forceFill([
                'sync_status' => RepositorySyncStatus::Synced->value,
                'last_synced_at' => now(),
                'sync_error' => null,
                'sync_failed_at' => null,
            ])->save();

            $metadataSynced = true;
        } catch (GitHubApiException $e) {
            if ($e->isUnauthorized()) {
                $this->expireConnection($connection);
            }

            Log::warning('GitHub repository sync failed', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
                'status' => $e->statusCode,
                'message' => $e->getMessage(),
            ]);

            $this->markFailed($repository, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('GitHub repository sync errored', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->markFailed($repository, $e->getMessage() !== '' ? $e->getMessage() : $e::class);
        }

        // Chain spec 015's issues sync, spec 016's PRs sync, and spec
        // 020's workflow runs sync. All three dispatches sit outside
        // the try/catch above so a transient queue failure here doesn't
        // flip a freshly-synced repo back to `failed` — each child sync
        // carries its own independent status on the repo row.
        if ($metadataSynced) {
            $this->dispatchChildSync(
                fn () => SyncRepositoryIssuesJob::dispatch($repository->id),
                $repository,
                'issues',
            );

            $this->dispatchChildSync(
                fn () => SyncRepositoryPullRequestsJob::dispatch($repository->id),
                $repository,
                'pulls',
            );

            $this->dispatchChildSync(
                fn () => SyncRepositoryWorkflowRunsJob::dispatch($repository->id),
                $repository,
                'workflow runs',
            );
        }
    }

    /**
     * Wrap a child-sync dispatch in its own try/catch so a queue-driver
     * blip in one chained sync doesn't suppress the other. Logs the
     * failure; the user can recover via the per-tab "Run sync" buttons.
     */
    private function dispatchChildSync(
        callable $dispatcher,
        Repository $repository,
        string $kind,
    ): void {
        try {
            $dispatcher();
        } catch (Throwable $e) {
            Log::warning("GitHub {$kind} sync dispatch failed", [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The connection to use for this sync. Phase 1 ties repos to projects
     * and projects to a single owner user; the owner's connection is the
     * source of truth for sync. Multi-team scoping ships later.
     */
    private function resolveConnection(Repository $repository): ?GithubConnection
    {
        $project = $repository->project;

        if ($project === null) {
            return null;
        }

        $project->loadMissing('owner');
        $owner = $project->owner;

        if ($owner === null) {
            return null;
        }

        $owner->loadMissing('githubConnection');

        return $owner->githubConnection;
    }

    /** Mirror GitHub's payload onto the local Repository row. */
    private function applyPayload(Repository $repository, array $payload): void
    {
        $repository->forceFill([
            'provider_id' => isset($payload['id']) ? (string) $payload['id'] : $repository->provider_id,
            'description' => $payload['description'] ?? $repository->description,
            'default_branch' => $payload['default_branch'] ?? $repository->default_branch,
            'visibility' => $payload['visibility'] ?? ($payload['private'] ?? false ? 'private' : 'public'),
            'language' => $payload['language'] ?? $repository->language,
            'stars_count' => (int) ($payload['stargazers_count'] ?? 0),
            'forks_count' => (int) ($payload['forks_count'] ?? 0),
            'open_issues_count' => (int) ($payload['open_issues_count'] ?? 0),
            'last_pushed_at' => $this->parseTimestamp($payload['pushed_at'] ?? null),
            'html_url' => $payload['html_url'] ?? $repository->html_url,
        ]);
    }

    /**
     * Flip the row to `failed` and persist the failure reason for the UI.
     *
     * We deliberately do NOT update `last_synced_at` — that column means
     * "last successful sync" and is what the Settings card surfaces as
     * "Last sync N min ago". A failed run keeps the previous successful
     * timestamp (or null if never synced).
     *
     * `sync_error` is hard-capped at 500 chars so a runaway exception
     * message can't bloat the row. The full message is still in Pail at
     * the call site.
     */
    private function markFailed(Repository $repository, ?string $reason = null): void
    {
        $repository->forceFill([
            'sync_status' => RepositorySyncStatus::Failed->value,
            'sync_error' => $reason !== null ? Str::limit($reason, 500, '…') : null,
            'sync_failed_at' => now(),
        ])->save();
    }

    /**
     * Clear the connection's access token + zero its expiry. Spec 013's
     * `isAccessTokenValid()` returns `true` when `expires_at` is null,
     * so we set it to "now" instead of null to flip the connection into
     * the expired branch and surface the Reconnect CTA.
     */
    private function expireConnection(GithubConnection $connection): void
    {
        $connection->forceFill([
            'access_token' => '',
            'expires_at' => now(),
        ])->save();
    }

    private function parseTimestamp(?string $iso): ?Carbon
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        return Carbon::parse($iso);
    }
}
