<?php

namespace App\Domain\Alerts\Actions;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Enums\ActivitySeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use Illuminate\Support\Carbon;

/**
 * Close every open or acknowledged Alert matching a recovery
 * transition (spec 030). One Alert per `(source, source_id, type)` —
 * see `TriggerAlertAction`'s idempotency contract — so the closer
 * normally touches at most one row. `type` is optional so a single
 * recovery call can fan-close every Alert for the source if needed.
 *
 * Ack'd rows are closed alongside open rows: an ack'd outage that
 * recovers is still resolved. If the user wants ack to "stick"
 * through recovery they can mute (031).
 *
 * For each closed row the action emits an `alert.resolved` activity
 * event so the rail picks up the recovery in realtime. A no-op call
 * (nothing matches) emits nothing.
 */
class ResolveAlertAction
{
    public function __construct(
        private readonly CreateActivityEventAction $createActivity,
    ) {}

    /**
     * @param  array{
     *     source: AlertSource|string,
     *     source_id: int|null,
     *     type?: string,
     * }  $criteria
     * @return int Number of Alerts resolved.
     */
    public function execute(array $criteria): int
    {
        $source = $criteria['source'] instanceof AlertSource
            ? $criteria['source']->value
            : (string) $criteria['source'];

        $query = Alert::query()
            ->where('source', $source)
            ->where('source_id', $criteria['source_id'])
            ->whereIn('status', [
                AlertStatus::Open->value,
                AlertStatus::Acknowledged->value,
            ]);

        if (isset($criteria['type'])) {
            $query->where('type', $criteria['type']);
        }

        $resolving = $query->get();

        if ($resolving->isEmpty()) {
            return 0;
        }

        $now = Carbon::now();

        foreach ($resolving as $alert) {
            $alert->forceFill([
                'status' => AlertStatus::Resolved->value,
                'resolved_at' => $now,
                'last_seen_at' => $now,
            ])->save();

            $this->createActivity->execute([
                'event_type' => 'alert.resolved',
                'severity' => ActivitySeverity::Success,
                'title' => "{$alert->title} resolved",
                'occurred_at' => $now,
                'source' => 'alerts',
                'metadata' => [
                    'alert_id' => $alert->id,
                    'alert_source' => $source,
                    'alert_source_id' => $alert->source_id,
                ],
            ]);
        }

        return $resolving->count();
    }
}
