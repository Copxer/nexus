<script setup lang="ts">
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import TopBarSearch from '@/Components/TopBar/TopBarSearch.vue';
import type { PageProps } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Bell,
    ChevronDown,
    Clock,
    LogOut,
    Menu,
    PanelRight,
    UserCog,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted } from 'vue';

withDefaults(
    defineProps<{
        /** Hide the activity rail toggle when the page itself is the activity feed. */
        showActivityRailToggle?: boolean;
    }>(),
    { showActivityRailToggle: true },
);

const emit = defineEmits<{
    /** Mobile/tablet hamburger pressed — AppLayout opens the sidebar drawer. */
    (e: 'open-sidebar'): void;
    /** Tablet rail toggle pressed — AppLayout opens the activity rail drawer. */
    (e: 'open-activity-rail'): void;
    /** Search trigger pressed — AppLayout opens the command palette. */
    (e: 'open-palette'): void;
}>();

const page = usePage<PageProps>();
const user = computed(() => page.props.auth.user!);
const initials = computed(() => {
    const name = user.value.name ?? '';
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0]!.toUpperCase())
            .join('') || '?'
    );
});

// Spec 032 — `alerts.activeCount` is a shared Inertia prop populated
// by `HandleInertiaRequests::share()`. The Echo subscription below
// triggers a partial-reload of just this prop on every `AlertTriggered`
// / `AlertResolved` pulse so the badge updates across every page.
const activeAlertsCount = computed<number>(
    () => page.props.alerts?.activeCount ?? 0,
);

// ─── Reverb subscription (spec 032) ──────────────────────────────────
// Listens on `users.{id}.alerts` for both fresh triggers and resolves.
// Echo connection state is intentionally not tracked here — the bell
// is a low-stakes surface; "Live offline" presentation lives on the
// `/alerts` page itself.
let teardown: (() => void) | null = null;

onMounted(() => {
    if (typeof window === 'undefined' || !window.Echo) {
        return;
    }
    const userId = page.props.auth?.user?.id;
    if (userId == null) return;

    const channelName = `users.${userId}.alerts`;
    const channel = window.Echo.private(channelName);

    const reloadBadge = () => {
        router.reload({ only: ['alerts'] });
    };

    channel.listen('.AlertTriggered', reloadBadge);
    channel.listen('.AlertResolved', reloadBadge);

    teardown = () => {
        channel.stopListening('.AlertTriggered');
        channel.stopListening('.AlertResolved');
        window.Echo?.leave(channelName);
    };
});

onBeforeUnmount(() => {
    teardown?.();
});
</script>

<template>
    <header
        class="relative z-30 flex h-16 items-center gap-3 border-b border-border-subtle bg-background-panel px-4 backdrop-blur-xl sm:px-6"
    >
        <!-- Mobile hamburger (AppLayout listens) -->
        <button
            type="button"
            class="flex h-9 w-9 items-center justify-center rounded-lg border border-border-subtle bg-slate-950/40 text-text-muted transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60 lg:hidden"
            aria-label="Open navigation"
            @click="emit('open-sidebar')"
        >
            <Menu class="h-4 w-4" aria-hidden="true" />
        </button>

        <!-- Page title slot -->
        <div class="min-w-0 flex-1">
            <slot name="title">
                <h1 class="truncate text-lg font-semibold text-text-primary">
                    Overview
                </h1>
            </slot>
        </div>

        <!-- Search — opens the command palette (spec 005) -->
        <TopBarSearch @open-palette="emit('open-palette')" />

        <!-- Time-range pill — visual only -->
        <button
            type="button"
            class="hidden cursor-not-allowed items-center gap-2 rounded-lg border border-border-subtle bg-slate-950/40 px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-text-secondary transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60 sm:inline-flex"
            aria-label="Change time range"
            aria-disabled="true"
            title="Time range is fixed for this view."
        >
            <Clock class="h-3.5 w-3.5" aria-hidden="true" />
            24h
            <ChevronDown class="h-3 w-3" aria-hidden="true" />
        </button>

        <!-- Notifications — links to /alerts, badge reflects the user's
             open + acknowledged count, auto-updates on Reverb pulses. -->
        <Link
            :href="route('alerts.index')"
            class="relative flex h-9 w-9 items-center justify-center rounded-lg border border-border-subtle bg-slate-950/40 text-text-muted transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
            :aria-label="
                activeAlertsCount > 0
                    ? `${activeAlertsCount} active alert${activeAlertsCount === 1 ? '' : 's'}. Open the alerts page.`
                    : 'Open the alerts page.'
            "
        >
            <Bell class="h-4 w-4" aria-hidden="true" />
            <span
                v-if="activeAlertsCount > 0"
                class="absolute -end-1 -top-1 inline-flex min-w-[18px] items-center justify-center rounded-full border border-background-base bg-status-danger px-1 font-mono text-[10px] font-semibold text-text-primary"
            >
                {{ activeAlertsCount > 9 ? '9+' : activeAlertsCount }}
            </span>
        </Link>

        <!-- Activity rail toggle — visible whenever the rail isn't a
             persistent column (i.e. below 2xl). Includes mobile so users
             can reach the populated feed via the drawer. Hidden on
             pages that *are* the feed (e.g. /activity) so the toggle
             doesn't open a redundant drawer. -->
        <button
            v-if="showActivityRailToggle"
            type="button"
            class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-border-subtle bg-slate-950/40 text-text-muted transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60 2xl:hidden"
            aria-label="Toggle activity rail"
            @click="emit('open-activity-rail')"
        >
            <PanelRight class="h-4 w-4" aria-hidden="true" />
        </button>

        <!-- Avatar dropdown — same options as the sidebar user card -->
        <Dropdown align="right" width="48">
            <template #trigger>
                <button
                    type="button"
                    class="flex h-9 w-9 items-center justify-center rounded-lg border border-accent-cyan/30 bg-accent-cyan/10 font-mono text-xs font-semibold text-accent-cyan transition hover:border-accent-cyan/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    :aria-label="`${user.name} account menu`"
                >
                    {{ initials }}
                </button>
            </template>
            <template #content>
                <DropdownLink :href="route('profile.edit')">
                    <span class="flex items-center gap-2">
                        <UserCog class="h-4 w-4" aria-hidden="true" />
                        Profile
                    </span>
                </DropdownLink>
                <DropdownLink :href="route('logout')" method="post" as="button">
                    <span class="flex items-center gap-2">
                        <LogOut class="h-4 w-4" aria-hidden="true" />
                        Log out
                    </span>
                </DropdownLink>
            </template>
        </Dropdown>
    </header>
</template>
