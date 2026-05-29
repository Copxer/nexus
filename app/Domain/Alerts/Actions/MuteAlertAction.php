<?php

namespace App\Domain\Alerts\Actions;

use App\Enums\AlertStatus;
use App\Models\Alert;
use Illuminate\Support\Carbon;

/**
 * User-initiated "stop highlighting this" — flips an Alert to muted
 * (spec 031). Mute is the escape hatch: it suppresses further
 * surfacing for this specific row even when the underlying source
 * keeps re-triggering. Future triggers for the same `(source,
 * source_id, type)` still create a fresh open Alert — see
 * `TriggerAlertAction`'s idempotency contract (mute is per-row, not
 * per-source-tuple).
 *
 * Silent — no activity event, same reasoning as Acknowledge.
 *
 * Idempotent:
 *   - Already muted: no-op.
 *   - Resolved: no-op (resolved is terminal; mute can't override).
 *
 * Open and acknowledged alerts can both be muted — "I know about
 * it, I'm not going to act on it, stop telling me."
 */
class MuteAlertAction
{
    public function execute(Alert $alert): Alert
    {
        if ($alert->status === AlertStatus::Muted || $alert->status === AlertStatus::Resolved) {
            return $alert;
        }

        $now = Carbon::now();

        $alert->forceFill([
            'status' => AlertStatus::Muted->value,
            'last_seen_at' => $now,
        ])->save();

        return $alert;
    }
}
