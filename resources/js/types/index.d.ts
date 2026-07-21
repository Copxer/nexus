export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    /** Spec 036 — per-user UI theme preference. */
    theme?: 'dark' | 'light' | 'system';
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User | null;
    };
    flash?: {
        status?: string | null;
        error?: string | null;
        /**
         * Spec 026 — plaintext agent token surfaced once after issue or
         * rotate. Read by `Components/Hosts/AgentTokenPanel.vue`. Never
         * persisted on the client; the next page load drops it.
         */
        agentTokenPlaintext?: string | null;
    };
    /**
     * Right-rail activity feed shared by `HandleInertiaRequests::share()`
     * (spec 018). Authenticated pages get the latest events for the user's
     * accessible scope; guests get an empty array.
     */
    activity?: {
        recent: ActivityEvent[];
    };
    /**
     * Open + acknowledged alert count, shared by
     * `HandleInertiaRequests::share()` (spec 032). Drives the TopBar
     * notifications-bell badge across every page. `null` for guests.
     */
    alerts?: {
        activeCount: number;
    } | null;
    /**
     * Spec 043 — pre-loaded command palette entity bundle. Each list
     * is bounded (50 projects, 100 repos, 50 hosts, 50 websites) so
     * the shared prop stays cheap even for heavy users. `null` for
     * guests.
     */
    palette?: {
        entities: {
            projects: PaletteEntity[];
            repositories: PaletteEntity[];
            hosts: PaletteEntity[];
            websites: PaletteEntity[];
        };
    } | null;
};

/**
 * Serialized entity row surfaced by `GetPaletteEntitiesQuery`. The
 * shape is deliberately narrow — enough to render a palette row
 * (label + subtitle + keywords) and navigate on click (url), no more.
 */
export interface PaletteEntity {
    id: number;
    label: string;
    subtitle: string | null;
    keywords: string[];
    url: string;
}

/**
 * Status token used by KPI cards and other dashboard widgets to drive
 * `StatusBadge` tone. Mirrors the four status colors locked in
 * tailwind.config.js + visual-reference.md.
 */
export type DashboardStatus =
    | 'success'
    | 'warning'
    | 'danger'
    | 'info'
    | 'muted';

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
        /**
         * Integer percent (0–100) of completed runs that succeeded in
         * the 24h window. `null` when no completed runs landed — the
         * UI renders that as `—% success` instead of `0%` so an empty
         * window doesn't read as a failure.
         */
        success_rate_24h: number | null;
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
        offline: number;
        new: number;
        sparkline: number[];
        status: DashboardStatus;
        cards: Array<{
            id: number;
            name: string;
            status: string | null;
            cpu_percent: number | null;
            memory_percent: number | null;
            last_seen_at: string | null;
        }>;
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

    /**
     * Top Repositories slice rendered by the Overview widget. Computed
     * from the `repositories` table; ordered by `stars_count desc`. The
     * `commits` field is currently a `stars_count` proxy — phase-2's
     * GitHub sync replaces it with real commit data. Empty array
     * triggers the widget's empty state.
     */
    topRepositories: Array<{
        name: string;
        commits: number;
        share: number;
    }>;

    /**
     * Spec 035 — owned projects ranked ascending by `health_score`
     * with nulls last. Empty array triggers the "All projects healthy"
     * placeholder. Capped at 6 server-side.
     */
    riskyProjects: RiskyProjectRow[];
}

export interface RiskyProjectRow {
    id: number;
    slug: string;
    name: string;
    color: string | null;
    icon: string | null;
    health_score: number | null;
    /** §14.2 band — null when score is null. */
    health_band:
        | 'healthy'
        | 'good'
        | 'degraded'
        | 'warning'
        | 'critical'
        | null;
    /** Humanized via `diffForHumans()`. */
    last_activity_at: string | null;
    health_explanation: ProjectHealthExplanationPayload | null;
}

export interface ProjectHealthExplanationPayload {
    status: 'pending' | 'explained' | 'failed' | 'skipped';
    summary: string | null;
    drivers: string[];
    recommended_actions: string[];
    explained_at: string | null;
    failed_at: string | null;
    error_message: string | null;
}

/**
 * Roadmap §8.10 event-type vocabulary. Drives both the icon lookup and the
 * severity tone in `ActivityFeedItem`.
 */
export type ActivityEventType =
    | 'issue.created'
    | 'issue.closed'
    | 'issue.reopened'
    | 'issue.updated'
    | 'pull_request.opened'
    | 'pull_request.merged'
    | 'pull_request.closed'
    | 'pull_request.review_requested'
    | 'workflow.failed'
    | 'workflow.succeeded'
    | 'release.published'
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
