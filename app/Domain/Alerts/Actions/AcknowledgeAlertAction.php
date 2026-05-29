<?php

namespace App\Domain\Alerts\Actions;

use App\Enums\AlertStatus;
use App\Models\Alert;
use Illuminate\Support\Carbon;

/**
 * User-initiated "I see this and I'm on it" — flips an open Alert to
 * acknowledged (spec 031). Silent: no activity event. Ack is UI-state,
 * not project-state — flooding the rail with "Yoany ack'd this" rows
 * would dilute the signal that already covers the real transitions
 * (`alert.triggered`, `alert.recovered`, `alert.resolved`).
 *
 * Idempotent in both directions:
 *   - Already-acknowledged: no-op (no double-stamp).
 *   - Resolved / muted: no-op (you can't ack what's closed; the
 *     workflow is "Resolve cancels everything else").
 */
class AcknowledgeAlertAction
{
    public function execute(Alert $alert): Alert
    {
        if ($alert->status !== AlertStatus::Open) {
            return $alert;
        }

        $now = Carbon::now();

        $alert->forceFill([
            'status' => AlertStatus::Acknowledged->value,
            'acknowledged_at' => $now,
            'last_seen_at' => $now,
        ])->save();

        return $alert;
    }
}
