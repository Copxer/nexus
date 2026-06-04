<?php

namespace App\Enums;

/**
 * Project health-score banding (spec 033, roadmap §14.2).
 *
 * Mapping (inclusive on both ends):
 *   90–100 → healthy
 *   70–89  → good
 *   50–69  → degraded
 *   30–49  → warning
 *    0–29  → critical
 *
 * Tones map to `StatusBadge`:
 *   healthy  → success
 *   good     → info
 *   degraded → warning
 *   warning  → warning
 *   critical → danger
 */
enum HealthScoreBand: string
{
    case Healthy = 'healthy';
    case Good = 'good';
    case Degraded = 'degraded';
    case Warning = 'warning';
    case Critical = 'critical';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 90 => self::Healthy,
            $score >= 70 => self::Good,
            $score >= 50 => self::Degraded,
            $score >= 30 => self::Warning,
            default => self::Critical,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Good => 'Good',
            self::Degraded => 'Degraded',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Good => 'info',
            self::Degraded, self::Warning => 'warning',
            self::Critical => 'danger',
        };
    }
}
