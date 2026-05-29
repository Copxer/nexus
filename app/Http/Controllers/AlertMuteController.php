<?php

namespace App\Http\Controllers;

use App\Domain\Alerts\Actions\MuteAlertAction;
use App\Models\Alert;
use Illuminate\Http\RedirectResponse;

/**
 * `POST /alerts/{alert}/mute` — user-initiated "stop highlighting"
 * (spec 031). Thin handoff to `MuteAlertAction`. Idempotent on a row
 * already muted; refuses to mute a resolved row (terminal state wins).
 */
class AlertMuteController extends Controller
{
    public function __invoke(Alert $alert, MuteAlertAction $mute): RedirectResponse
    {
        $this->authorize('update', $alert);

        $mute->execute($alert);

        return back()->with('status', 'Alert muted.');
    }
}
