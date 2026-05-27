<?php

namespace App\Enums;

/**
 * Severity rung of an Alert (spec 030). Matches roadmap §8.12.
 *
 * Tones map to `StatusBadge`:
 *   info     → info
 *   warning  → warning
 *   critical → danger
 *
 * Activity-event severity maps similarly: `critical` Alerts surface as
 * `ActivitySeverity::Danger` on the rail.
 */
enum AlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function badgeTone(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Warning => 'warning',
            self::Critical => 'danger',
        };
    }

    public function toActivitySeverity(): ActivitySeverity
    {
        return match ($this) {
            self::Info => ActivitySeverity::Info,
            self::Warning => ActivitySeverity::Warning,
            self::Critical => ActivitySeverity::Danger,
        };
    }
}
