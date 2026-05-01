<?php

use App\Domain\Monitoring\Jobs\DispatchDueWebsiteChecksJob;
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
