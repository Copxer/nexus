import type { PaletteEntity } from '@/types';
import { router } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    BarChart3,
    Bell,
    Clock,
    Flame,
    FolderKanban,
    GitBranch,
    GitPullRequest,
    Globe,
    Globe2,
    LayoutDashboard,
    LogOut,
    Newspaper,
    Plug,
    Plus,
    RefreshCw,
    Rocket,
    Server,
    Settings as SettingsIcon,
    SunMoon,
    UserCog,
    type LucideIcon,
} from 'lucide-vue-next';

export type CommandGroup =
    | 'recent'
    | 'navigation'
    | 'actions'
    | 'projects'
    | 'repositories'
    | 'hosts'
    | 'websites'
    | 'workItems'
    | 'alerts'
    | 'system';


const groupOrder: CommandGroup[] = [
    'recent',
    'navigation',
    'actions',
    'projects',
    'repositories',
    'hosts',
    'websites',
    'workItems',
    'alerts',
    'system',
];

export const commandGroupLabels: Record<CommandGroup, string> = {
    recent: 'Recent',
    navigation: 'Navigation',
    actions: 'Actions',
    projects: 'Projects',
    repositories: 'Repositories',
    hosts: 'Hosts',
    websites: 'Websites',
    workItems: 'Work items',
    alerts: 'Alerts',
    system: 'System',
};

export interface Command {
    /** Stable identifier used as a Vue list key. */
    id: string;
    /** Human-readable label shown in the row. */
    label: string;
    /** Group bucket the row appears under in the palette list. */
    group: CommandGroup;
    /** Lucide icon component rendered on the row. */
    icon: LucideIcon;
    /**
     * Optional alternate search terms — useful for synonyms ("nav", "go to",
     * "site monitor"). Matched alongside `label` by the fuzzy filter.
     */
    keywords?: string[];
    /** Action to execute when this command is selected. Omitted for "Soon" entries. */
    run?: () => void;
    /** Right-side hint text rendered when the command is enabled (e.g. "G O" jump-to). */
    shortcut?: string;
    /** Whether the command is currently inert (will display the soonLabel pill). */
    disabled?: boolean;
    /** Pill text shown on disabled rows; defaults to "Soon". */
    soonLabel?: string;
    /**
     * Spec 043 — secondary line under the label, used by entity rows to
     * show a subtitle (`ops@example.com`, `#42 opened 3d ago`, …).
     * Static commands don't set this.
     */
    subtitle?: string | null;
    /**
     * Spec 043 — marker so palette recent-tracking skips entity rows.
     * Only static commands are LRU-tracked; entity rows are already
     * bookmarks in their own right (sidebar / URL).
     */
    isEntity?: boolean;
}

export interface PaletteEntityBundle {
    projects: PaletteEntity[];
    repositories: PaletteEntity[];
    hosts: PaletteEntity[];
    websites: PaletteEntity[];
}

/**
 * Build the static command registry — navigation + actions + system.
 * Real commands (`run` defined) navigate or trigger Inertia actions
 * today. Disabled "Soon" entries advertise the roadmap and stay inert
 * until their owning spec ships — same treatment as the sidebar nav.
 *
 * Live entity rows (spec 043) are appended via `buildEntityCommands()`
 * so the pure static registry stays cheap + deterministic.
 */
export function getCommands(): Command[] {
    return [
        // ────── Navigation ────── //
        {
            id: 'go-overview',
            label: 'Go to Overview',
            group: 'navigation',
            icon: LayoutDashboard,
            keywords: ['dashboard', 'home'],
            run: () => router.visit(route('overview')),
        },
        {
            id: 'go-profile',
            label: 'Go to Profile',
            group: 'navigation',
            icon: UserCog,
            keywords: ['account', 'settings'],
            run: () => router.visit(route('profile.edit')),
        },
        {
            id: 'go-projects',
            label: 'Go to Projects',
            group: 'navigation',
            icon: FolderKanban,
            keywords: ['list', 'manage'],
            run: () => router.visit(route('projects.index')),
        },
        {
            id: 'go-repositories',
            label: 'Go to Repositories',
            group: 'navigation',
            icon: GitBranch,
            keywords: ['repos', 'github'],
            run: () => router.visit(route('repositories.index')),
        },
        {
            id: 'go-issues-prs',
            label: 'Go to Issues & PRs',
            group: 'navigation',
            icon: GitPullRequest,
            keywords: ['issues', 'pull requests', 'prs', 'work items'],
            run: () => router.visit(route('work-items.index')),
        },
        {
            id: 'go-pipelines',
            label: 'Go to Pipelines',
            group: 'navigation',
            icon: Activity,
            // Pipelines is a planned nav view (roadmap §7.6/§8.6) with
            // no assigned phase yet — stays inert like Analytics/Alerts.
            disabled: true,
            soonLabel: 'Planned',
        },
        {
            id: 'go-deployments',
            label: 'Go to Deployments',
            group: 'navigation',
            icon: Rocket,
            keywords: ['deploy', 'workflow runs', 'actions', 'ci'],
            run: () => router.visit(route('deployments.index')),
        },
        {
            id: 'go-hosts',
            label: 'Go to Hosts',
            group: 'navigation',
            icon: Server,
            keywords: ['docker', 'agents', 'servers', 'containers'],
            run: () => router.visit(route('monitoring.hosts.index')),
        },
        {
            id: 'go-monitoring',
            label: 'Go to Monitoring',
            group: 'navigation',
            icon: Globe,
            keywords: ['monitor', 'websites', 'uptime', 'probes'],
            run: () => router.visit(route('monitoring.websites.index')),
        },
        {
            id: 'go-analytics',
            label: 'Go to Analytics',
            group: 'navigation',
            icon: BarChart3,
            keywords: ['metrics', 'dashboard', 'charts', 'trends', 'mttr', 'uptime'],
            run: () => router.visit(route('analytics.index')),
        },
        {
            id: 'go-alerts',
            label: 'Go to Alerts',
            group: 'navigation',
            icon: Bell,
            keywords: ['incidents', 'open alerts', 'acks'],
            run: () => router.visit(route('alerts.index')),
        },
        {
            id: 'go-settings',
            label: 'Go to Settings',
            group: 'navigation',
            icon: SettingsIcon,
            keywords: ['preferences', 'integrations'],
            run: () => router.visit(route('settings.index')),
        },
        {
            id: 'open-daily-briefings',
            label: 'Open daily briefings',
            group: 'navigation',
            icon: Newspaper,
            keywords: ['ai', 'briefing', 'digest', 'history', 'summary'],
            run: () => router.visit(route('daily-briefings.index')),
        },

        // ────── Actions ────── //
        {
            id: 'log-out',
            label: 'Log out',
            group: 'actions',
            icon: LogOut,
            keywords: ['sign out', 'logout'],
            run: () => router.post(route('logout')),
        },
        {
            id: 'create-project',
            label: 'Create project',
            group: 'actions',
            icon: Plus,
            keywords: ['new', 'add'],
            run: () => router.visit(route('projects.create')),
        },
        {
            id: 'connect-github',
            label: 'Connect GitHub',
            group: 'actions',
            icon: Plug,
            keywords: ['oauth', 'integration'],
            run: () => router.visit(route('integrations.github.connect')),
        },
        {
            id: 'run-sync',
            label: 'Run sync',
            group: 'actions',
            icon: RefreshCw,
            keywords: ['sync', 'refresh', 'github', 'repositories'],
            run: () => router.post(route('repositories.sync-all')),
        },
        {
            id: 'view-failed-deployments',
            label: 'View failed deployments',
            group: 'actions',
            icon: Flame,
            keywords: ['failed', 'deployments', 'broken', 'ci'],
            run: () =>
                router.visit(route('deployments.index', { conclusion: 'failure' })),
        },
        {
            id: 'view-slow-websites',
            label: 'View slow websites',
            group: 'actions',
            icon: Globe2,
            keywords: ['slow', 'websites', 'monitoring', 'performance'],
            run: () =>
                router.visit(route('monitoring.websites.index', { status: 'slow' })),
        },
        {
            id: 'go-rules',
            label: 'Open rules & health weights',
            group: 'actions',
            icon: SettingsIcon,
            keywords: [
                'rules',
                'weights',
                'health',
                'score',
                'alerts',
                'metric',
                'threshold',
            ],
            run: () => router.visit(route('settings.rules.index')),
        },
        {
            id: 'daily-briefing-settings',
            label: 'Daily briefing settings',
            group: 'actions',
            icon: SettingsIcon,
            keywords: ['ai', 'briefing', 'digest', 'preferences', 'schedule', 'delivery'],
            run: () => router.visit(route('settings.daily-briefing.index')),
        },

        // ────── System ────── //
        {
            id: 'toggle-theme',
            label: 'Toggle theme',
            group: 'system',
            icon: SunMoon,
            disabled: true,
            soonLabel: 'Phase 9',
        },
    ];
}

/**
 * Convert the pre-loaded entity bundle from the shared Inertia prop
 * into `Command` rows appended to the palette registry (spec 043).
 * Callers only include these when the palette has a non-empty query;
 * the empty-query view stays focused on static commands + recent.
 */
export function buildEntityCommands(bundle: PaletteEntityBundle | null): Command[] {
    if (!bundle) return [];

    const projects = bundle.projects.map((p): Command => ({
        id: `entity-project-${p.id}`,
        label: `Project · ${p.label}`,
        subtitle: p.subtitle,
        group: 'projects',
        icon: FolderKanban,
        keywords: p.keywords,
        isEntity: true,
        run: () => router.visit(p.url),
    }));

    const repositories = bundle.repositories.map((r): Command => ({
        id: `entity-repo-${r.id}`,
        label: `Repo · ${r.label}`,
        subtitle: r.subtitle,
        group: 'repositories',
        icon: GitBranch,
        keywords: r.keywords,
        isEntity: true,
        run: () => router.visit(r.url),
    }));

    const hosts = bundle.hosts.map((h): Command => ({
        id: `entity-host-${h.id}`,
        label: `Host · ${h.label}`,
        subtitle: h.subtitle,
        group: 'hosts',
        icon: Server,
        keywords: h.keywords,
        isEntity: true,
        run: () => router.visit(h.url),
    }));

    const websites = bundle.websites.map((w): Command => ({
        id: `entity-website-${w.id}`,
        label: `Website · ${w.label}`,
        subtitle: w.subtitle,
        group: 'websites',
        icon: Globe,
        keywords: w.keywords,
        isEntity: true,
        run: () => router.visit(w.url),
    }));

    return [...projects, ...repositories, ...hosts, ...websites];
}

/**
 * Convert async server-side results (work items + alerts) into `Command`
 * rows for palette rendering (spec 043).
 */
export function buildAsyncCommands(
    workItems: Array<{ id: number; label: string; subtitle: string | null; url: string }>,
    alerts: Array<{ id: number; label: string; subtitle: string | null; url: string }>,
): Command[] {
    const workItemRows = workItems.map((w): Command => ({
        id: `async-workitem-${w.id}`,
        label: w.label,
        subtitle: w.subtitle,
        group: 'workItems',
        icon: GitPullRequest,
        isEntity: true,
        run: () => router.visit(w.url),
    }));

    const alertRows = alerts.map((a): Command => ({
        id: `async-alert-${a.id}`,
        label: a.label,
        subtitle: a.subtitle,
        group: 'alerts',
        icon: AlertTriangle,
        isEntity: true,
        run: () => router.visit(a.url),
    }));

    return [...workItemRows, ...alertRows];
}

/**
 * Filter the static command list to just the ids the user has recently
 * run, sorted by recency. Skips ids that no longer exist in the
 * registry (e.g. a command was renamed since it was last recorded).
 */
export function pickRecentCommands(
    all: readonly Command[],
    recentIds: readonly string[],
): Command[] {
    const byId = new Map<string, Command>();
    for (const cmd of all) byId.set(cmd.id, cmd);

    return recentIds
        .map((id) => byId.get(id))
        .filter((cmd): cmd is Command => cmd !== undefined && !cmd.disabled)
        .map((cmd): Command => ({
            ...cmd,
            group: 'recent',
            // Fresh id so Vue lists don't collide with the "canonical"
            // command row in the Navigation/Actions groups.
            id: `recent-${cmd.id}`,
        }));
}

/** Icon used by CommandPalette for the loading indicator. */
export { Clock as PaletteLoadingIcon };

/**
 * Stable group ordering — palette rendering uses this to lay groups out in
 * a deterministic order regardless of registry insertion order.
 */
export function compareGroups(a: CommandGroup, b: CommandGroup): number {
    return groupOrder.indexOf(a) - groupOrder.indexOf(b);
}
