<?php

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
