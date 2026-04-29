<?php

namespace App\Enums;

/**
 * Priority tier for a project. Drives the priority pill colour on Index/Show.
 * Mirrors roadmap §8.2 Priority Options.
 */
enum ProjectPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /** Tone used by `StatusBadge` (muted/info/warning/danger). */
    public function badgeTone(): string
    {
        return match ($this) {
            self::Low => 'muted',
            self::Medium => 'info',
            self::High => 'warning',
            self::Critical => 'danger',
        };
    }
}
