<?php

namespace App\Enums;

/**
 * Lifecycle status for a project. Drives the status badge on Index/Show.
 * Mirrors roadmap §8.2 Status Options.
 */
enum ProjectStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Paused = 'paused';
    case Archived = 'archived';

    /** Tone used by `StatusBadge` (success/warning/info/muted). */
    public function badgeTone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Maintenance => 'warning',
            self::Paused => 'info',
            self::Archived => 'muted',
        };
    }
}
