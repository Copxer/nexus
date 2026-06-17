<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertOctagon,
    ChevronLeft,
    Inbox,
    RefreshCw,
    Webhook,
} from 'lucide-vue-next';
import { ref } from 'vue';

type Status = 'received' | 'processed' | 'failed' | 'skipped';

interface DeliveryRow {
    id: number;
    github_delivery_id: string | null;
    event: string;
    action: string | null;
    repository_full_name: string | null;
    status: Status | null;
    status_tone: 'info' | 'success' | 'danger' | 'muted' | 'warning' | null;
    error_message: string | null;
    received_at: string | null;
    received_at_iso: string | null;
    processed_at: string | null;
}

interface Paginator {
    data: DeliveryRow[];
    current_page: number;
    last_page: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface FilterState {
    status: string;
    event: string;
    repository: string;
}

const props = defineProps<{
    deliveries: Paginator;
    filters: FilterState;
    filterOptions: {
        statuses: Status[];
        events: string[];
    };
}>();

const filterDraft = ref<FilterState>({ ...props.filters });

const applyFilters = () => {
    router.get(
        route('settings.webhook-deliveries.index'),
        Object.fromEntries(
            Object.entries(filterDraft.value).filter(
                ([, v]) => v !== null && v !== '',
            ),
        ),
        {
            preserveScroll: true,
            preserveState: true,
            only: ['deliveries', 'filters', 'filterOptions'],
        },
    );
};

const clearFilters = () => {
    filterDraft.value = { status: 'all', event: '', repository: '' };
    applyFilters();
};

const retry = (delivery: DeliveryRow) => {
    if (delivery.status !== 'failed') return;
    router.post(
        route('settings.webhook-deliveries.retry', { delivery: delivery.id }),
        {},
        { preserveScroll: true },
    );
};

const capitalize = (s: string | null) =>
    s ? s.charAt(0).toUpperCase() + s.slice(1) : s ?? '';
</script>

<template>
    <Head title="Webhook deliveries" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <Link
                    :href="route('settings.index')"
                    class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan transition hover:text-accent-cyan/80"
                >
                    <ChevronLeft class="h-3 w-3" aria-hidden="true" />
                    Settings
                </Link>
                <h1 class="text-lg font-semibold text-text-primary">
                    Webhook deliveries
                </h1>
            </div>
        </template>

        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-col gap-1">
                    <h2 class="flex items-center gap-2 text-xl font-semibold text-text-primary">
                        <Webhook
                            class="h-5 w-5 text-accent-cyan"
                            aria-hidden="true"
                        />
                        GitHub webhook deliveries
                    </h2>
                    <p class="text-sm text-text-secondary">
                        Inspect every delivery, see which handler ran, and re-queue
                        failed rows against their stored payload.
                    </p>
                </div>
            </header>

            <!-- Filter strip -->
            <section
                aria-label="Filters"
                class="glass-card flex flex-col gap-4 p-5 sm:flex-row sm:items-end"
            >
                <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                    Status
                    <select
                        v-model="filterDraft.status"
                        class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        @change="applyFilters"
                    >
                        <option value="all">All</option>
                        <option
                            v-for="status in props.filterOptions.statuses"
                            :key="status"
                            :value="status"
                        >
                            {{ capitalize(status) }}
                        </option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                    Event
                    <select
                        v-model="filterDraft.event"
                        class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        @change="applyFilters"
                    >
                        <option value="">All events</option>
                        <option
                            v-for="event in props.filterOptions.events"
                            :key="event"
                            :value="event"
                        >
                            {{ event }}
                        </option>
                    </select>
                </label>
                <label class="flex flex-1 flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                    Repository
                    <input
                        v-model="filterDraft.repository"
                        type="text"
                        placeholder="owner/repo"
                        class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        @keydown.enter="applyFilters"
                        @blur="applyFilters"
                    >
                </label>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm font-semibold text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    @click="clearFilters"
                >
                    Clear
                </button>
            </section>

            <!-- List -->
            <section
                v-if="props.deliveries.data.length > 0"
                class="glass-card overflow-hidden"
            >
                <ul class="divide-y divide-border-subtle">
                    <li
                        v-for="delivery in props.deliveries.data"
                        :key="delivery.id"
                        class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="flex min-w-0 flex-col gap-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <StatusBadge
                                    v-if="delivery.status_tone"
                                    :tone="delivery.status_tone"
                                >
                                    {{ capitalize(delivery.status) }}
                                </StatusBadge>
                                <span class="font-mono text-xs text-text-secondary">
                                    {{ delivery.event }}<template v-if="delivery.action">.{{ delivery.action }}</template>
                                </span>
                                <span
                                    v-if="delivery.repository_full_name"
                                    class="font-mono text-xs text-text-muted"
                                >
                                    {{ delivery.repository_full_name }}
                                </span>
                            </div>
                            <div class="flex items-center gap-3 text-[11px] text-text-muted">
                                <span v-if="delivery.received_at">
                                    Received {{ delivery.received_at }}
                                </span>
                                <span v-if="delivery.processed_at">
                                    · Processed {{ delivery.processed_at }}
                                </span>
                            </div>
                            <p
                                v-if="delivery.error_message"
                                class="flex items-start gap-2 text-xs text-status-danger"
                            >
                                <AlertOctagon
                                    class="mt-0.5 h-3.5 w-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="truncate">{{ delivery.error_message }}</span>
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button
                                v-if="delivery.status === 'failed'"
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-1.5 text-xs font-semibold text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                @click="retry(delivery)"
                            >
                                <RefreshCw class="h-3.5 w-3.5" aria-hidden="true" />
                                Retry
                            </button>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Empty -->
            <section
                v-else
                class="glass-card flex flex-col items-center gap-3 p-10 text-center"
            >
                <span
                    class="flex h-12 w-12 items-center justify-center rounded-full border border-border-subtle bg-slate-950/40"
                >
                    <Inbox class="h-5 w-5 text-text-muted" aria-hidden="true" />
                </span>
                <h3 class="text-base font-semibold text-text-primary">
                    No deliveries match
                </h3>
                <p class="max-w-sm text-sm text-text-muted">
                    Adjust the filters above or wait for GitHub to deliver a new
                    webhook. Connected repositories surface here automatically.
                </p>
            </section>

            <!-- Pagination -->
            <nav
                v-if="props.deliveries.last_page > 1"
                class="flex flex-wrap items-center justify-center gap-1"
                aria-label="Pagination"
            >
                <Link
                    v-for="link in props.deliveries.links"
                    :key="link.label"
                    :href="link.url ?? '#'"
                    :class="[
                        'inline-flex min-w-[2.25rem] items-center justify-center rounded-lg border px-3 py-1.5 text-xs font-semibold transition',
                        link.active
                            ? 'border-accent-cyan/50 bg-accent-cyan/10 text-accent-cyan'
                            : link.url
                              ? 'border-border-subtle bg-background-panel-hover text-text-secondary hover:border-accent-cyan/40 hover:text-text-primary'
                              : 'cursor-not-allowed border-border-subtle bg-background-panel-hover/40 text-text-muted',
                    ]"
                    :preserve-state="true"
                    :preserve-scroll="true"
                    v-html="link.label"
                />
            </nav>
        </div>
    </AppLayout>
</template>
