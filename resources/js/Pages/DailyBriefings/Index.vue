<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, Inbox, Sparkles } from 'lucide-vue-next';
import { ref } from 'vue';

interface ChannelRef {
    kind: string;
    kind_label: string;
    name: string;
}

interface BriefingRow {
    id: number;
    briefing_date: string;
    status: 'pending' | 'generated' | 'delivered' | 'failed' | 'skipped';
    status_tone: 'success' | 'warning' | 'danger' | 'info' | 'muted';
    channel: ChannelRef | null;
    summary_preview: string;
    summary: string;
    highlights: string[];
    risks: string[];
    generated_at: string | null;
    delivered_at: string | null;
    prompt_version: string;
    error_message: string | null;
}

defineProps<{
    briefings: BriefingRow[];
}>();

const selectedBriefingId = ref<number | null>(null);

const toggleBriefing = (briefing: BriefingRow) => {
    selectedBriefingId.value = selectedBriefingId.value === briefing.id ? null : briefing.id;
};
</script>

<template>
    <Head title="Daily briefings" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <h1 class="text-lg font-semibold text-text-primary">Daily briefings</h1>
            </div>
        </template>

        <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
            <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-col gap-2">
                    <h2 class="flex items-center gap-2 text-xl font-semibold text-text-primary">
                        <Sparkles class="h-5 w-5 text-accent-cyan" aria-hidden="true" />
                        Briefing history
                    </h2>
                    <p class="text-sm text-text-secondary">
                        Review generated morning digests and delivery status from inside Nexus.
                    </p>
                </div>
                <Link
                    :href="route('settings.daily-briefing.index')"
                    class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm font-semibold text-text-secondary transition hover:border-accent-cyan/50 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                >
                    Settings
                </Link>
            </header>

            <section v-if="briefings.length === 0" class="glass-card flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full border border-border-subtle bg-slate-950/60">
                    <Inbox class="h-5 w-5 text-text-muted" aria-hidden="true" />
                </span>
                <p class="text-sm font-medium text-text-secondary">No generated briefings yet</p>
                <p class="max-w-sm text-xs text-text-muted">
                    Once a daily briefing is generated, it will appear here even if delivery later fails.
                </p>
            </section>

            <ul v-else class="flex flex-col gap-3">
                <li
                    v-for="briefing in briefings"
                    :key="briefing.id"
                    class="glass-card p-4 transition hover:border-accent-cyan/40"
                >
                    <button
                        type="button"
                        class="flex w-full flex-col gap-3 text-left sm:flex-row sm:items-start sm:justify-between"
                        @click="toggleBriefing(briefing)"
                    >
                        <div class="flex min-w-0 flex-1 flex-col gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1 font-mono text-sm text-text-primary">
                                    <CalendarDays class="h-4 w-4 text-accent-cyan" aria-hidden="true" />
                                    {{ briefing.briefing_date }}
                                </span>
                                <StatusBadge :tone="briefing.status_tone">
                                    {{ briefing.status }}
                                </StatusBadge>
                            </div>
                            <p class="line-clamp-2 text-sm text-text-secondary">
                                {{ briefing.summary_preview }}
                            </p>
                            <p class="text-[11px] text-text-muted">
                                Channel:
                                <span v-if="briefing.channel" class="text-text-secondary">
                                    {{ briefing.channel.name }} ({{ briefing.channel.kind_label }})
                                </span>
                                <span v-else>No verified channel configured</span>
                            </p>
                        </div>
                        <dl class="grid shrink-0 grid-cols-2 gap-3 text-right text-[11px] sm:min-w-48">
                            <div>
                                <dt class="font-semibold uppercase tracking-[0.18em] text-text-muted">Generated</dt>
                                <dd class="text-text-secondary">{{ briefing.generated_at ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold uppercase tracking-[0.18em] text-text-muted">Delivered</dt>
                                <dd class="text-text-secondary">{{ briefing.delivered_at ?? '—' }}</dd>
                            </div>
                        </dl>
                    </button>

                    <section
                        v-if="selectedBriefingId === briefing.id"
                        class="mt-4 space-y-4 border-t border-border-subtle pt-4"
                    >
                        <p v-if="briefing.error_message" class="rounded-lg border border-status-danger/30 bg-status-danger/10 p-3 text-xs text-status-danger">
                            {{ briefing.error_message }}
                        </p>
                        <div>
                            <h3 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">Summary</h3>
                            <p class="whitespace-pre-line text-sm leading-6 text-text-secondary">
                                {{ briefing.summary }}
                            </p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <h3 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">Highlights</h3>
                                <ul v-if="briefing.highlights.length > 0" class="list-disc space-y-1 pl-4 text-sm text-text-secondary">
                                    <li v-for="highlight in briefing.highlights" :key="highlight">
                                        {{ highlight }}
                                    </li>
                                </ul>
                                <p v-else class="text-sm text-text-muted">No highlights recorded.</p>
                            </div>
                            <div>
                                <h3 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">Risks</h3>
                                <ul v-if="briefing.risks.length > 0" class="list-disc space-y-1 pl-4 text-sm text-text-secondary">
                                    <li v-for="risk in briefing.risks" :key="risk">
                                        {{ risk }}
                                    </li>
                                </ul>
                                <p v-else class="text-sm text-text-muted">No risks recorded.</p>
                            </div>
                        </div>
                        <p class="text-[11px] text-text-muted">Prompt version: {{ briefing.prompt_version }}</p>
                    </section>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
