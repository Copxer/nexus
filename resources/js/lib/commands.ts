import { router } from '@inertiajs/vue3';
import {
    Activity,
    BarChart3,
    Bell,
    Flame,
    FolderKanban,
    GitBranch,
    GitPullRequest,
    Globe,
    Globe2,
    LayoutDashboard,
    LogOut,
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

export type CommandGroup = 'navigation' | 'actions' | 'system';

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
}

const groupOrder: CommandGroup[] = ['navigation', 'actions', 'system'];

export const commandGroupLabels: Record<CommandGroup, string> = {
    navigation: 'Navigation',
    actions: 'Actions',
    system: 'System',
};

/**
 * Build the command registry. Real commands (`run` defined) navigate or
 * trigger Inertia actions today. Disabled "Soon" entries advertise the
 * roadmap and stay inert until their owning spec ships — same treatment as
 * the sidebar nav.
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
            disabled: true,
            soonLabel: 'Phase 4',
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
            disabled: true,
            soonLabel: 'Phase 6',
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
            disabled: true,
            soonLabel: 'Phase 8',
        },
        {
            id: 'go-alerts',
            label: 'Go to Alerts',
            group: 'navigation',
            icon: Bell,
            disabled: true,
            soonLabel: 'Phase 7',
        },
        {
            id: 'go-settings',
            label: 'Go to Settings',
            group: 'navigation',
            icon: SettingsIcon,
            keywords: ['preferences', 'integrations'],
            run: () => router.visit(route('settings.index')),
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
            disabled: true,
            soonLabel: 'Phase 2',
        },
        {
            id: 'view-failed-deployments',
            label: 'View failed deployments',
            group: 'actions',
            icon: Flame,
            disabled: true,
            soonLabel: 'Phase 4',
        },
        {
            id: 'view-slow-websites',
            label: 'View slow websites',
            group: 'actions',
            icon: Globe2,
            disabled: true,
            soonLabel: 'Phase 5',
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
 * Stable group ordering — palette rendering uses this to lay groups out in
 * a deterministic order regardless of registry insertion order.
 */
export function compareGroups(a: CommandGroup, b: CommandGroup): number {
    return groupOrder.indexOf(a) - groupOrder.indexOf(b);
}

