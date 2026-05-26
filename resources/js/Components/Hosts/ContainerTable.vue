<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import { containerHealthTone, containerStateTone } from '@/lib/hostStyles';
import { Boxes } from 'lucide-vue-next';

interface ContainerRow {
    id: number;
    container_id: string;
    name: string;
    image: string;
    image_tag: string | null;
    status: string | null;
    state: string | null;
    health_status: string | null;
    cpu_percent: number | null;
    memory_usage_mb: number | null;
    memory_limit_mb: number | null;
    memory_percent: number | null;
    last_seen_at: string | null;
}

defineProps<{
    containers: ContainerRow[];
}>();

const formatPercent = (pct: number | null): string =>
    pct === null ? '—' : `${pct.toFixed(1)}%`;

const formatMemory = (usage: number | null, limit: number | null): string => {
    if (usage === null) return '—';
    return limit === null ? `${usage} MB` : `${usage} / ${limit} MB`;
};

const imageRef = (row: ContainerRow): string =>
    row.image_tag ? `${row.image}:${row.image_tag}` : row.image;

// `health_status` is meaningful only when the container declares a
// Docker HEALTHCHECK — 'none' / null is "no healthcheck", not a fault.
const hasHealth = (health: string | null): boolean =>
    health !== null && health !== 'none' && health !== '';
</script>

<template>
    <section class="glass-card flex flex-col gap-4 p-6">
        <header class="flex items-center justify-between gap-3">
            <h3
                class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.2em] text-text-secondary"
            >
                <Boxes class="h-3.5 w-3.5 text-accent-cyan" aria-hidden="true" />
                Containers
            </h3>
            <span class="text-[11px] text-text-muted">
                {{ containers.length }}
                {{ containers.length === 1 ? 'container' : 'containers' }}
            </span>
        </header>

        <p
            v-if="containers.length === 0"
            class="rounded-lg border border-dashed border-border-subtle px-4 py-8 text-center text-xs text-text-muted"
        >
            No containers reported. The agent posts the container list on its
            next telemetry tick.
        </p>

        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-xs">
                <thead>
                    <tr
                        class="border-b border-border-subtle font-mono uppercase tracking-[0.16em] text-text-muted"
                    >
                        <th class="px-2 py-2 font-medium">Container</th>
                        <th class="px-2 py-2 font-medium">State</th>
                        <th class="px-2 py-2 font-medium">CPU</th>
                        <th class="px-2 py-2 font-medium">Memory</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="container in containers"
                        :key="container.id"
                        class="border-b border-border-subtle/50 last:border-0"
                    >
                        <td class="px-2 py-2.5">
                            <div class="flex flex-col gap-0.5">
                                <span
                                    class="font-semibold text-text-primary"
                                >
                                    {{ container.name }}
                                </span>
                                <span class="truncate font-mono text-text-muted">
                                    {{ imageRef(container) }}
                                </span>
                            </div>
                        </td>
                        <td class="px-2 py-2.5">
                            <div class="flex flex-wrap items-center gap-1">
                                <StatusBadge
                                    :tone="containerStateTone(container.state)"
                                >
                                    {{ container.state ?? 'unknown' }}
                                </StatusBadge>
                                <StatusBadge
                                    v-if="hasHealth(container.health_status)"
                                    :tone="
                                        containerHealthTone(
                                            container.health_status,
                                        )
                                    "
                                >
                                    {{ container.health_status }}
                                </StatusBadge>
                            </div>
                        </td>
                        <td class="px-2 py-2.5 font-mono text-text-secondary">
                            {{ formatPercent(container.cpu_percent) }}
                        </td>
                        <td class="px-2 py-2.5 font-mono text-text-secondary">
                            {{
                                formatMemory(
                                    container.memory_usage_mb,
                                    container.memory_limit_mb,
                                )
                            }}
                            <span
                                v-if="container.memory_percent !== null"
                                class="text-text-muted"
                            >
                                · {{ container.memory_percent.toFixed(1) }}%
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
