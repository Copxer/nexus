<script setup lang="ts">
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import SidebarNavLink from '@/Components/Sidebar/SidebarNavLink.vue';
import SidebarSystemStatus from '@/Components/Sidebar/SidebarSystemStatus.vue';
import SidebarUserCard from '@/Components/Sidebar/SidebarUserCard.vue';
import { Link } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    BarChart3,
    Bell,
    FolderKanban,
    GitBranch,
    GitPullRequest,
    Globe,
    LayoutDashboard,
    Rocket,
    Server,
    Settings as SettingsIcon,
    type LucideIcon,
} from 'lucide-vue-next';
import { computed } from 'vue';

interface NavItem {
    label: string;
    icon: LucideIcon;
    href?: string;
    routeName?: string;
    disabled?: boolean;
    soonLabel?: string;
}

// Order locked to roadmap §7.6. Only Overview is wired up this phase; the
// rest carry a "Soon" pill until their owning spec lands. The phase pill text
// helps tell readers which spec will activate each item.
const nav: NavItem[] = [
    { label: 'Overview', icon: LayoutDashboard, routeName: 'overview' },
    { label: 'Projects', icon: FolderKanban, disabled: true, soonLabel: 'Phase 1' },
    { label: 'Repositories', icon: GitBranch, disabled: true, soonLabel: 'Phase 1' },
    { label: 'Issues & PRs', icon: GitPullRequest, disabled: true, soonLabel: 'Phase 2' },
    { label: 'Pipelines', icon: Activity, disabled: true, soonLabel: 'Phase 4' },
    { label: 'Deployments', icon: Rocket, disabled: true, soonLabel: 'Phase 4' },
    { label: 'Hosts', icon: Server, disabled: true, soonLabel: 'Phase 6' },
    { label: 'Monitoring', icon: Globe, disabled: true, soonLabel: 'Phase 5' },
    { label: 'Analytics', icon: BarChart3, disabled: true, soonLabel: 'Phase 8' },
    { label: 'Alerts', icon: Bell, disabled: true, soonLabel: 'Phase 7' },
    { label: 'Settings', icon: SettingsIcon, disabled: true, soonLabel: 'Phase 1' },
];

const isActive = (item: NavItem) =>
    item.routeName ? route().current(item.routeName) : false;

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
</script>

<template>
    <aside
        class="flex h-full flex-col gap-6 overflow-y-auto border-r border-border-subtle bg-background-panel px-4 py-6 backdrop-blur-xl"
        :class="variant === 'drawer' ? 'w-72' : 'w-60'"
    >
        <!-- Wordmark -->
        <Link
            :href="route('overview')"
            aria-label="Nexus home"
            class="flex items-center gap-3 rounded-lg px-2 py-1 transition hover:bg-background-panel-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
        >
            <ApplicationLogo
                class="h-8 w-8 fill-current text-accent-cyan drop-shadow-[0_0_14px_rgba(34,211,238,0.55)]"
            />
            <span
                class="text-sm font-semibold uppercase tracking-[0.32em] text-text-secondary"
            >
                Nexus
            </span>
        </Link>

        <!-- Primary nav -->
        <nav aria-label="Primary" class="flex flex-1 flex-col gap-1">
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
