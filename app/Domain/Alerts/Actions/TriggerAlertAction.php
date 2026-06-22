<?php

namespace App\Domain\Alerts\Actions;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Events\AlertTriggered;
use App\Models\Alert;
use Illuminate\Support\Carbon;

/**
 * Promote a transition event into a durable Alert row (spec 030).
 *
 * Idempotent on `(source, source_id, type)`: a second identical
 * trigger inside the open window bumps `last_seen_at` and returns the
 * same row — no duplicate Alert, no second activity event. This makes
 * the action safe to call from emitters that fire on every probe /
 * tick rather than only on transitions, even though spec 030's call
 * sites all fire on transitions.
 *
 * On a fresh trigger the action also emits an `alert.triggered`
 * activity event with `source: 'alerts'` so the right-rail picks up
 * the alert in realtime through the existing `ActivityEventCreated`
 * fan-out (the `source: alerts` branch resolves the recipient via
 * `metadata.alert_id`).
 *
 * Concurrency note: the idempotency check is `SELECT … WHERE status
 * IN (open, acknowledged) … LIMIT 1` followed by an `INSERT`, not an
 * atomic upsert. Two callers racing simultaneously could in theory
 * both miss the existing row and both insert. The 030 call sites
 * serialize (per-website scheduler / queued telemetry job / sequential
 * webhook handler) so this is theoretical today; if a future caller
 * arrives with real concurrency, add a partial unique index on
 * `(source, source_id, type) WHERE status IN ('open', 'acknowledged')`
 * (PostgreSQL) or a uniqueness guard at the action layer.
 */
class TriggerAlertAction
{
    public function __construct(
        private readonly CreateActivityEventAction $createActivity,
    ) {}

    /**
     * Spec 038 — `project_id` is nullable for `AlertSource::System`
     * alerts (queue / GitHub-rate / webhook / agent). Project-scoped
     * alerts still pass a real id.
     *
     * @param  array{
     *     project_id: int|null,
     *     source: AlertSource|string,
     *     source_id: int|null,
     *     type: string,
     *     severity: AlertSeverity|string,
     *     title: string,
     *     description?: string|null,
     *     metadata?: array<string, mixed>,
     * }  $attrs
     */
    public function execute(array $attrs): Alert
    {
        $source = $attrs['source'] instanceof AlertSource
            ? $attrs['source']->value
            : (string) $attrs['source'];
        $severity = $attrs['severity'] instanceof AlertSeverity
            ? $attrs['severity']
            : AlertSeverity::from((string) $attrs['severity']);

        $now = Carbon::now();

        $existing = Alert::query()
            ->where('source', $source)
            ->where('source_id', $attrs['source_id'])
            ->where('type', $attrs['type'])
            ->whereIn('status', [
                AlertStatus::Open->value,
                AlertStatus::Acknowledged->value,
            ])
            ->first();

        if ($existing !== null) {
            // Steady-state: the alert is still firing. Bump
            // `last_seen_at` so the UI can show "5 minutes ago"
            // freshness without inserting a sibling row. No second
            // activity event — that would flood the rail.
            $existing->forceFill(['last_seen_at' => $now])->save();

            return $existing;
        }

        $alert = Alert::query()->create([
            'project_id' => $attrs['project_id'] ?? null,
            'source' => $source,
            'source_id' => $attrs['source_id'],
            'type' => $attrs['type'],
            'severity' => $severity->value,
            'status' => AlertStatus::Open->value,
            'title' => $attrs['title'],
            'description' => $attrs['description'] ?? null,
            'triggered_at' => $now,
            'last_seen_at' => $now,
            'metadata' => $attrs['metadata'] ?? null,
        ]);

        $this->createActivity->execute([
            'event_type' => 'alert.triggered',
            'severity' => $severity->toActivitySeverity(),
            'title' => $alert->title,
            'description' => $alert->description,
            'occurred_at' => $now,
            'source' => 'alerts',
            'metadata' => [
                'alert_id' => $alert->id,
                'alert_source' => $source,
                'alert_source_id' => $alert->source_id,
                'alert_type' => $alert->type,
            ],
        ]);

        // Spec 032 — dedicated alerts broadcast so the `/alerts` page
        // can partial-reload its list + the TopBar bell can refresh
        // its count without filtering every event off the activity
        // channel. Only fires on the fresh-insert path; the
        // steady-state `last_seen_at` bump above stays silent so a
        // re-firing source doesn't double-toast.
        $alert->loadMissing('project:id,owner_user_id');
        AlertTriggered::dispatch($alert->id, $alert->project?->owner_user_id);

        return $alert;
    }
}
