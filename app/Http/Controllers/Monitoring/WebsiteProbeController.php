<?php

namespace App\Http\Controllers\Monitoring;

use App\Domain\Monitoring\Actions\RecordWebsiteCheckAction;
use App\Domain\Monitoring\Actions\RunWebsiteProbeAction;
use App\Http\Controllers\Controller;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;

/**
 * Manual "Probe now" button on the Website show page (spec 023).
 *
 * Sync probe — controller blocks until the probe completes (≤
 * `timeout_ms`, default 10s) and flashes the persisted check back
 * to the user. Spec 024's scheduler is where async becomes natural;
 * the same `RecordWebsiteCheckAction` is reused on both paths so
 * persistence semantics never drift.
 */
class WebsiteProbeController extends Controller
{
    public function __invoke(
        Website $website,
        RunWebsiteProbeAction $probe,
        RecordWebsiteCheckAction $record,
    ): RedirectResponse {
        $this->authorize('probe', $website);

        $result = $probe->execute($website);
        $record->execute($website, $result);

        $status = $result->status->value;
        $message = $result->responseTimeMs !== null
            ? "Probe complete — {$status} in {$result->responseTimeMs}ms."
            : "Probe complete — {$status}.";

        return back()->with('status', $message);
    }
}
