<?php

namespace App\Http\Controllers;

use App\Domain\Alerts\Actions\AcknowledgeAlertAction;
use App\Models\Alert;
use Illuminate\Http\RedirectResponse;

/**
 * `POST /alerts/{alert}/acknowledge` — user-initiated ack (spec 031).
 * Thin handoff to `AcknowledgeAlertAction`. Idempotent: re-ack'ing an
 * already-acknowledged row is a no-op (no double-stamp).
 */
class AlertAcknowledgeController extends Controller
{
    public function __invoke(Alert $alert, AcknowledgeAlertAction $acknowledge): RedirectResponse
    {
        $this->authorize('update', $alert);

        $acknowledge->execute($alert);

        return back()->with('status', 'Alert acknowledged.');
    }
}
