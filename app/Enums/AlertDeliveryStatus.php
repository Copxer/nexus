<?php

namespace App\Enums;

/**
 * Lifecycle of an alert delivery row (spec 042).
 *
 * `pending` — job enqueued, no attempt yet.
 * `sent`    — driver returned 2xx (or Mail::send didn't throw).
 * `failed`  — driver exhausted retries (default 3 per §18 backoff).
 * `skipped` — never attempted: rate-limited, deduped, channel
 *             disabled, or channel un-verified. `error_message`
 *             carries the reason marker for surface in the UI.
 */
enum AlertDeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function badgeTone(): string
    {
        return match ($this) {
            self::Pending => 'muted',
            self::Sent => 'success',
            self::Failed => 'danger',
            self::Skipped => 'warning',
        };
    }
}
