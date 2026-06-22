<?php

namespace App\Domain\Observability\Jobs;

use App\Domain\Alerts\Actions\ResolveAlertAction;
use App\Domain\Alerts\Actions\TriggerAlertAction;
use App\Domain\Observability\Queries\GetSystemHealthQuery;
use App\Domain\Observability\SystemHealthThresholds;
use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Spec 038 — every-minute evaluator that turns the four
 * `GetSystemHealthQuery` signals into idempotent internal alerts.
 * Reuses spec 030's `TriggerAlertAction` (`AlertSource::System`)
 * + `ResolveAlertAction` for the close-on-recovery flow.
 *
 * Idempotency is automatic: `TriggerAlertAction` returns the
 * existing open alert if one already matches `(source, source_id,
 * type)`, so a sustained breach produces one alert, not a stream.
 *
 * Thresholds are named constants on this class so future tuning
 * (or a polish-spec UI for user-tunable weights) has one place to
 * change. Status mapping in `GetSystemHealthQuery` mirrors the
 * same thresholds — both layers can evolve together but the
 * evaluator owns the alerting decision.
 */
class EvaluateSystemHealthJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    // Spec 038 — thresholds live in `SystemHealthThresholds` so the
    // query (decides what tone to render) and the job (decides what
    // alert to fire) read the same numbers.

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new WithoutOverlapping('observability:evaluate-system-health')];
    }

    public function handle(
        GetSystemHealthQuery $query,
        TriggerAlertAction $trigger,
        ResolveAlertAction $resolve,
    ): void {
        $health = $query->execute();

        $this->evaluateQueue($health['queue'], $trigger, $resolve);
        $this->evaluateWebhooks($health['webhooks'], $trigger, $resolve);
        $this->evaluateGithubRateLimit($health['github_rate_limit'], $trigger, $resolve);
        $this->evaluateAgentAuth($health['agent_auth'], $trigger, $resolve);
    }

    /**
     * @param  array{pending: int, failed_5m: int, status: string}  $slice
     */
    private function evaluateQueue(array $slice, TriggerAlertAction $trigger, ResolveAlertAction $resolve): void
    {
        $severity = match (true) {
            $slice['pending'] >= SystemHealthThresholds::QUEUE_BACKLOG_CRITICAL,
            $slice['failed_5m'] >= SystemHealthThresholds::QUEUE_FAILURES_5M_CRIT => AlertSeverity::Critical,
            $slice['pending'] >= SystemHealthThresholds::QUEUE_BACKLOG_WARNING,
            $slice['failed_5m'] >= SystemHealthThresholds::QUEUE_FAILURES_5M_WARN => AlertSeverity::Warning,
            default => null,
        };

        if ($severity === null) {
            $this->resolveType($resolve, 'queue.backlog_high');

            return;
        }

        $trigger->execute([
            'project_id' => null,
            'source' => AlertSource::System,
            'source_id' => null,
            'type' => 'queue.backlog_high',
            'severity' => $severity,
            'title' => 'Queue backlog high',
            'description' => "Pending jobs: {$slice['pending']}, failures in last 5m: {$slice['failed_5m']}.",
            'metadata' => $slice,
        ]);
    }

    /**
     * @param  array{deliveries_5m: int, failures_5m: int, failure_rate_percent: float|null, status: string}  $slice
     */
    private function evaluateWebhooks(array $slice, TriggerAlertAction $trigger, ResolveAlertAction $resolve): void
    {
        // Don't fire on a quiet account — `failure_rate_percent` is
        // null when sample size is below the floor, and the query
        // returns `success`/`muted` accordingly. The evaluator's job
        // is to act only on a real signal.
        if (
            $slice['deliveries_5m'] < SystemHealthThresholds::WEBHOOK_MIN_SAMPLE
            || $slice['failure_rate_percent'] === null
        ) {
            $this->resolveType($resolve, 'webhook.failure_rate_high');

            return;
        }

        $rate = $slice['failure_rate_percent'];

        $severity = match (true) {
            $rate >= SystemHealthThresholds::WEBHOOK_FAILRATE_CRIT_PCT => AlertSeverity::Critical,
            $rate >= SystemHealthThresholds::WEBHOOK_FAILRATE_WARN_PCT => AlertSeverity::Warning,
            default => null,
        };

        if ($severity === null) {
            $this->resolveType($resolve, 'webhook.failure_rate_high');

            return;
        }

        $trigger->execute([
            'project_id' => null,
            'source' => AlertSource::System,
            'source_id' => null,
            'type' => 'webhook.failure_rate_high',
            'severity' => $severity,
            'title' => 'Webhook failure rate high',
            'description' => "{$slice['failures_5m']} / {$slice['deliveries_5m']} deliveries failed in last 5m ({$rate}%).",
            'metadata' => $slice,
        ]);
    }

    /**
     * @param  array{remaining: int|null, reset_at_iso: string|null, status: string}  $slice
     */
    private function evaluateGithubRateLimit(array $slice, TriggerAlertAction $trigger, ResolveAlertAction $resolve): void
    {
        if ($slice['remaining'] === null) {
            // No snapshot yet — fresh install or no connected users.
            // The poller will produce one within 10 min; stay quiet.
            $this->resolveType($resolve, 'github.rate_limit_low');

            return;
        }

        $severity = match (true) {
            $slice['remaining'] < SystemHealthThresholds::GITHUB_RATE_REMAINING_CRIT => AlertSeverity::Critical,
            $slice['remaining'] < SystemHealthThresholds::GITHUB_RATE_REMAINING_WARN => AlertSeverity::Warning,
            default => null,
        };

        if ($severity === null) {
            $this->resolveType($resolve, 'github.rate_limit_low');

            return;
        }

        $trigger->execute([
            'project_id' => null,
            'source' => AlertSource::System,
            'source_id' => null,
            'type' => 'github.rate_limit_low',
            'severity' => $severity,
            'title' => 'GitHub rate limit low',
            'description' => "{$slice['remaining']} requests remaining (reset at {$slice['reset_at_iso']}).",
            'metadata' => $slice,
        ]);
    }

    /**
     * @param  array{failures_5m: int, status: string}  $slice
     */
    private function evaluateAgentAuth(array $slice, TriggerAlertAction $trigger, ResolveAlertAction $resolve): void
    {
        $severity = match (true) {
            $slice['failures_5m'] >= SystemHealthThresholds::AGENT_AUTH_FAILURES_5M_CRIT => AlertSeverity::Critical,
            $slice['failures_5m'] >= SystemHealthThresholds::AGENT_AUTH_FAILURES_5M_WARN => AlertSeverity::Warning,
            default => null,
        };

        if ($severity === null) {
            $this->resolveType($resolve, 'agent.auth_failures_high');

            return;
        }

        $trigger->execute([
            'project_id' => null,
            'source' => AlertSource::System,
            'source_id' => null,
            'type' => 'agent.auth_failures_high',
            'severity' => $severity,
            'title' => 'Agent auth failures high',
            'description' => "{$slice['failures_5m']} rejected agent requests in last 5m.",
            'metadata' => $slice,
        ]);
    }

    private function resolveType(ResolveAlertAction $resolve, string $type): void
    {
        $resolve->execute([
            'source' => AlertSource::System,
            'source_id' => null,
            'type' => $type,
        ]);
    }
}
