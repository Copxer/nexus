export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User | null;
    };
};

/**
 * Status token used by KPI cards and other dashboard widgets to drive
 * `StatusBadge` tone. Mirrors the four status colors locked in
 * tailwind.config.js + visual-reference.md.
 */
export type DashboardStatus = 'success' | 'warning' | 'danger' | 'info';

/**
 * Mock dashboard payload returned by `OverviewController` for the Overview
 * page. Shape mirrors roadmap §8.1.1 with two phase-0 additions per card
 * (`sparkline` for the inline series, `status` for the badge tone).
 */
export interface DashboardPayload {
    projects: {
        active: number;
        new_this_week: number;
        sparkline: number[];
        status: DashboardStatus;
    };
    deployments: {
        successful_24h: number;
        change_percent: number;
        sparkline: number[];
        status: DashboardStatus;
    };
    services: {
        running: number;
        health_percent: number;
        sparkline: number[];
        status: DashboardStatus;
    };
    hosts: {
        online: number;
        new: number;
        sparkline: number[];
        status: DashboardStatus;
    };
    alerts: {
        active: number;
        critical: number;
        sparkline: number[];
        status: DashboardStatus;
    };
    uptime: {
        overall: number;
        change: number;
        sparkline: number[];
        status: DashboardStatus;
    };
}

/**
 * Roadmap §8.10 event-type vocabulary. Drives both the icon lookup and the
 * severity tone in `ActivityFeedItem`.
 */
export type ActivityEventType =
    | 'issue.created'
    | 'issue.closed'
    | 'pull_request.opened'
    | 'pull_request.merged'
    | 'pull_request.review_requested'
    | 'workflow.failed'
    | 'workflow.succeeded'
    | 'deployment.started'
    | 'deployment.succeeded'
    | 'deployment.failed'
    | 'website.down'
    | 'website.recovered'
    | 'host.offline'
    | 'host.recovered'
    | 'container.unhealthy'
    | 'alert.triggered'
    | 'alert.resolved';

/**
 * Single activity-feed event as returned by `OverviewController`. Mirrors
 * the §8.10 field list with the optional fields trimmed to what we actually
 * surface in the UI today (id, type, severity, title, source, occurred_at,
 * optional metadata pill).
 */
export interface ActivityEvent {
    id: string;
    type: ActivityEventType;
    severity: 'success' | 'warning' | 'danger' | 'info' | 'muted';
    title: string;
    source: string;
    /** Pre-formatted relative-time string ("3 min ago"). Server-rendered for now. */
    occurred_at: string;
    /** Optional metadata pill rendered to the right of the source meta. */
    metadata?: string;
}

/**
 * 7×6 heatmap grid. Outer array is days (Sun..Sat), inner array is the six
 * 4-hour buckets used by §8.11 MVP (12 AM / 4 AM / 8 AM / 12 PM / 4 PM / 8 PM).
 */
export type ActivityHeatmapPayload = number[][];
