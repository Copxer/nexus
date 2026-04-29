<?php

namespace App\Enums;

/**
 * Lifecycle of a GitHub webhook delivery row.
 *
 *  - `received` — controller stored the row + dispatched the job
 *  - `processed` — handler ran successfully
 *  - `failed`    — handler threw; `error_message` carries the reason
 *  - `skipped`   — event type or action we don't know how to handle
 *                  (per §8.5 "Log unknown events. Do not fail silently")
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
