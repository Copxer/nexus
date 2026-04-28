<script setup lang="ts">
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Bell,
    ChevronDown,
    Clock,
    LogOut,
    Menu,
    PanelRight,
    Search,
    UserCog,
} from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{
    /** Number of unread notifications to render in the bell badge. Visual only this spec. */
    notificationsCount?: number;
}>();

const emit = defineEmits<{
    /** Mobile/tablet hamburger pressed — AppLayout opens the sidebar drawer. */
    (e: 'open-sidebar'): void;
    /** Tablet rail toggle pressed — AppLayout opens the activity rail drawer. */
    (e: 'open-activity-rail'): void;
}>();

const page = usePage();
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

        <!-- Search — visual only; real search lands with the command palette in spec 005 -->
        <label
            class="relative hidden items-center md:flex"
            aria-label="Global search"
        >
            <Search
                class="pointer-events-none absolute start-3 h-4 w-4 text-text-muted"
                aria-hidden="true"
            />
            <input
                type="search"
                placeholder="Search projects, repos, hosts…"
                class="w-64 rounded-lg border border-border-subtle bg-slate-950/60 ps-9 pe-16 py-2 text-sm text-text-primary placeholder:text-text-muted shadow-inner shadow-black/20 transition focus:border-accent-cyan focus:ring-2 focus:ring-accent-cyan/40 lg:w-72"
                disabled
            />
            <span
                class="pointer-events-none absolute end-3 hidden font-mono text-[11px] text-text-muted lg:inline"
            >
                ⌘K
            </span>
        </label>

        <!-- Time-range pill — visual only -->
        <button
            type="button"
            class="hidden items-center gap-2 rounded-lg border border-border-subtle bg-slate-950/40 px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60 sm:inline-flex"
            aria-label="Change time range"
            disabled
        >
            <Clock class="h-3.5 w-3.5" aria-hidden="true" />
            24h
            <ChevronDown class="h-3 w-3" aria-hidden="true" />
        </button>

        <!-- Notifications -->
        <button
            type="button"
            class="relative flex h-9 w-9 items-center justify-center rounded-lg border border-border-subtle bg-slate-950/40 text-text-muted transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
            aria-label="Notifications"
            disabled
        >
            <Bell class="h-4 w-4" aria-hidden="true" />
            <span
                v-if="notificationsCount && notificationsCount > 0"
                class="absolute -end-1 -top-1 inline-flex min-w-[18px] items-center justify-center rounded-full border border-background-base bg-status-danger px-1 font-mono text-[10px] font-semibold text-white"
            >
                {{ notificationsCount > 9 ? '9+' : notificationsCount }}
            </span>
        </button>

        <!-- Activity rail toggle (visible only when rail is collapsed: tablet + laptop) -->
        <button
            type="button"
            class="hidden h-9 w-9 items-center justify-center rounded-lg border border-border-subtle bg-slate-950/40 text-text-muted transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60 md:inline-flex 2xl:hidden"
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
