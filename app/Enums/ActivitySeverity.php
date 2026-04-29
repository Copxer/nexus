<?php

namespace App\Enums;

/**
 * Severity tones for activity events. Maps directly onto the
 * `StatusBadge` / dashboard tone vocabulary so the UI can color
 * events without an extra translation layer.
 */
enum ActivitySeverity: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';

    public function badgeTone(): string
    {
        return $this->value;
    }
}
