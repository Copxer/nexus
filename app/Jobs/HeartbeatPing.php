<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Tiny heartbeat job that proves the queue → Horizon → log path is alive.
 *
 * It exists for spec 009 wiring verification only. Real domain jobs
 * (GitHub sync, Docker polling, alert evaluation, etc.) ship in their
 * own phase specs.
 */
class HeartbeatPing implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        Log::info('Heartbeat ping at '.now()->toIso8601String());
    }
}
