<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Actions\RecordWebsiteCheckAction;
use App\Domain\Monitoring\Actions\RunWebsiteProbeAction;
use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Per-website async probe wrapper (spec 024). Reuses the spec-023
 * actions so the manual "Probe now" button and the scheduled run
 * land on the same persistence path — no behavioural drift.
 *
 * Loads the row inside `handle()` rather than carrying the model
 * through `SerializesModels`: the dispatcher could pick up a website
 * that's then deleted before the worker dequeues, and a no-op return
 * is cleaner than a `ModelNotFoundException` in the failure log.
 *
 * `tries = 1`. A failed probe (transport error / timeout) is *itself*
 * a recorded outcome — `RecordWebsiteCheckAction` writes the Error
 * row. Job-level retries would either double-record the same probe or
 * mask transient failures the user wants to see.
 */
class RunWebsiteCheckJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $websiteId) {}

    public function handle(
        RunWebsiteProbeAction $probe,
        RecordWebsiteCheckAction $record,
    ): void {
        $website = Website::query()->find($this->websiteId);

        if ($website === null) {
            return;
        }

        $result = $probe->execute($website);
        $record->execute($website, $result);
    }
}
