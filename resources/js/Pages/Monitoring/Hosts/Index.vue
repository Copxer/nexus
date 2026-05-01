<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import { hostStatusTone as statusTone } from '@/lib/hostStyles';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Server, ShieldAlert, ShieldCheck } from 'lucide-vue-next';

interface ProjectChip {
    id: number;
    slug: string;
    name: string;
    color: string | null;
    icon: string | null;
}

interface ActiveAgentToken {
    id: number;
    name: string | null;
    last_used_at: string | null;
    created_at: string | null;
}

interface HostRow {
    id: number;
    name: string;
    slug: string;
    provider: string | null;
    connection_type: string | null;
    status:
        | 'pending'
        | 'online'
        | 'offline'
        | 'degraded'
        | 'archived'
        | string
        | null;
    last_seen_at: string | null;
    project: ProjectChip | null;
    active_agent_token: ActiveAgentToken | null;
}

defineProps<{
    hosts: HostRow[];
}>();

const projectAccentClass = (color: string | null) =>
    (
        ({
            cyan: 'text-accent-cyan',
            blue: 'text-accent-blue',
            purple: 'text-accent-purple',
            magenta: 'text-accent-magenta',
            success: 'text-status-success',
            warning: 'text-status-warning',
        }) as const
    )[color ?? ''] ?? 'text-text-muted';
</script>

<template>
    <Head title="Monitoring · Hosts" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <span
                    class="text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan"
                >
                    Phase 6
                </span>
                <h1 class="text-lg font-semibold text-text-primary">
                    Hosts
                </h1>
            </div>
        </template>

        <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
            <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
                <div class="flex flex-col gap-2">
                    <h2 class="text-xl font-semibold text-text-primary">
                        Docker hosts
                    </h2>
                    <p class="text-sm text-text-secondary">
                        Hosts running the Nexus agent. Mint a token,
                        install the agent, and the host's CPU, memory,
                        and containers stream into Nexus. Telemetry
                        ingestion lands in spec 027.
                    </p>
                </div>
                <Link
                    :href="route('monitoring.hosts.create')"
                    class="inline-flex items-center gap-2 rounded-lg border border-accent-cyan/40 bg-accent-cyan/15 px-3 py-2 text-sm font-semibold text-accent-cyan transition hover:border-accent-cyan/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                >
                    <Plus class="h-4 w-4" aria-hidden="true" />
                    Add host
                </Link>
            </header>

            <div
                v-if="hosts.length === 0"
                class="glass-card flex flex-col items-center justify-center gap-3 px-6 py-16 text-center"
            >
                <span
                    class="flex h-12 w-12 items-center justify-center rounded-full border border-border-subtle bg-slate-950/60"
                >
                    <Server
                        class="h-5 w-5 text-text-muted"
                        aria-hidden="true"
                    />
                </span>
                <p class="text-sm font-medium text-text-secondary">
                    No hosts yet
                </p>
                <p class="max-w-sm text-xs text-text-muted">
                    Add your first host to start tracking Docker
                    container health, CPU, and memory across your
                    infrastructure.
                </p>
            </div>

            <ul v-else class="flex flex-col gap-2">
                <li v-for="host in hosts" :key="host.id">
                    <Link
                        :href="route('monitoring.hosts.show', host.id)"
                        class="glass-card flex items-center gap-4 px-4 py-3 transition hover:border-accent-cyan/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    >
                        <Server
                            class="h-4 w-4 shrink-0 text-text-muted"
                            aria-hidden="true"
                        />
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <span
                                    class="truncate text-sm font-semibold text-text-primary"
                                >
                                    {{ host.name }}
                                </span>
                                <span
                                    v-if="host.project"
                                    class="inline-flex items-center gap-1 rounded-full border border-current/30 px-1.5 py-0.5 text-[10px] font-mono uppercase tracking-[0.18em]"
                                    :class="projectAccentClass(host.project.color)"
                                >
                                    {{ host.project.name }}
                                </span>
                            </div>
                            <p
                                class="flex flex-wrap items-center gap-x-2 gap-y-1 truncate text-xs text-text-muted"
                            >
                                <span
                                    v-if="host.provider"
                                    class="font-mono text-text-secondary"
                                >
                                    {{ host.provider }}
                                </span>
                                <span
                                    v-if="host.connection_type"
                                    class="font-mono uppercase"
                                >
                                    {{ host.connection_type }}
                                </span>
                                <span v-if="host.last_seen_at">
                                    · Last seen {{ host.last_seen_at }}
                                </span>
                                <span v-else class="text-text-muted/70">
                                    · Awaiting first telemetry
                                </span>
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span
                                v-if="host.active_agent_token"
                                class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-[0.2em] text-status-success"
                            >
                                <ShieldCheck
                                    class="h-3 w-3"
                                    aria-hidden="true"
                                />
                                Token
                            </span>
                            <span
                                v-else
                                class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-[0.2em] text-text-muted"
                            >
                                <ShieldAlert
                                    class="h-3 w-3"
                                    aria-hidden="true"
                                />
                                No token
                            </span>
                            <StatusBadge :tone="statusTone(host.status)">
                                {{ host.status }}
                            </StatusBadge>
                        </div>
                    </Link>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
