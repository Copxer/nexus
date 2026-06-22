<?php

namespace App\Domain\Observability\Queries;

use App\Domain\Observability\SystemHealthThresholds;
use App\Enums\WebhookDeliveryStatus;
use App\Models\GithubRateLimitSnapshot;
use App\Models\WebhookDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Spec 038 — read-side aggregate for the Settings system-health
 * card + the `EvaluateSystemHealthJob`'s threshold checks.
 *
 * Four signals, each one independent + computed against live
 * tables (no persisted metrics store except the GitHub rate-limit
 * snapshot, which lives in its own small table fed by
 * `CheckGitHubRateLimitJob`):
 *
 *   - **Queue.** Pending job count (from `jobs` table) + failed
 *     count in last 5 min (from `failed_jobs.failed_at`).
 *   - **Webhooks.** Deliveries received in last 5 min + failures
 *     in the same window. Failure rate stays `null` when sample
 *     size is below the warning floor (the evaluator's
 *     `WEBHOOK_MIN_SAMPLE`) to avoid noise on a quiet account.
 *   - **GitHub rate-limit.** Latest snapshot across all users.
 *     `null` remaining means we haven't polled yet (fresh
 *     install) — the card shows "—" and the evaluator stays
 *     quiet.
 *   - **Agent auth.** Count of `agent.auth.failure` activity
 *     events in last 5 min. Captured by `AuthenticateAgent`
 *     middleware on every rejected request.
 *
 * Status tones are computed against the same thresholds the
 * evaluator uses — but the query is pure and threshold-agnostic
 * (the evaluator imports the constants). Tones here just power
 * the card colour without re-deriving the alerting decision.
 */
class GetSystemHealthQuery
{
    public const WINDOW_MINUTES = 5;

    public function execute(): array
    {
        $windowStart = Carbon::now()->subMinutes(self::WINDOW_MINUTES);

        return [
            'queue' => $this->queueSlice($windowStart),
            'webhooks' => $this->webhookSlice($windowStart),
            'github_rate_limit' => $this->githubRateLimitSlice(),
            'agent_auth' => $this->agentAuthSlice($windowStart),
        ];
    }

    /**
     * @return array{pending: int, failed_5m: int, status: 'success'|'warning'|'danger'|'muted'}
     */
    private function queueSlice(Carbon $windowStart): array
    {
        $pending = DB::table('jobs')->count();
        $failed5m = DB::table('failed_jobs')
            ->where('failed_at', '>', $windowStart)
            ->count();

        return [
            'pending' => $pending,
            'failed_5m' => $failed5m,
            'status' => $this->queueStatus($pending, $failed5m),
        ];
    }

    /**
     * @return array{
     *     deliveries_5m: int,
     *     failures_5m: int,
     *     failure_rate_percent: float|null,
     *     status: 'success'|'warning'|'danger'|'muted',
     * }
     */
    private function webhookSlice(Carbon $windowStart): array
    {
        $total = WebhookDelivery::query()
            ->where('received_at', '>', $windowStart)
            ->count();

        $failed = WebhookDelivery::query()
            ->where('received_at', '>', $windowStart)
            ->where('status', WebhookDeliveryStatus::Failed->value)
            ->count();

        // Below the min-sample floor, don't surface a percentage —
        // 1 of 1 failed reads as 100% but isn't a signal.
        $rate = $total >= SystemHealthThresholds::WEBHOOK_MIN_SAMPLE
            ? round(($failed / $total) * 100, 2)
            : null;

        return [
            'deliveries_5m' => $total,
            'failures_5m' => $failed,
            'failure_rate_percent' => $rate,
            'status' => $this->webhookStatus($total, $rate),
        ];
    }

    /**
     * @return array{
     *     remaining: int|null,
     *     reset_at_iso: string|null,
     *     status: 'success'|'warning'|'danger'|'muted',
     * }
     */
    private function githubRateLimitSlice(): array
    {
        $snapshot = GithubRateLimitSnapshot::query()
            ->latest('recorded_at')
            ->first();

        if ($snapshot === null) {
            return [
                'remaining' => null,
                'reset_at_iso' => null,
                'status' => 'muted',
            ];
        }

        return [
            'remaining' => $snapshot->remaining,
            'reset_at_iso' => $snapshot->reset_at?->toIso8601String(),
            'status' => $this->githubRateStatus($snapshot->remaining),
        ];
    }

    /**
     * @return array{failures_5m: int, status: 'success'|'warning'|'danger'|'muted'}
     */
    private function agentAuthSlice(Carbon $windowStart): array
    {
        $failures = DB::table('activity_events')
            ->where('event_type', 'agent.auth.failure')
            ->where('occurred_at', '>', $windowStart)
            ->count();

        return [
            'failures_5m' => $failures,
            'status' => $this->agentAuthStatus($failures),
        ];
    }

    /** @return 'success'|'warning'|'danger'|'muted' */
    private function queueStatus(int $pending, int $failed5m): string
    {
        if (
            $pending >= SystemHealthThresholds::QUEUE_BACKLOG_CRITICAL
            || $failed5m >= SystemHealthThresholds::QUEUE_FAILURES_5M_CRIT
        ) {
            return 'danger';
        }
        if (
            $pending >= SystemHealthThresholds::QUEUE_BACKLOG_WARNING
            || $failed5m >= SystemHealthThresholds::QUEUE_FAILURES_5M_WARN
        ) {
            return 'warning';
        }

        return 'success';
    }

    /** @return 'success'|'warning'|'danger'|'muted' */
    private function webhookStatus(int $total, ?float $rate): string
    {
        if ($rate === null) {
            // Either no traffic or below the sample floor — quiet.
            return $total === 0 ? 'muted' : 'success';
        }
        if ($rate >= SystemHealthThresholds::WEBHOOK_FAILRATE_CRIT_PCT) {
            return 'danger';
        }
        if ($rate >= SystemHealthThresholds::WEBHOOK_FAILRATE_WARN_PCT) {
            return 'warning';
        }

        return 'success';
    }

    /** @return 'success'|'warning'|'danger'|'muted' */
    private function githubRateStatus(?int $remaining): string
    {
        if ($remaining === null) {
            return 'muted';
        }
        if ($remaining < SystemHealthThresholds::GITHUB_RATE_REMAINING_CRIT) {
            return 'danger';
        }
        if ($remaining < SystemHealthThresholds::GITHUB_RATE_REMAINING_WARN) {
            return 'warning';
        }

        return 'success';
    }

    /** @return 'success'|'warning'|'danger'|'muted' */
    private function agentAuthStatus(int $failures): string
    {
        if ($failures >= SystemHealthThresholds::AGENT_AUTH_FAILURES_5M_CRIT) {
            return 'danger';
        }
        if ($failures >= SystemHealthThresholds::AGENT_AUTH_FAILURES_5M_WARN) {
            return 'warning';
        }

        return 'success';
    }
}
