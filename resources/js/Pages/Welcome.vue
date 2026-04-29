<script setup lang="ts">
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import type { PageProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    canLogin?: boolean;
    canRegister?: boolean;
}>();

const page = usePage<PageProps>();
const isAuthenticated = computed(() => page.props.auth?.user != null);

interface Capability {
    title: string;
    description: string;
    accent: 'cyan' | 'purple' | 'magenta' | 'success' | 'warning' | 'danger';
}

const capabilities: Capability[] = [
    {
        title: 'Repository risk',
        description:
            'Designed to surface stale PRs, failing workflows, and unattended issues — per repo, before standup.',
        accent: 'cyan',
    },
    {
        title: 'Deployment timeline',
        description:
            'A single timeline of every release, every environment. Failed runs and rollbacks will be unmissable.',
        accent: 'purple',
    },
    {
        title: 'Website performance',
        description:
            'Probes for uptime, response time, and TLS health, charted against your SLA targets — coming with phase 5.',
        accent: 'success',
    },
    {
        title: 'Container metrics',
        description:
            'Built to ingest CPU, memory, network, and health from every Docker host through a lightweight agent — no public API exposure required.',
        accent: 'magenta',
    },
    {
        title: 'Active alerts',
        description:
            'A single queue for warnings and critical incidents from webhooks, probes, and host agents — acknowledge or resolve in one click.',
        accent: 'danger',
    },
    {
        title: 'Activity heatmap',
        description:
            'When and where the system is busiest, at a glance — to give post-incident reviews and capacity planning a real starting point.',
        accent: 'warning',
    },
];

// Decorative dots only — per visual-reference.md, glow is reserved for active /
// critical / online states. Keep these flat so the hero CTA remains the only
// neon surface on the page.
const accentClasses: Record<Capability['accent'], { dot: string; ring: string }> = {
    cyan: {
        dot: 'bg-accent-cyan',
        ring: 'ring-accent-cyan/40',
    },
    purple: {
        dot: 'bg-accent-purple',
        ring: 'ring-accent-purple/40',
    },
    magenta: {
        dot: 'bg-accent-magenta',
        ring: 'ring-accent-magenta/40',
    },
    success: {
        dot: 'bg-status-success',
        ring: 'ring-status-success/40',
    },
    warning: {
        dot: 'bg-status-warning',
        ring: 'ring-status-warning/40',
    },
    danger: {
        dot: 'bg-status-danger',
        ring: 'ring-status-danger/40',
    },
};
</script>

<template>
    <Head title="Welcome" />

    <div
        class="relative isolate flex min-h-screen flex-col overflow-hidden bg-app-gradient"
    >
        <!-- Ambient neon background. Decorative only. -->
        <div
            aria-hidden="true"
            class="pointer-events-none absolute -top-32 left-1/2 h-[640px] w-[640px] -translate-x-1/2 rounded-full bg-accent-cyan/10 blur-3xl"
        />
        <div
            aria-hidden="true"
            class="pointer-events-none absolute -bottom-40 right-[-10%] h-[520px] w-[520px] rounded-full bg-accent-purple/15 blur-3xl"
        />
        <div
            aria-hidden="true"
            class="pointer-events-none absolute -bottom-40 left-[-10%] h-[420px] w-[420px] rounded-full bg-accent-magenta/10 blur-3xl"
        />

        <!-- Top bar -->
        <header class="relative z-10">
            <div
                class="mx-auto flex max-w-7xl items-center justify-between px-6 py-6 sm:px-10"
            >
                <Link
                    href="/"
                    aria-label="Nexus home"
                    class="flex items-center gap-3"
                >
                    <ApplicationLogo
                        class="h-10 drop-shadow-[0_0_18px_rgba(34,211,238,0.55)]"
                    />
                </Link>

                <nav v-if="canLogin" class="flex items-center gap-2">
                    <template v-if="isAuthenticated">
                        <Link
                            :href="route('overview')"
                            class="inline-flex items-center justify-center rounded-lg border border-accent-cyan/40 bg-accent-cyan/10 px-4 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-accent-cyan shadow-glow-cyan transition hover:border-accent-cyan/70 hover:bg-accent-cyan/20"
                        >
                            Open Overview
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            :href="route('login')"
                            class="rounded-lg px-4 py-2 text-sm font-medium text-text-secondary transition hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        >
                            Log in
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="inline-flex items-center justify-center rounded-lg border border-accent-cyan/40 bg-accent-cyan/10 px-4 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-accent-cyan shadow-glow-cyan transition hover:border-accent-cyan/70 hover:bg-accent-cyan/20"
                        >
                            Get started
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative z-10">
            <div
                class="mx-auto flex max-w-5xl flex-col items-center px-6 pb-16 pt-12 text-center sm:px-10 sm:pt-20"
            >
                <span
                    class="inline-flex items-center gap-2 rounded-full border border-accent-cyan/30 bg-accent-cyan/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan"
                >
                    <span class="h-1.5 w-1.5 rounded-full bg-accent-cyan" />
                    Engineering command center · in active development
                </span>

                <h1
                    class="mt-6 max-w-4xl text-balance text-4xl font-semibold leading-[1.1] text-text-primary sm:text-5xl lg:text-6xl"
                >
                    See your entire engineering world from
                    <span
                        class="bg-gradient-to-r from-accent-cyan via-accent-purple to-accent-magenta bg-clip-text text-transparent"
                        >one futuristic dashboard</span
                    >.
                </h1>

                <p
                    class="mt-6 max-w-2xl text-pretty text-base text-text-secondary sm:text-lg"
                >
                    A single command center for GitHub repositories,
                    deployments, Docker hosts, website probes, and alerts —
                    being built phase by phase, in the open. Authentication and
                    the visual foundation are live; the integrations are next.
                </p>

                <div
                    class="mt-10 flex flex-wrap items-center justify-center gap-3"
                >
                    <template v-if="isAuthenticated">
                        <Link
                            :href="route('overview')"
                            class="inline-flex items-center justify-center rounded-lg border border-accent-cyan/40 bg-accent-cyan/10 px-6 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-accent-cyan shadow-glow-cyan transition hover:border-accent-cyan/70 hover:bg-accent-cyan/20"
                        >
                            Open Overview
                        </Link>
                    </template>
                    <template v-else-if="canLogin">
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="inline-flex items-center justify-center rounded-lg border border-accent-cyan/40 bg-accent-cyan/10 px-6 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-accent-cyan shadow-glow-cyan transition hover:border-accent-cyan/70 hover:bg-accent-cyan/20"
                        >
                            Create your control center
                        </Link>
                        <Link
                            :href="route('login')"
                            class="inline-flex items-center justify-center rounded-lg border border-border-subtle bg-slate-900/60 px-6 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary"
                        >
                            I already have an account
                        </Link>
                    </template>
                </div>
            </div>
        </section>

        <!-- Capability grid -->
        <section class="relative z-10 px-6 pb-24 sm:px-10">
            <div class="mx-auto max-w-6xl">
                <div
                    class="mb-10 flex flex-col gap-2 text-center sm:text-start"
                >
                    <span
                        class="text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan"
                    >
                        What Nexus is being built to answer
                    </span>
                    <h2
                        class="text-2xl font-semibold text-text-primary sm:text-3xl"
                    >
                        Six questions, one cockpit
                    </h2>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <article
                        v-for="capability in capabilities"
                        :key="capability.title"
                        class="glass-card p-6"
                    >
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-10 w-10 items-center justify-center rounded-xl border border-border-subtle bg-slate-950/60 ring-1 transition"
                                :class="accentClasses[capability.accent].ring"
                            >
                                <span
                                    class="h-2.5 w-2.5 rounded-full"
                                    :class="accentClasses[capability.accent].dot"
                                />
                            </span>
                            <h3
                                class="text-base font-semibold text-text-primary"
                            >
                                {{ capability.title }}
                            </h3>
                        </div>
                        <p
                            class="mt-4 text-sm leading-relaxed text-text-secondary"
                        >
                            {{ capability.description }}
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="relative z-10 mt-auto border-t border-border-subtle">
            <div
                class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-3 px-6 py-6 text-xs text-text-secondary sm:flex-row sm:px-10"
            >
                <span>
                    Nexus Control Center · An engineering operations cockpit.
                </span>
                <span class="font-mono uppercase tracking-[0.2em]">
                    Phase 0 · Foundation
                </span>
            </div>
        </footer>
    </div>
</template>
