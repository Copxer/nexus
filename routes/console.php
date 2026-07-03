<?php

use App\Domain\Alerts\Jobs\EvaluateAlertRulesJob;
use App\Domain\Analytics\Jobs\RecomputeAllProjectHealthScoresJob;
use App\Domain\Docker\Jobs\DetectOfflineHostsJob;
use App\Domain\Monitoring\Jobs\DispatchDueWebsiteChecksJob;
use App\Domain\Observability\Jobs\CheckGitHubRateLimitJob;
use App\Domain\Observability\Jobs\EvaluateSystemHealthJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ──────────────────────────────────────────────────────────────────────
// Phase 0 baseline schedule (spec 009).
//
// `app:heartbeat` proves the queue → Horizon → log path is alive on its
// own without manual invocation. `inspire` runs as an hourly canary so
// `schedule:list` is never empty and the scheduler exercises more than
// one entry. Real domain schedules ship with their phase specs.
// ──────────────────────────────────────────────────────────────────────
Schedule::command('app:heartbeat')->everyTenMinutes();
Schedule::command('inspire')->hourly();

// Spec 024 — every-minute dispatcher that picks website monitors whose
// configured `check_interval_seconds` has elapsed. The dispatcher itself
// stays fast (DB read + filter); per-website probes run async via
// `RunWebsiteCheckJob`. `withoutOverlapping()` guards against a slow
// dispatcher tick stacking onto the next minute. Production needs
// `php artisan schedule:work` (or cron) running.
Schedule::job(new DispatchDueWebsiteChecksJob)
    ->everyMinute()
    ->name('monitoring:dispatch-due-website-checks')
    ->withoutOverlapping();

// Spec 029 — every-minute offline detector for Docker hosts. Flips
// `online` hosts past `config('hosts.heartbeat_timeout_seconds')`
// (default 120 s) to `offline` and emits `host.offline` activity events.
// Late telemetry flows through `IngestHostTelemetryAction` and flips
// the host back to online, emitting `host.recovered`.
Schedule::job(new DetectOfflineHostsJob)
    ->everyMinute()
    ->name('hosts:detect-offline')
    ->withoutOverlapping();

// Spec 033 — every-5-minute sweep that fans out per-project health-
// score recomputes for any project with activity in the last 7 days
// or no stored score yet. The transition listener
// (`RecomputeProjectHealthOnActivity`) catches realtime moves; this
// sweep covers slow-drift signals (eg. a workflow failure aging past
// the 24h window) and the first-run backfill.
Schedule::job(new RecomputeAllProjectHealthScoresJob)
    ->everyFiveMinutes()
    ->name('analytics:recompute-health-scores')
    ->withoutOverlapping(10);

// Spec 038 — every-minute self-monitoring evaluator. Reads
// `GetSystemHealthQuery` + triggers / resolves internal alerts on
// the four §17 signals (queue / webhooks / GitHub rate / agent
// auth). `withoutOverlapping` keyed on the job middleware so a slow
// tick doesn't stack onto the next minute.
Schedule::job(new EvaluateSystemHealthJob)
    ->everyMinute()
    ->name('observability:evaluate-system-health')
    ->withoutOverlapping();

// Spec 038 — every-10-minute poll of GitHub's `/rate_limit` per
// connected user. Persists one snapshot per user; the system-health
// query reads the latest row. Bounded HTTP volume (1 call per
// connection per 10 min); stays well under any per-token quota.
Schedule::job(new CheckGitHubRateLimitJob)
    ->everyTenMinutes()
    ->name('observability:check-github-rate-limit')
    ->withoutOverlapping();

// Spec 046 — every-5-minute evaluator for user-defined metric alert
// rules. Iterates enabled `AlertRule` rows, delegates each to its
// kind's evaluator (Strategy per §6.5), and dispatches
// `TriggerAlertAction` on truth. Fired alerts ride spec 042's
// delivery layer via `AlertSource::System`. Cool-down is per-rule
// (default 30 min) so a stuck condition doesn't page every tick.
Schedule::job(new EvaluateAlertRulesJob)
    ->everyFiveMinutes()
    ->name('alerts:evaluate-rules')
    ->withoutOverlapping();
