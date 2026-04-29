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
            $this->markFailed($repository);

            return;
        }

        $repository->forceFill([
            'sync_status' => RepositorySyncStatus::Syncing->value,
        ])->save();

        $metadataSynced = false;

        try {
            $payload = (new GitHubClient($connection))
                ->fetchRepository($repository->full_name);

            $this->applyPayload($repository, $payload);

            $repository->forceFill([
                'sync_status' => RepositorySyncStatus::Synced->value,
                'last_synced_at' => now(),
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

            $this->markFailed($repository);
        } catch (Throwable $e) {
            Log::error('GitHub repository sync errored', [
                'repository_id' => $repository->id,
                'full_name' => $repository->full_name,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->markFailed($repository);
        }

        // Chain spec 015's issues sync. Dispatch lives outside the
        // try/catch above so a transient queue failure here doesn't
        // flip a freshly-synced repo back to `failed` — issues sync
        // has its own independent status on the repo row.
        if ($metadataSynced) {
            try {
                SyncRepositoryIssuesJob::dispatch($repository->id);
            } catch (Throwable $e) {
                Log::warning('GitHub issues sync dispatch failed', [
                    'repository_id' => $repository->id,
                    'full_name' => $repository->full_name,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
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
     * Flip the row to `failed`. We deliberately do NOT update
     * `last_synced_at` — that column means "last successful sync" and
     * is what the Settings card surfaces as "Last sync N min ago". A
     * failed run keeps the previous successful timestamp (or null if
     * never synced).
     *
     * Failure reason is logged at the call site (see the catches above)
     * — spec 014's schema doesn't carry a `sync_error` column yet. A
     * future spec that surfaces error messages on the Repository page
     * will add the column + thread the reason through here.
     */
    private function markFailed(Repository $repository): void
    {
        $repository->forceFill([
            'sync_status' => RepositorySyncStatus::Failed->value,
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
