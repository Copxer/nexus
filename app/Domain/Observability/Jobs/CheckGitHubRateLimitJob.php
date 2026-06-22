<?php

namespace App\Domain\Observability\Jobs;

use App\Domain\GitHub\Exceptions\GitHubApiException;
use App\Domain\GitHub\Services\GitHubClient;
use App\Models\GithubConnection;
use App\Models\GithubRateLimitSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Spec 038 — periodic poll of GitHub's `/rate_limit` endpoint for
 * each user with a non-expired connection. Persists one snapshot
 * per user per tick into `github_rate_limit_snapshots`; the
 * Settings system-health card + `EvaluateSystemHealthJob` read
 * the latest row.
 *
 * 401 responses don't crash the loop — the user's connection has
 * either expired or been revoked, and the next sync job's own
 * 401 path will mark the connection expired (spec 037). We just
 * skip and continue.
 *
 * `$tries = 1` — the every-10-minute schedule is the retry path.
 * Cost is one HTTP call per connected user per 10 minutes, which
 * stays well under any reasonable per-token quota.
 */
class CheckGitHubRateLimitJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $connections = GithubConnection::query()
            ->where(function ($q): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            })
            ->where('access_token', '!=', '')
            ->get();

        if ($connections->isEmpty()) {
            return;
        }

        foreach ($connections as $connection) {
            try {
                $rate = (new GitHubClient($connection))->fetchRateLimit();
            } catch (GitHubApiException $e) {
                Log::warning('GitHub rate-limit poll failed', [
                    'user_id' => $connection->user_id,
                    'status' => $e->statusCode,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            GithubRateLimitSnapshot::query()->create([
                'user_id' => $connection->user_id,
                'remaining' => $rate['remaining'],
                'limit' => $rate['limit'],
                'reset_at' => Carbon::createFromTimestamp($rate['reset']),
                'recorded_at' => Carbon::now(),
            ]);
        }
    }
}
