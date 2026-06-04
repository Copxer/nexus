<?php

namespace App\Listeners;

use App\Domain\Analytics\Jobs\RecomputeProjectHealthScoreJob;
use App\Events\ActivityEventCreated;
use App\Models\ActivityEvent;
use App\Models\Alert;
use App\Models\Host;
use App\Models\Website;

/**
 * Spec 033 — bridge from the activity rail to the health-score
 * recompute pipeline. Every `ActivityEventCreated` emit is inspected;
 * if the event_type is one that §14.2 deducts on, the listener
 * resolves the relevant project and queues a per-project recompute
 * job. Everything else is ignored — telemetry ticks (`host.metrics.
 * recorded`), benign workflow events, GitHub sync chatter never
 * touch the queue from here.
 *
 * Project resolution mirrors `ActivityEventCreated::resolveOwnerUserId`:
 * the activity row doesn't store a direct `project_id`, so we walk
 * source → entity → project per the same four scoping paths the
 * broadcast routing already uses. Duplication is acceptable here —
 * unifying the two into a shared resolver is its own refactor and
 * the mapping is stable.
 *
 * Auto-discovered by Laravel 11+'s event-listener scan (single
 * `handle(ActivityEventCreated)` method); no explicit registration
 * required.
 */
class RecomputeProjectHealthOnActivity
{
    /**
     * Activity event types that should re-trigger a score recompute.
     * Everything else is a no-op — including bulk telemetry,
     * GitHub-sync chatter, and informational events like
     * `release.published` that §14.2 doesn't weight.
     */
    private const SCORE_MOVING_EVENTS = [
        'alert.triggered',
        'alert.resolved',
        'website.down',
        'website.recovered',
        'website.slow',
        'website.error',
        'host.offline',
        'host.recovered',
        'workflow.failed',
        'github.sync.failed',
    ];

    public function handle(ActivityEventCreated $event): void
    {
        $activity = $event->activityEvent;

        if (! in_array($activity->event_type, self::SCORE_MOVING_EVENTS, true)) {
            return;
        }

        $projectId = $this->resolveProjectId($activity);

        if ($projectId === null) {
            return;
        }

        RecomputeProjectHealthScoreJob::dispatch($projectId);
    }

    /**
     * Walk the activity row to the owning project id. Returns null
     * for orphan rows (no relevant entity, deleted parent) — the
     * listener silently skips those.
     */
    private function resolveProjectId(ActivityEvent $activity): ?int
    {
        // Repo-scoped path (workflow.failed comes through here).
        if ($activity->repository_id !== null) {
            return $activity->repository?->project_id;
        }

        $metadata = $activity->metadata ?? [];

        return match ($activity->source) {
            'monitoring' => isset($metadata['website_id'])
                ? Website::query()->whereKey($metadata['website_id'])->value('project_id')
                : null,
            'hosts' => isset($metadata['host_id'])
                ? Host::query()->whereKey($metadata['host_id'])->value('project_id')
                : null,
            'alerts' => isset($metadata['alert_id'])
                ? Alert::query()->whereKey($metadata['alert_id'])->value('project_id')
                : null,
            default => null,
        };
    }
}
