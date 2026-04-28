<?php

namespace App\Console\Commands;

use App\Jobs\HeartbeatPing;
use Illuminate\Console\Command;

/**
 * Manual trigger for the `HeartbeatPing` queueable job. Useful for
 * verifying the queue → Horizon → log path is alive without waiting
 * for the scheduler. Invoked via `php artisan app:heartbeat`.
 */
class HeartbeatPingCommand extends Command
{
    protected $signature = 'app:heartbeat';

    protected $description = 'Dispatch a HeartbeatPing job to verify the queue → Horizon → log path.';

    public function handle(): int
    {
        HeartbeatPing::dispatch();

        $this->info('Heartbeat ping dispatched. Watch Horizon and laravel.log.');

        return self::SUCCESS;
    }
}
