<?php

namespace App\Domain\PublicStatus\Listeners;

use App\Domain\PublicStatus\Jobs\NotifyStatusSubscribersJob;
use App\Events\AlertResolved;
use App\Events\AlertTriggered;
use App\Models\Alert;

/**
 * Spec 047 — listens for the two spec-032 alert events and enqueues
 * `NotifyStatusSubscribersJob` for the project (if it's opted in).
 *
 * Fire-and-forget from the event bus. If the queue is unavailable
 * or the job dispatch throws for any reason, the failure is
 * observed via Horizon; the alert lifecycle itself never fails
 * because a public notification couldn't ship.
 */
class NotifyPublicSubscribersOnAlertListener
{
    public function handle(AlertTriggered|AlertResolved $event): void
    {
        $alert = Alert::query()->find($event->alertId);

        if ($alert === null || $alert->project_id === null) {
            return;
        }

        // Skip cheaply before enqueueing so the queue only carries
        // useful work.
        $enabled = $alert->project()->value('public_status_enabled');
        if (! $enabled) {
            return;
        }

        $eventName = $event instanceof AlertResolved ? 'resolved' : 'triggered';

        NotifyStatusSubscribersJob::dispatch($alert->id, $eventName);
    }
}
