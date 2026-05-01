<?php

namespace App\Enums;

/**
 * Lifecycle of a website monitor (spec 023).
 *
 * - `pending` — created, never probed.
 * - `up`      — last probe matched the expected status code.
 * - `down`    — last probe responded but did not match the expected
 *               status code.
 * - `slow`    — last probe matched the expected status code but
 *               exceeded the slow threshold (3000ms phase-1).
 * - `error`   — last probe could not complete (DNS / timeout /
 *               connection refused / TLS failure).
 *
 * Tones map to `StatusBadge`:
 *   pending → muted (no signal yet)
 *   up      → success
 *   down    → danger
 *   slow    → warning
 *   error   → danger
 */
enum WebsiteStatus: string
{
    case Pending = 'pending';
    case Up = 'up';
    case Down = 'down';
    case Slow = 'slow';
    case Error = 'error';

    public function badgeTone(): string
    {
        return match ($this) {
            self::Pending => 'muted',
            self::Up => 'success',
            self::Down, self::Error => 'danger',
            self::Slow => 'warning',
        };
    }
}
