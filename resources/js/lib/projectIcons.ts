import {
    Activity,
    BarChart3,
    Bell,
    Cloud,
    Cpu,
    Database,
    FolderKanban,
    GitBranch,
    Globe,
    HeartPulse,
    Rocket,
    Server,
    type LucideIcon,
} from 'lucide-vue-next';

/**
 * Curated 12-icon palette for projects. Mirrors the validation list in
 * `App\Http\Requests\Projects\StoreProjectRequest::rules()` — keep the
 * two in sync. A whitelist (vs a full `import * as`) preserves
 * tree-shaking; only these 12 icons land in the bundle.
 */
export const projectIconRegistry = {
    FolderKanban,
    Rocket,
    GitBranch,
    Server,
    Globe,
    BarChart3,
    Bell,
    Activity,
    HeartPulse,
    Cpu,
    Database,
    Cloud,
} as const satisfies Record<string, LucideIcon>;

export type ProjectIconName = keyof typeof projectIconRegistry;

export const projectIconNames: readonly ProjectIconName[] = Object.keys(
    projectIconRegistry,
) as ProjectIconName[];

/** Resolve an icon-name string to its Lucide component, or `null` if unknown. */
export function projectIcon(name: string | null | undefined): LucideIcon | null {
    if (!name) return null;
    return projectIconRegistry[name as ProjectIconName] ?? null;
}
