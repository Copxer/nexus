<?php

namespace App\Domain\Alerts\Actions;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Domain\Notifications\Services\AlertNotificationService;
use App\Enums\ActivitySeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Events\AlertResolved;
use App\Models\Alert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 * event and (spec 032) an `AlertResolved` broadcast so the rail + the
 * `/alerts` page + the TopBar bell react in realtime. A no-op call
 * (nothing matches) emits nothing.
 *
 * **Race-guard (post-032 follow-up).** Two callers can hit the same
 * `(source, source_id, type)` near-simultaneously — e.g. a user
 * clicking Resolve in the browser while a website-monitor cron's
 * recovery path is mid-flight. Without a guard, both `query->get()`
 * SELECTs return the row, both flip + emit, both broadcast — the
 * rail double-renders the recovery and the TopBar badge churns.
 *
 * The guard: re-fetch each candidate inside a per-row transaction
 * with `lockForUpdate()` and a fresh `whereIn('status', [open,
 * acknowledged])` filter. The first caller wins the row-level lock,
 * flips + emits, releases. The second caller's re-fetch returns
 * `null` (status is now resolved → filter excludes it) and the close
 * path is silently skipped. The action's return value reports the
 * rows actually closed, not the rows the initial SELECT returned.
 *
 * On SQLite (the dev/test DB) `lockForUpdate` is a no-op — file-level
 * locks already serialize writes, so the guard is harmless and the
 * status re-check on its own still catches stale candidates.
 */
class ResolveAlertAction
{
    public function __construct(
        private readonly CreateActivityEventAction $createActivity,
        private readonly AlertNotificationService $notifications,
    ) {}

    /**
     * @param  array{
     *     source: AlertSource|string,
     *     source_id: int|null,
     *     type?: string,
     * }  $criteria
     * @return int Number of Alerts actually closed (may be less than the
     *             initial SELECT returned if concurrent callers won the
     *             race for some rows — see the race-guard note above).
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

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $now = Carbon::now();
        $closed = 0;

        foreach ($candidates as $candidate) {
            if ($this->closeIfStillActionable($candidate->id, $source, $now)) {
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Close one candidate alert under a row-level lock; returns true
     * iff this caller actually flipped the row + emitted. A racing
     * caller that already won the lock returns false here (the
     * re-fetch finds the row's status outside the `[open,
     * acknowledged]` set, so nothing to do).
     */
    private function closeIfStillActionable(int $candidateId, string $source, Carbon $now): bool
    {
        return DB::transaction(function () use ($candidateId, $source, $now): bool {
            $alert = Alert::query()
                ->whereKey($candidateId)
                ->whereIn('status', [
                    AlertStatus::Open->value,
                    AlertStatus::Acknowledged->value,
                ])
                ->lockForUpdate()
                ->first();

            if ($alert === null) {
                return false; // racing caller already closed it
            }

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

            // Spec 032 — dedicated alerts broadcast per closed row.
            // Inside the same transaction as the close + activity
            // event so the broadcast can't race the write (the
            // `ShouldDispatchAfterCommit` interface defers it until
            // commit).
            $alert->loadMissing('project:id,owner_user_id');
            AlertResolved::dispatch($alert->id, $alert->project?->owner_user_id);

            // Spec 042 — resolution notification is opt-in per
            // preference row (`notify_on_resolve`). Fire-and-forget.
            try {
                $this->notifications->dispatchResolutionFor($alert);
            } catch (Throwable $e) {
                Log::warning('AlertNotificationService::dispatchResolutionFor failed', [
                    'alert_id' => $alert->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return true;
        });
    }
}
