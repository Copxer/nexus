<?php

namespace App\Domain\Monitoring\Jobs;

use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduler-bound dispatcher (spec 024). Bound in `routes/console.php`
 * via `Schedule::job(...)->everyMinute()->withoutOverlapping()`.
 *
 * Loads every `Website` row, filters to "due now" in PHP (the
 * predicate `last_checked_at + check_interval_seconds <= now()` is
 * cross-DB awkward to express in raw SQL), and dispatches a per-website
 * `RunWebsiteCheckJob` for each. The probe HTTP request happens in
 * the per-website job — the dispatcher itself stays fast so a slow
 * site doesn't block the every-minute tick.
 *
 * Soft cap of 500 websites per dispatcher run keeps a runaway
 * configuration from amplifying into thousands of queued jobs in a
 * single tick. Ordered by `last_checked_at` ascending with nulls
 * first so the oldest-stale rows always land in the cap window —
 * an `orderBy('id')` would silently strand the high-id tail when
 * total > cap. Phase-1 expectation is well below the cap; revisit
 * (cursor-based pagination, distributed locks) when a real account
 * approaches it.
 */
class DispatchDueWebsiteChecksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Single attempt; the next every-minute tick is the retry path. */
    public int $tries = 1;

    /** Hard cap on websites picked up per tick. */
    private const SOFT_CAP = 500;

    public function handle(): void
    {
        $now = now();

        Website::query()
            // Never-checked rows always due → land at the head of the
            // queue. After that, oldest-stale first.
            ->orderByRaw('last_checked_at IS NULL DESC')
            ->orderBy('last_checked_at')
            ->limit(self::SOFT_CAP)
            ->get()
            ->filter(function (Website $website) use ($now) {
                if ($website->last_checked_at === null) {
                    return true;
                }

                return $website->last_checked_at
                    ->copy()
                    ->addSeconds($website->check_interval_seconds)
                    ->lessThanOrEqualTo($now);
            })
            ->each(fn (Website $website) => RunWebsiteCheckJob::dispatch($website->id));
    }
}
