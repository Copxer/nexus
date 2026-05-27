<?php

namespace App\Enums;

/**
 * Lifecycle of an Alert (spec 030).
 *
 * - `open`         — fresh trigger, awaiting human action or recovery.
 * - `acknowledged` — a user said "I see it" but hasn't fixed it yet.
 *                    Auto-recovery still closes the row.
 * - `resolved`     — closed, either by `ResolveAlertAction` (recovery
 *                    event) or by the user clicking Resolve in 031.
 * - `muted`        — the user opted out of further surfacing for this
 *                    specific row. Future triggers for the same
 *                    `(source, source_id, type)` will still create a
 *                    fresh open Alert.
 */
enum AlertStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
    case Muted = 'muted';
}
