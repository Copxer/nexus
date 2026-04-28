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
