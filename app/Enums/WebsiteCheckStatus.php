<?php

namespace App\Enums;

/**
 * Outcome of a single recorded `WebsiteCheck` row (spec 023).
 *
 * Same value set as `WebsiteStatus` minus `pending` — a check only
 * exists after a probe ran, so there's no pending state to model.
 *
 * Tones match the parent `WebsiteStatus` for visual consistency
 * across the index list (parent status), the show page header
 * (parent status), and the recent-checks list (per-row status).
 */
enum WebsiteCheckStatus: string
{
    case Up = 'up';
    case Down = 'down';
    case Slow = 'slow';
    case Error = 'error';

    public function badgeTone(): string
    {
        return match ($this) {
            self::Up => 'success',
            self::Down, self::Error => 'danger',
            self::Slow => 'warning',
        };
    }
}
