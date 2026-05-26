/**
 * Shared style helpers for Docker hosts (spec 026).
 *
 * Mirrors `websiteStyles.ts`: keep this map in sync with
 * `HostStatus::badgeTone()` so server- and client-rendered badges
 * agree.
 */

export type HostStatusValue =
    | 'pending'
    | 'online'
    | 'offline'
    | 'degraded'
    | 'archived'
    | string
    | null;

export const hostStatusTone = (
    status: HostStatusValue,
): 'muted' | 'success' | 'warning' | 'danger' =>
    (
        ({
            pending: 'muted',
            online: 'success',
            offline: 'danger',
            degraded: 'warning',
            archived: 'muted',
        }) as const
    )[status ?? ''] ?? 'muted';

/**
 * Tone for a container's Docker `state` (spec 028). `running` is the
 * healthy steady state; transient states warn; `exited` is neutral
 * (could be a finished one-shot) and `dead` is a hard failure.
 */
export const containerStateTone = (
    state: string | null,
): 'muted' | 'success' | 'warning' | 'danger' =>
    (
        ({
            running: 'success',
            created: 'warning',
            restarting: 'warning',
            paused: 'warning',
            exited: 'muted',
            dead: 'danger',
        }) as const
    )[state ?? ''] ?? 'muted';

/**
 * Tone for a container's healthcheck `health_status` (spec 028). Only
 * containers that declare a Docker `HEALTHCHECK` report anything other
 * than `none`.
 */
export const containerHealthTone = (
    health: string | null,
): 'muted' | 'success' | 'warning' | 'danger' =>
    (
        ({
            healthy: 'success',
            unhealthy: 'danger',
            starting: 'warning',
            none: 'muted',
        }) as const
    )[health ?? ''] ?? 'muted';
