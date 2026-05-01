<?php

namespace App\Enums;

/**
 * Lifecycle of a GitHub webhook delivery row.
 *
 *  - `received` — controller stored the row + dispatched the job
 *  - `processed` — handler ran successfully
 *  - `failed`    — handler threw; `error_message` carries the reason
 *  - `skipped`   — handler chose not to surface the event to the
 *                  activity feed (e.g. unknown event type, non-terminal
 *                  workflow run action, repository not imported into
 *                  Nexus). Per §8.5: "Log unknown events. Do not fail
 *                  silently."
 *
 *                  As of spec 020, `skipped` no longer guarantees zero
 *                  side-effects — the workflow_run handler upserts the
 *                  run into `workflow_runs` for ALL deliveries (so the
 *                  timeline reflects in-flight states) and only
 *                  short-circuits the activity-event creation. The
 *                  semantic intent is "no activity event," not "no
 *                  DB writes."
 */
enum WebhookDeliveryStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function badgeTone(): string
    {
        return match ($this) {
            self::Received => 'info',
            self::Processed => 'success',
            self::Failed => 'danger',
            self::Skipped => 'muted',
        };
    }
}
