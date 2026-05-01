<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import AgentTokenPanel from '@/Components/Hosts/AgentTokenPanel.vue';
import { hostStatusTone as statusTone } from '@/lib/hostStyles';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ExternalLink,
    PencilLine,
    Trash2,
} from 'lucide-vue-next';

interface ActiveAgentToken {
    id: number;
    name: string | null;
    last_used_at: string | null;
    created_at: string | null;
}

interface ProjectChip {
    id: number;
    slug: string;
    name: string;
    color: string | null;
    icon: string | null;
}

interface HostPayload {
    id: number;
    name: string;
    slug: string;
    provider: string | null;
    endpoint_url: string | null;
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
    cpu_count: number | null;
    memory_total_mb: number | null;
    disk_total_gb: number | null;
    os: string | null;
    docker_version: string | null;
    project: ProjectChip | null;
    active_agent_token: ActiveAgentToken | null;
}

const props = defineProps<{
    host: HostPayload;
    canUpdate: boolean;
    canDelete: boolean;
    canManageTokens: boolean;
}>();

const archive = () => {
    if (
        !window.confirm(
            'Archive this host? Existing agent tokens will be revoked. Telemetry history is kept.',
        )
    ) {
        return;
    }
    router.delete(route('monitoring.hosts.destroy', props.host.id));
};
</script>

<template>
    <Head :title="`Host · ${host.name}`" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <Link
                    :href="route('monitoring.hosts.index')"
                    class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan transition hover:text-accent-cyan/80"
                >
                    <ChevronLeft class="h-3 w-3" aria-hidden="true" />
                    Hosts
                </Link>
                <h1 class="text-lg font-semibold text-text-primary">
                    {{ host.name }}
                </h1>
            </div>
        </template>

        <div
            class="mx-auto flex max-w-4xl flex-col gap-4 px-4 py-6 sm:px-6 lg:px-8"
        >
            <section class="glass-card flex flex-col gap-4 p-6">
                <header
                    class="flex flex-wrap items-start justify-between gap-3"
                >
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center gap-2">
                            <h2
                                class="text-xl font-semibold text-text-primary"
                            >
                                {{ host.name }}
                            </h2>
                            <StatusBadge :tone="statusTone(host.status)">
                                {{ host.status }}
                            </StatusBadge>
                        </div>
                        <p
                            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-text-muted"
                        >
                            <span
                                v-if="host.project"
                                class="font-mono uppercase tracking-[0.18em] text-text-secondary"
                            >
                                {{ host.project.name }}
                            </span>
                            <span v-if="host.provider">
                                · {{ host.provider }}
                            </span>
                            <span v-if="host.connection_type">
                                · {{ host.connection_type }} push
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
                        <Link
                            v-if="canUpdate"
                            :href="route('monitoring.hosts.edit', host.id)"
                            class="inline-flex items-center gap-1 rounded-md border border-border-subtle bg-background-panel-hover px-2 py-1 text-xs font-semibold text-text-secondary transition hover:border-accent-cyan/60 hover:text-text-primary"
                        >
                            <PencilLine
                                class="h-3 w-3"
                                aria-hidden="true"
                            />
                            Edit
                        </Link>
                        <button
                            v-if="canDelete"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-md border border-status-danger/40 bg-status-danger/10 px-2 py-1 text-xs font-semibold text-status-danger transition hover:border-status-danger/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-status-danger/60"
                            @click="archive"
                        >
                            <Trash2 class="h-3 w-3" aria-hidden="true" />
                            Archive
                        </button>
                    </div>
                </header>

                <dl
                    class="grid grid-cols-2 gap-4 border-t border-border-subtle pt-4 text-xs sm:grid-cols-4"
                >
                    <div class="flex flex-col gap-1">
                        <dt class="uppercase tracking-[0.18em] text-text-muted">
                            CPU cores
                        </dt>
                        <dd
                            class="font-mono text-sm text-text-primary"
                        >
                            {{ host.cpu_count ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt class="uppercase tracking-[0.18em] text-text-muted">
                            Memory
                        </dt>
                        <dd
                            class="font-mono text-sm text-text-primary"
                        >
                            {{
                                host.memory_total_mb !== null
                                    ? `${Math.round(host.memory_total_mb / 1024)} GB`
                                    : '—'
                            }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt class="uppercase tracking-[0.18em] text-text-muted">
                            Disk
                        </dt>
                        <dd
                            class="font-mono text-sm text-text-primary"
                        >
                            {{
                                host.disk_total_gb !== null
                                    ? `${host.disk_total_gb} GB`
                                    : '—'
                            }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt class="uppercase tracking-[0.18em] text-text-muted">
                            Docker
                        </dt>
                        <dd
                            class="font-mono text-sm text-text-primary"
                        >
                            {{ host.docker_version ?? '—' }}
                        </dd>
                    </div>
                </dl>

                <p
                    v-if="host.endpoint_url"
                    class="flex items-center gap-1 text-xs text-text-muted"
                >
                    Endpoint
                    <a
                        :href="host.endpoint_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-mono text-text-secondary transition hover:text-accent-cyan"
                    >
                        {{ host.endpoint_url }}
                        <ExternalLink
                            class="ml-1 inline h-3 w-3"
                            aria-hidden="true"
                        />
                    </a>
                </p>
            </section>

            <AgentTokenPanel
                :host-id="host.id"
                :active-token="host.active_agent_token"
                :can-manage-tokens="canManageTokens"
            />

            <section
                class="glass-card flex flex-col gap-2 border-dashed p-6 text-xs text-text-muted"
            >
                <p class="font-semibold uppercase tracking-[0.2em] text-text-secondary">
                    Coming in spec 027 / 028
                </p>
                <p>
                    Telemetry ingestion (CPU, memory, container stats)
                    and the host's container table arrive in the next
                    two specs of phase 6.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
