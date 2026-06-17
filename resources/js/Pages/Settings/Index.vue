<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    ExternalLink,
    Github,
    Moon,
    Plug,
    PlugZap,
    ShieldCheck,
    Sun,
    SunMoon,
    Unplug,
    Webhook,
} from 'lucide-vue-next';
import type { PageProps } from '@/types';
import { computed, ref } from 'vue';

interface GithubConnectionShape {
    username: string;
    connected_at: string | null;
    expires_at: string | null;
    is_token_valid: boolean;
    scopes: string[];
    recent_repositories: {
        count: number;
        last_synced_at: string | null;
    };
}

const props = defineProps<{
    github: GithubConnectionShape | null;
}>();

const isConnected = computed(() => props.github !== null);
const isExpired = computed(
    () => props.github !== null && !props.github.is_token_valid,
);

// Spec 036 — per-user theme preference. Local ref tracks the
// optimistic selection; POST persists via ThemeController. AppLayout
// re-applies the `<html>` class on the next page navigation by reading
// the updated `auth.user.theme` shared prop.
const page = usePage<PageProps>();
const themeChoice = ref<'dark' | 'light' | 'system'>(
    page.props.auth?.user?.theme ?? 'dark',
);

const updateTheme = (next: 'dark' | 'light' | 'system') => {
    if (next === themeChoice.value) return;
    themeChoice.value = next;
    router.post(
        route('settings.theme.update'),
        { theme: next },
        { preserveScroll: true },
    );
};

const themeOptions = [
    { value: 'dark', label: 'Dark', icon: Moon, description: 'Original interface.' },
    { value: 'light', label: 'Light', icon: Sun, description: 'Baseline light surfaces.' },
    { value: 'system', label: 'System', icon: SunMoon, description: 'Match your OS preference.' },
] as const;

const disconnect = () => {
    if (
        !window.confirm(
            'Disconnect GitHub? Sync jobs will stop running until you reconnect.',
        )
    ) {
        return;
    }
    router.delete(route('integrations.github.disconnect'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Settings" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <span
                    class="text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan"
                >
                    Phase 2
                </span>
                <h1 class="text-lg font-semibold text-text-primary">Settings</h1>
            </div>
        </template>

        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <!-- Section header -->
            <header class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-text-primary">
                        Integrations
                    </h2>
                    <p class="mt-1 text-sm text-text-secondary">
                        Connect external services to bring real data into Nexus.
                    </p>
                </div>
            </header>

            <!-- Spec 037 — webhook deliveries entry point. Surfaced
                 here next to the GitHub connection because it's the
                 audit/forensic surface for that integration. -->
            <Link
                :href="route('settings.webhook-deliveries.index')"
                class="glass-card flex items-center justify-between gap-3 p-5 transition hover:border-accent-cyan/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
            >
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg border border-border-subtle bg-background-panel-hover">
                        <Webhook class="h-5 w-5 text-accent-cyan" aria-hidden="true" />
                    </span>
                    <div class="flex min-w-0 flex-col">
                        <span class="text-sm font-semibold text-text-primary">
                            Webhook deliveries
                        </span>
                        <span class="text-xs text-text-muted">
                            Inspect + retry GitHub webhook deliveries.
                        </span>
                    </div>
                </div>
                <ExternalLink class="h-4 w-4 text-text-muted" aria-hidden="true" />
            </Link>

            <!-- GitHub Integration card -->
            <section
                aria-label="GitHub integration"
                class="glass-card flex flex-col gap-5 p-6 sm:p-8"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-start gap-4">
                        <span
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-border-subtle bg-background-panel-hover"
                        >
                            <Github
                                class="h-6 w-6 text-text-primary"
                                aria-hidden="true"
                            />
                        </span>
                        <div class="flex min-w-0 flex-col gap-2">
                            <div class="flex flex-wrap items-center gap-3">
                                <h3
                                    class="text-base font-semibold text-text-primary"
                                >
                                    GitHub
                                </h3>
                                <StatusBadge
                                    v-if="isConnected && !isExpired"
                                    tone="success"
                                >
                                    Connected
                                </StatusBadge>
                                <StatusBadge
                                    v-else-if="isConnected && isExpired"
                                    tone="warning"
                                >
                                    Expired
                                </StatusBadge>
                                <StatusBadge v-else tone="muted">
                                    Disconnected
                                </StatusBadge>
                            </div>
                            <p class="text-sm text-text-secondary">
                                Bring repositories, issues, and pull requests
                                into Nexus.
                            </p>
                        </div>
                    </div>

                    <!-- Action zone -->
                    <div class="flex items-center gap-2">
                        <a
                            v-if="!isConnected"
                            :href="route('integrations.github.connect')"
                            class="inline-flex items-center gap-2 rounded-lg border border-accent-cyan/40 bg-accent-cyan/15 px-3 py-2 text-sm font-semibold text-accent-cyan transition hover:border-accent-cyan/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        >
                            <Plug class="h-4 w-4" aria-hidden="true" />
                            Connect GitHub
                        </a>
                        <a
                            v-else-if="isExpired"
                            :href="route('integrations.github.connect')"
                            class="inline-flex items-center gap-2 rounded-lg border border-status-warning/40 bg-status-warning/10 px-3 py-2 text-sm font-semibold text-status-warning transition hover:bg-status-warning/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-status-warning/60"
                        >
                            <PlugZap class="h-4 w-4" aria-hidden="true" />
                            Reconnect
                        </a>
                        <button
                            v-if="isConnected"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-status-danger/40 bg-status-danger/10 px-3 py-2 text-sm font-semibold text-status-danger transition hover:bg-status-danger/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-status-danger/60"
                            @click="disconnect"
                        >
                            <Unplug class="h-4 w-4" aria-hidden="true" />
                            Disconnect
                        </button>
                    </div>
                </div>

                <!-- Connected metadata strip -->
                <dl
                    v-if="github !== null"
                    class="grid grid-cols-2 gap-4 border-t border-border-subtle pt-5 text-sm md:grid-cols-4"
                >
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Account
                        </dt>
                        <dd
                            class="flex items-center gap-2 font-mono text-text-secondary"
                        >
                            @{{ github.username }}
                            <a
                                :href="`https://github.com/${github.username}`"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-text-muted transition hover:text-accent-cyan"
                                aria-label="Open GitHub profile"
                            >
                                <ExternalLink
                                    class="h-3.5 w-3.5"
                                    aria-hidden="true"
                                />
                            </a>
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Connected
                        </dt>
                        <dd class="text-text-secondary">
                            {{ github.connected_at ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Token expiry
                        </dt>
                        <dd
                            class="flex items-center gap-2 text-text-secondary"
                        >
                            <CheckCircle2
                                v-if="!isExpired"
                                class="h-3.5 w-3.5 text-status-success"
                                aria-hidden="true"
                            />
                            <AlertTriangle
                                v-else
                                class="h-3.5 w-3.5 text-status-warning"
                                aria-hidden="true"
                            />
                            <span>
                                {{ github.expires_at ?? 'No expiry set' }}
                            </span>
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Scopes
                        </dt>
                        <dd
                            class="flex flex-wrap gap-1.5 text-[11px] text-text-secondary"
                        >
                            <span
                                v-for="scope in github.scopes"
                                :key="scope"
                                class="inline-flex items-center gap-1 rounded-full border border-border-subtle bg-background-panel-hover/40 px-2 py-0.5 font-mono"
                            >
                                <ShieldCheck
                                    class="h-3 w-3 text-accent-cyan"
                                    aria-hidden="true"
                                />
                                {{ scope }}
                            </span>
                            <span
                                v-if="github.scopes.length === 0"
                                class="text-text-muted"
                            >
                                —
                            </span>
                        </dd>
                    </div>
                </dl>

                <!-- Repository sync indicator (spec 014). Only renders
                     once at least one repo is linked under the user's
                     projects — keeps the card honest pre-import. -->
                <p
                    v-if="github !== null && github.recent_repositories.count > 0"
                    class="rounded-lg border border-border-subtle bg-background-panel-hover/40 p-4 text-xs text-text-muted"
                >
                    <span class="font-mono text-text-secondary">
                        {{ github.recent_repositories.count }}
                    </span>
                    {{ github.recent_repositories.count === 1 ? 'repository' : 'repositories' }}
                    linked across your projects.
                    <span v-if="github.recent_repositories.last_synced_at">
                        Last sync
                        <span class="font-mono text-text-secondary">
                            {{ github.recent_repositories.last_synced_at }}</span>.
                    </span>
                </p>

                <!-- Disconnected helper text + roadmap pointer -->
                <p
                    v-else
                    class="rounded-lg border border-dashed border-border-subtle bg-background-panel-hover/30 p-4 text-xs text-text-muted"
                >
                    Once connected, Nexus can list and import repositories
                    (spec 014), then sync issues + pull requests into the
                    Work Items page (specs 015–016).
                </p>
            </section>

            <!-- Section header — Appearance -->
            <header class="flex items-center justify-between gap-3 pt-2">
                <div>
                    <h2 class="text-base font-semibold text-text-primary">
                        Appearance
                    </h2>
                    <p class="mt-1 text-sm text-text-secondary">
                        Pick the surface palette Nexus renders in.
                    </p>
                </div>
            </header>

            <section class="glass-card p-6 sm:p-8">
                <fieldset class="flex flex-col gap-3">
                    <legend class="sr-only">Theme</legend>
                    <div
                        class="grid grid-cols-1 gap-3 sm:grid-cols-3"
                        role="radiogroup"
                        aria-label="Theme preference"
                    >
                        <button
                            v-for="opt in themeOptions"
                            :key="opt.value"
                            type="button"
                            role="radio"
                            :aria-checked="themeChoice === opt.value"
                            class="flex items-center gap-3 rounded-lg border bg-background-panel-hover px-4 py-3 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                            :class="
                                themeChoice === opt.value
                                    ? 'border-accent-cyan/50'
                                    : 'border-border-subtle hover:border-accent-cyan/30'
                            "
                            @click="updateTheme(opt.value)"
                        >
                            <component
                                :is="opt.icon"
                                class="h-4 w-4 shrink-0"
                                :class="
                                    themeChoice === opt.value
                                        ? 'text-accent-cyan'
                                        : 'text-text-muted'
                                "
                                aria-hidden="true"
                            />
                            <span class="flex min-w-0 flex-col">
                                <span class="text-sm font-semibold text-text-primary">
                                    {{ opt.label }}
                                </span>
                                <span class="truncate text-[11px] text-text-muted">
                                    {{ opt.description }}
                                </span>
                            </span>
                        </button>
                    </div>
                </fieldset>
            </section>
        </div>
    </AppLayout>
</template>
