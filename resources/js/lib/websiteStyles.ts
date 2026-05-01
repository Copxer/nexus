/**
 * Shared style helpers for website monitors (spec 023).
 *
 * Used by:
 *   - `Pages/Monitoring/Websites/Index.vue` — list-row status badge.
 *   - `Pages/Monitoring/Websites/Show.vue` — header status badge +
 *     per-check status badge.
 *   - `Pages/Projects/Show.vue` — per-project Monitoring tab list.
 *
 * Centralising avoids drift when a new `WebsiteStatus` case lands
 * (e.g. `degraded` could arrive in spec 024). The PHP-side
 * counterpart lives on `WebsiteStatus::badgeTone()` and
 * `WebsiteCheckStatus::badgeTone()` — keep the two in sync.
 *
 * Both `WebsiteStatus` (parent) and `WebsiteCheckStatus` (per-check)
 * accept the same key set minus `pending` on the latter; one map
 * covers both.
 */

export type WebsiteStatusValue =
    | 'pending'
    | 'up'
    | 'down'
    | 'slow'
    | 'error'
    | string
    | null;

export const websiteStatusTone = (
    status: WebsiteStatusValue,
): 'muted' | 'success' | 'warning' | 'danger' =>
    (
        ({
            pending: 'muted',
            up: 'success',
            down: 'danger',
            slow: 'warning',
            error: 'danger',
        }) as const
    )[status ?? ''] ?? 'muted';
