<?php

namespace App\Http\Controllers;

use App\Domain\Alerts\Actions\ResolveAlertAction;
use App\Enums\AlertStatus;
use App\Models\Alert;
use Illuminate\Http\RedirectResponse;

/**
 * `POST /alerts/{alert}/resolve` — user-initiated resolve (spec 031).
 * Reuses spec 030's `ResolveAlertAction` with the alert's own
 * `(source, source_id, type)` as the criteria. Idempotency in 030's
 * Trigger guarantees at most one open / acknowledged row per
 * `(source, source_id, type)`, so this closes exactly the clicked
 * alert and emits the same `alert.resolved` activity event that the
 * recovery-driven auto-resolve path already does.
 */
class AlertResolveController extends Controller
{
    public function __invoke(Alert $alert, ResolveAlertAction $resolve): RedirectResponse
    {
        $this->authorize('update', $alert);

        // Muted is "stop fanning, I know about it" — auto-resolving
        // it would silently invalidate the user's earlier choice. The
        // Vue layer already hides the Resolve button on muted rows
        // (`can_resolve` is false in the transform); this guard
        // surfaces a real error if a stale page state or direct API
        // call hits the route anyway.
        if ($alert->status === AlertStatus::Muted) {
            return back()->with(
                'error',
                'Cannot resolve a muted alert. Unmute it first.',
            );
        }

        $resolve->execute([
            'source' => $alert->source,
            'source_id' => $alert->source_id,
            'type' => $alert->type,
        ]);

        return back()->with('status', 'Alert resolved.');
    }
}
