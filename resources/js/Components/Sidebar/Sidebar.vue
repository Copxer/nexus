<script setup lang="ts">
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import SidebarNavLink from '@/Components/Sidebar/SidebarNavLink.vue';
import SidebarSystemStatus from '@/Components/Sidebar/SidebarSystemStatus.vue';
import SidebarUserCard from '@/Components/Sidebar/SidebarUserCard.vue';
import { Link } from '@inertiajs/vue3';
import {
    Activity,
    BarChart3,
    Bell,
    FolderKanban,
    GitBranch,
    GitPullRequest,
    Globe,
    History,
    LayoutDashboard,
    Rocket,
    Server,
    Settings as SettingsIcon,
    X,
    type LucideIcon,
} from 'lucide-vue-next';

interface NavItem {
    label: string;
    icon: LucideIcon;
    href?: string;
    routeName?: string;
    disabled?: boolean;
    soonLabel?: string;
}

// Order locked to roadmap §7.6 (with Activity inserted between Alerts and
// Settings as the 12th slot — spec 018). Only Overview, Projects,
// Repositories, Issues & PRs, Activity, and Settings are wired this phase;
// the rest carry a "Soon" pill until their owning spec lands. The phase
// pill text helps readers see which spec will activate each item.
const nav: NavItem[] = [
    { label: 'Overview', icon: LayoutDashboard, routeName: 'overview' },
    { label: 'Projects', icon: FolderKanban, routeName: 'projects.index' },
    { label: 'Repositories', icon: GitBranch, routeName: 'repositories.index' },
    { label: 'Issues & PRs', icon: GitPullRequest, routeName: 'work-items.index' },
    { label: 'Pipelines', icon: Activity, disabled: true, soonLabel: 'Phase 4' },
    { label: 'Deployments', icon: Rocket, disabled: true, soonLabel: 'Phase 4' },
    { label: 'Hosts', icon: Server, disabled: true, soonLabel: 'Phase 6' },
    { label: 'Monitoring', icon: Globe, disabled: true, soonLabel: 'Phase 5' },
    { label: 'Analytics', icon: BarChart3, disabled: true, soonLabel: 'Phase 8' },
    { label: 'Alerts', icon: Bell, disabled: true, soonLabel: 'Phase 7' },
    { label: 'Activity', icon: History, routeName: 'activity.index' },
    { label: 'Settings', icon: SettingsIcon, routeName: 'settings.index' },
];

// Match the route exactly OR any sibling under the same resource family
// (so `/projects/foo` keeps the Projects nav lit). For non-resourceful
// routes like `overview` the wildcard match harmlessly returns false.
const isActive = (item: NavItem): boolean => {
    if (!item.routeName) return false;
    if (route().current(item.routeName)) return true;
    const family = item.routeName.split('.')[0];
    return route().current(`${family}.*`);
};

const itemHref = (item: NavItem) =>
    item.routeName ? route(item.routeName) : (item.href ?? '#');

defineProps<{
    /**
     * Visual variant for the slide-over drawer mode used on small screens.
     * In drawer mode the sidebar fills the screen height and uses solid
     * panel chrome rather than the glass column treatment.
     */
    variant?: 'column' | 'drawer';
}>();

defineEmits<{
    (e: 'close'): void;
}>();
</script>

<template>
    <!-- Drawer width is capped to 88vw at < sm so the backdrop click target
         stays generous on small phones (360px viewport → ~43px backdrop). -->
    <aside
        class="flex h-full flex-col gap-6 border-r border-border-subtle bg-background-panel px-4 py-6 backdrop-blur-xl"
        :class="variant === 'drawer' ? 'w-72 max-w-[88vw]' : 'w-60'"
    >
        <!-- Header row: wordmark, plus a close button when in drawer mode -->
        <div class="flex items-center justify-between gap-2">
            <Link
                :href="route('overview')"
                aria-label="Nexus home"
                class="flex items-center gap-3 rounded-lg px-2 py-1 transition hover:bg-background-panel-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
            >
                <ApplicationLogo
                    class="h-8 drop-shadow-[0_0_14px_rgba(34,211,238,0.55)]"
                />
            </Link>
            <button
                v-if="variant === 'drawer'"
                type="button"
                class="flex h-9 w-9 items-center justify-center rounded-lg border border-border-subtle bg-slate-950/40 text-text-muted transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                aria-label="Close navigation"
                @click="$emit('close')"
            >
                <X class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>

        <!-- Primary nav: scrollable region so the footer stays anchored -->
        <nav
            aria-label="Primary"
            class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto"
        >
            <SidebarNavLink
                v-for="item in nav"
                :key="item.label"
                :icon="item.icon"
                :href="itemHref(item)"
                :active="isActive(item)"
                :disabled="item.disabled"
                :soon-label="item.soonLabel"
            >
                {{ item.label }}
            </SidebarNavLink>
        </nav>

        <!-- Footer cluster: status + user -->
        <div class="flex flex-col gap-3">
            <SidebarSystemStatus />
            <SidebarUserCard />
        </div>
    </aside>
</template>
