<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

type Band = 'operational' | 'degraded' | 'partial_outage' | 'major_outage';
type Severity = 'info' | 'warning' | 'critical';

interface Monitor {
    id: number;
    name: string;
    url: string;
    status: string | null;
    uptime_24h: number | null;
    uptime_7d: number | null;
    uptime_30d: number | null;
}

interface Incident {
    id: number;
    title: string;
    severity: Severity;
    status?: string;
    triggered_at: string | null;
    triggered_at_human?: string;
    resolved_at?: string | null;
    resolved_at_human?: string;
}

interface StatusPayload {
    project_id: number;
    project_name: string;
    project_slug: string;
    headline: string | null;
    overall_band: Band;
    overall_label: string;
    monitors: Monitor[];
    active_incidents: Incident[];
    recent_incidents: Incident[];
    last_updated_at: string;
}

const props = defineProps<{
    status: StatusPayload;
    flash?: { status?: string | null; error?: string | null };
}>();

const subscribeForm = useForm({
    email: '',
    honeypot: '',
});

const submitSubscribe = () => {
    subscribeForm.post(
        route('public-status.subscribe', { project: props.status.project_slug }),
        {
            preserveScroll: true,
            onSuccess: () => subscribeForm.reset('email'),
        },
    );
};

const bandBg: Record<Band, string> = {
    operational: 'bg-emerald-950/60 border-emerald-500/40 text-emerald-300',
    degraded: 'bg-cyan-950/60 border-cyan-500/40 text-cyan-300',
    partial_outage: 'bg-amber-950/60 border-amber-500/40 text-amber-300',
    major_outage: 'bg-rose-950/60 border-rose-500/40 text-rose-300',
};

const severityColor: Record<Severity, string> = {
    info: 'text-cyan-300',
    warning: 'text-amber-300',
    critical: 'text-rose-300',
};

const formatUptime = (v: number | null) => (v === null ? '—' : `${v.toFixed(2)}%`);
</script>

<template>
    <Head :title="`${status.project_name} status`" />

    <div class="min-h-screen bg-slate-950 text-slate-100">
        <main class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
            <header class="mb-8">
                <p class="text-[11px] font-semibold uppercase tracking-[0.32em] text-slate-500">
                    Status page
                </p>
                <h1 class="mt-1 text-2xl font-semibold text-slate-50">
                    {{ status.project_name }}
                </h1>
                <p v-if="status.headline" class="mt-3 text-sm text-slate-300">
                    {{ status.headline }}
                </p>
            </header>

            <!-- Overall band -->
            <section
                class="rounded-2xl border p-6 text-center"
                :class="bandBg[status.overall_band]"
            >
                <p class="text-lg font-semibold">{{ status.overall_label }}</p>
            </section>

            <!-- Flash messages -->
            <p
                v-if="props.flash?.status"
                class="mt-4 rounded-lg border border-emerald-500/40 bg-emerald-950/40 px-4 py-2 text-sm text-emerald-200"
                role="status"
            >
                {{ props.flash.status }}
            </p>
            <p
                v-if="props.flash?.error"
                class="mt-4 rounded-lg border border-rose-500/40 bg-rose-950/40 px-4 py-2 text-sm text-rose-200"
                role="alert"
            >
                {{ props.flash.error }}
            </p>

            <!-- Monitors -->
            <section v-if="status.monitors.length > 0" class="mt-8">
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                    Monitors
                </h2>
                <ul class="divide-y divide-slate-800 rounded-2xl border border-slate-800 bg-slate-900/50">
                    <li
                        v-for="monitor in status.monitors"
                        :key="monitor.id"
                        class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-50">{{ monitor.name }}</p>
                            <p class="truncate text-[11px] text-slate-500">{{ monitor.url }}</p>
                        </div>
                        <dl class="grid grid-cols-3 gap-4 text-right text-[11px] text-slate-400">
                            <div>
                                <dt class="uppercase tracking-[0.18em]">24h</dt>
                                <dd class="font-mono text-slate-100">{{ formatUptime(monitor.uptime_24h) }}</dd>
                            </div>
                            <div>
                                <dt class="uppercase tracking-[0.18em]">7d</dt>
                                <dd class="font-mono text-slate-100">{{ formatUptime(monitor.uptime_7d) }}</dd>
                            </div>
                            <div>
                                <dt class="uppercase tracking-[0.18em]">30d</dt>
                                <dd class="font-mono text-slate-100">{{ formatUptime(monitor.uptime_30d) }}</dd>
                            </div>
                        </dl>
                    </li>
                </ul>
            </section>

            <!-- Active incidents -->
            <section v-if="status.active_incidents.length > 0" class="mt-8">
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                    Active incidents
                </h2>
                <ul class="space-y-3">
                    <li
                        v-for="incident in status.active_incidents"
                        :key="incident.id"
                        class="rounded-2xl border border-slate-800 bg-slate-900/50 p-4"
                    >
                        <p class="text-sm font-semibold" :class="severityColor[incident.severity]">
                            {{ incident.title }}
                        </p>
                        <p class="mt-1 text-[11px] text-slate-500">
                            Opened {{ incident.triggered_at_human ?? incident.triggered_at }}
                        </p>
                    </li>
                </ul>
            </section>

            <!-- Recent incidents -->
            <section v-if="status.recent_incidents.length > 0" class="mt-8">
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                    Recent history
                </h2>
                <ul class="space-y-3">
                    <li
                        v-for="incident in status.recent_incidents"
                        :key="incident.id"
                        class="rounded-2xl border border-slate-800 bg-slate-900/50 p-4"
                    >
                        <p class="text-sm font-semibold" :class="severityColor[incident.severity]">
                            {{ incident.title }}
                        </p>
                        <p class="mt-1 text-[11px] text-slate-500">
                            Resolved {{ incident.resolved_at_human ?? incident.resolved_at }}
                        </p>
                    </li>
                </ul>
            </section>

            <!-- Subscribe form -->
            <section class="mt-10">
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                    Get incident notifications
                </h2>
                <form class="rounded-2xl border border-slate-800 bg-slate-900/50 p-4" @submit.prevent="submitSubscribe">
                    <label class="flex flex-col gap-2">
                        <span class="text-xs text-slate-400">
                            Enter your email — we'll send a confirmation link.
                        </span>
                        <input
                            v-model="subscribeForm.email"
                            type="email"
                            required
                            placeholder="you@example.com"
                            class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60"
                        >
                    </label>
                    <!-- Honeypot: real users leave this empty; bots fill it. -->
                    <input
                        v-model="subscribeForm.honeypot"
                        type="text"
                        name="honeypot"
                        tabindex="-1"
                        autocomplete="off"
                        aria-hidden="true"
                        class="hidden"
                    >
                    <p
                        v-if="subscribeForm.errors.email"
                        class="mt-2 text-xs text-rose-300"
                    >
                        {{ subscribeForm.errors.email }}
                    </p>
                    <button
                        type="submit"
                        :disabled="subscribeForm.processing"
                        class="mt-3 inline-flex items-center gap-2 rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400/60 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Subscribe
                    </button>
                </form>
            </section>

            <footer class="mt-12 border-t border-slate-800 pt-6 text-center text-[11px] text-slate-500">
                <p>Powered by Nexus Control Center · Last updated {{ status.last_updated_at }}</p>
            </footer>
        </main>
    </div>
</template>
