<?php

namespace App\Enums;

/**
 * Lifecycle of a Docker host (spec 026).
 *
 * - `pending`   — created, no telemetry received yet.
 * - `online`    — last telemetry was within the host's silence
 *                 threshold.
 * - `offline`   — last telemetry exceeded the threshold (set by
 *                 spec 029's offline watcher).
 * - `degraded`  — host is online but reporting unhealthy containers
 *                 or resource pressure (spec 029).
 * - `archived`  — soft-archived; hidden from active lists. Pairs
 *                 with `archived_at`.
 *
 * Tones map to `StatusBadge`:
 *   pending    → muted (no signal yet)
 *   online     → success
 *   offline    → danger
 *   degraded   → warning
 *   archived   → muted
 */
enum HostStatus: string
{
    case Pending = 'pending';
    case Online = 'online';
    case Offline = 'offline';
    case Degraded = 'degraded';
    case Archived = 'archived';

    public function badgeTone(): string
    {
        return match ($this) {
            self::Pending, self::Archived => 'muted',
            self::Online => 'success',
            self::Offline => 'danger',
            self::Degraded => 'warning',
        };
    }
}
