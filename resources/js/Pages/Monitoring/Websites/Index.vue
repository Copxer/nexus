<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink, Globe, Plus } from 'lucide-vue-next';

interface ProjectChip {
    id: number;
    slug: string;
    name: string;
    color: string | null;
    icon: string | null;
}

interface WebsiteRow {
    id: number;
    name: string;
    url: string;
    method: string;
    status:
        | 'pending'
        | 'up'
        | 'down'
        | 'slow'
        | 'error'
        | string
        | null;
    last_checked_at: string | null;
    project: ProjectChip | null;
}

defineProps<{
    websites: WebsiteRow[];
}>();

const statusTone = (status: string | null) =>
    (
        ({
            pending: 'muted',
            up: 'success',
            down: 'danger',
            slow: 'warning',
            error: 'danger',
        }) as const
    )[status ?? ''] ?? 'muted';

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
    <Head title="Monitoring · Websites" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <span
                    class="text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan"
                >
                    Phase 5
                </span>
                <h1 class="text-lg font-semibold text-text-primary">
                    Monitoring
                </h1>
            </div>
        </template>

        <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
            <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
                <div class="flex flex-col gap-2">
                    <h2 class="text-xl font-semibold text-text-primary">
                        Website monitors
                    </h2>
                    <p class="text-sm text-text-secondary">
                        Health checks for the URLs you care about. Each
                        monitor records response time and status code on
                        every probe.
                    </p>
                </div>
                <Link
                    :href="route('monitoring.websites.create')"
                    class="inline-flex items-center gap-2 rounded-lg border border-accent-cyan/40 bg-accent-cyan/15 px-3 py-2 text-sm font-semibold text-accent-cyan transition hover:border-accent-cyan/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                >
                    <Plus class="h-4 w-4" aria-hidden="true" />
                    Add monitor
                </Link>
            </header>

            <div
                v-if="websites.length === 0"
                class="glass-card flex flex-col items-center justify-center gap-3 px-6 py-16 text-center"
            >
                <span
                    class="flex h-12 w-12 items-center justify-center rounded-full border border-border-subtle bg-slate-950/60"
                >
                    <Globe
                        class="h-5 w-5 text-text-muted"
                        aria-hidden="true"
                    />
                </span>
                <p class="text-sm font-medium text-text-secondary">
                    No website monitors yet
                </p>
                <p class="max-w-sm text-xs text-text-muted">
                    Add one to start tracking response times. Spec 024
                    will run scheduled checks every interval; for now
                    use the manual "Probe now" button on the detail
                    page.
                </p>
            </div>

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="website in websites"
                    :key="website.id"
                >
                    <Link
                        :href="route('monitoring.websites.show', website.id)"
                        class="glass-card flex items-center gap-4 px-4 py-3 transition hover:border-accent-cyan/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    >
                        <Globe
                            class="h-4 w-4 shrink-0 text-text-muted"
                            aria-hidden="true"
                        />
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <span
                                    class="truncate text-sm font-semibold text-text-primary"
                                >
                                    {{ website.name }}
                                </span>
                                <span
                                    v-if="website.project"
                                    class="inline-flex items-center gap-1 rounded-full border border-current/30 px-1.5 py-0.5 text-[10px] font-mono uppercase tracking-[0.18em]"
                                    :class="projectAccentClass(website.project.color)"
                                >
                                    {{ website.project.name }}
                                </span>
                            </div>
                            <p
                                class="flex flex-wrap items-center gap-x-2 gap-y-1 truncate text-xs text-text-muted"
                            >
                                <span class="font-mono text-text-secondary">
                                    {{ website.method }}
                                </span>
                                <span class="truncate font-mono">
                                    {{ website.url }}
                                </span>
                                <span v-if="website.last_checked_at">
                                    · Last checked
                                    {{ website.last_checked_at }}
                                </span>
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <StatusBadge :tone="statusTone(website.status)">
                                {{ website.status }}
                            </StatusBadge>
                            <a
                                :href="website.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-text-muted transition hover:text-accent-cyan"
                                :aria-label="`Open ${website.name} in a new tab`"
                                @click.stop
                            >
                                <ExternalLink
                                    class="h-4 w-4"
                                    aria-hidden="true"
                                />
                            </a>
                        </div>
                    </Link>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
