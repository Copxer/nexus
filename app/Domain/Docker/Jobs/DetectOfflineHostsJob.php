<?php

namespace App\Domain\Docker\Jobs;

use App\Domain\Docker\Actions\DetectOfflineHostsAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduler-bound offline detector (spec 029). Bound in
 * `routes/console.php` via
 * `Schedule::job(...)->everyMinute()->withoutOverlapping()` next to
 * the website-check dispatcher. Delegates the actual work to
 * `DetectOfflineHostsAction` — the job stays thin so the action is
 * easily unit-testable without spinning up the queue.
 */
class DetectOfflineHostsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Single attempt; the next every-minute tick is the retry path. */
    public int $tries = 1;

    public function handle(DetectOfflineHostsAction $detect): void
    {
        $detect->execute();
    }
}
