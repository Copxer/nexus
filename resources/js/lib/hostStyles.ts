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
