<?php

namespace App\Domain\Alerts\Actions;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
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
 */
class TriggerAlertAction
{
    public function __construct(
        private readonly CreateActivityEventAction $createActivity,
    ) {}

    /**
     * @param  array{
     *     project_id: int,
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
            'project_id' => $attrs['project_id'],
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

        return $alert;
    }
}
