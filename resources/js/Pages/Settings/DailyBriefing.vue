<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Bell, ChevronLeft, Clock, FolderKanban, Send, Sparkles } from 'lucide-vue-next';
import { ref } from 'vue';

type ChannelKind = 'email' | 'slack' | 'webhook';

interface Preference {
    enabled: boolean;
    delivery_time: string;
    timezone: string;
    channel_id: number | null;
    include_projects: number[];
    last_sent_for_date: string | null;
}

interface Channel {
    id: number;
    kind: ChannelKind;
    kind_label: string;
    name: string;
}

interface ProjectOption {
    id: number;
    name: string;
}

interface BriefingStatus {
    briefing_date: string;
    status: 'pending' | 'generated' | 'delivered' | 'failed' | 'skipped';
    generated_at: string | null;
    delivered_at: string | null;
    error_message: string | null;
}

const props = defineProps<{
    preference: Preference;
    channels: Channel[];
    projects: ProjectOption[];
    status: BriefingStatus | null;
    timezones: string[];
}>();

const form = ref({
    enabled: props.preference.enabled,
    delivery_time: props.preference.delivery_time,
    timezone: props.preference.timezone,
    channel_id: props.preference.channel_id,
    include_projects: [...props.preference.include_projects],
});

const save = () => {
    router.patch(route('settings.daily-briefing.update'), form.value, {
        preserveScroll: true,
    });
};

const sendTest = () => {
    router.post(route('settings.daily-briefing.test'), {}, { preserveScroll: true });
};

const statusTone = (status: BriefingStatus['status']) => {
    switch (status) {
        case 'delivered':
            return 'success';
        case 'failed':
            return 'danger';
        case 'skipped':
            return 'muted';
        default:
            return 'info';
    }
};
</script>

<template>
    <Head title="Daily briefing settings" />

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
                <h1 class="text-lg font-semibold text-text-primary">Daily briefing</h1>
            </div>
        </template>

        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header class="flex flex-col gap-1">
                <h2 class="flex items-center gap-2 text-xl font-semibold text-text-primary">
                    <Sparkles class="h-5 w-5 text-accent-cyan" aria-hidden="true" />
                    AI morning digest
                </h2>
                <p class="text-sm text-text-secondary">
                    Opt in, choose when Nexus should generate yesterday's briefing, and route it through a verified notification channel.
                </p>
            </header>

            <section class="glass-card p-5 sm:p-6">
                <form class="space-y-6" @submit.prevent="save">
                    <label class="flex items-start gap-3 rounded-lg border border-border-subtle bg-background-panel-hover/40 p-4">
                        <input v-model="form.enabled" type="checkbox" class="mt-1">
                        <span class="flex flex-col gap-1">
                            <span class="text-sm font-semibold text-text-primary">Enable daily briefing</span>
                            <span class="text-xs text-text-muted">Nexus only schedules briefings for users who explicitly opt in.</span>
                        </span>
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                            Delivery time
                            <input
                                v-model="form.delivery_time"
                                type="time"
                                required
                                class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                            >
                        </label>

                        <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                            Timezone
                            <select
                                v-model="form.timezone"
                                required
                                class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                            >
                                <option v-for="timezone in props.timezones" :key="timezone" :value="timezone">
                                    {{ timezone }}
                                </option>
                            </select>
                        </label>
                    </div>

                    <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                        Verified delivery channel
                        <select
                            v-model="form.channel_id"
                            class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        >
                            <option :value="null">First verified email channel</option>
                            <option v-for="channel in props.channels" :key="channel.id" :value="channel.id">
                                {{ channel.name }} ({{ channel.kind_label }})
                            </option>
                        </select>
                        <span class="text-xs normal-case tracking-normal text-text-muted">
                            Selected channels must stay enabled and verified. Fallback email is only used when no channel is selected.
                        </span>
                    </label>

                    <fieldset class="space-y-2">
                        <legend class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                            <FolderKanban class="h-3.5 w-3.5" aria-hidden="true" />
                            Project filter
                        </legend>
                        <div v-if="props.projects.length > 0" class="grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="project in props.projects"
                                :key="project.id"
                                class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover/40 px-3 py-2 text-sm text-text-secondary"
                            >
                                <input v-model="form.include_projects" type="checkbox" :value="project.id">
                                {{ project.name }}
                            </label>
                        </div>
                        <p class="text-xs text-text-muted">
                            Leave every project unchecked to include all of your projects.
                        </p>
                    </fieldset>

                    <div class="flex flex-wrap gap-3">
                        <button
                            type="submit"
                            class="inline-flex items-center gap-2 rounded-lg bg-accent-cyan px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-accent-cyan/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        >
                            <Clock class="h-4 w-4" aria-hidden="true" />
                            Save preferences
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-4 py-2 text-sm font-semibold text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!form.enabled"
                            @click="sendTest"
                        >
                            <Send class="h-4 w-4" aria-hidden="true" />
                            Send test briefing
                        </button>
                    </div>
                </form>
            </section>

            <section class="glass-card p-5 sm:p-6">
                <div class="flex items-start gap-3">
                    <Bell class="mt-0.5 h-5 w-5 text-accent-cyan" aria-hidden="true" />
                    <div class="flex min-w-0 flex-1 flex-col gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-text-primary">Latest briefing status</h3>
                            <StatusBadge v-if="props.status" :tone="statusTone(props.status.status)">
                                {{ props.status.status }}
                            </StatusBadge>
                            <StatusBadge v-else tone="muted">No briefing yet</StatusBadge>
                        </div>
                        <dl v-if="props.status" class="grid gap-3 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">Date</dt>
                                <dd class="font-mono text-text-secondary">{{ props.status.briefing_date }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">Generated</dt>
                                <dd class="text-text-secondary">{{ props.status.generated_at ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">Delivered</dt>
                                <dd class="text-text-secondary">{{ props.status.delivered_at ?? '—' }}</dd>
                            </div>
                        </dl>
                        <p v-if="props.preference.last_sent_for_date" class="text-xs text-text-muted">
                            Last successful send for {{ props.preference.last_sent_for_date }}.
                        </p>
                        <p v-if="props.status?.error_message" class="rounded-lg border border-status-danger/30 bg-status-danger/10 p-3 text-xs text-status-danger">
                            {{ props.status.error_message }}
                        </p>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
