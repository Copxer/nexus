<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Bell,
    ChevronLeft,
    Mail,
    MessageSquare,
    Plus,
    RefreshCw,
    Send,
    Trash2,
    Webhook,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

type ChannelKind = 'email' | 'slack' | 'webhook';
type Severity = 'info' | 'warning' | 'critical';
type Source = 'website' | 'docker' | 'deployment' | 'github' | 'manual' | 'system';
type DeliveryStatus = 'pending' | 'sent' | 'failed' | 'skipped';

interface Channel {
    id: number;
    kind: ChannelKind;
    kind_label: string;
    name: string;
    config_preview: Record<string, unknown>;
    enabled: boolean;
    verified_at: string | null;
    verified: boolean;
}

interface Preference {
    id: number;
    channel_id: number;
    channel_name: string | null;
    channel_kind: ChannelKind | null;
    min_severity: Severity;
    sources: Source[];
    enabled: boolean;
    notify_on_resolve: boolean;
    rate_limit_per_hour: number | null;
}

interface Delivery {
    id: number;
    alert_id: number | null;
    alert_title: string | null;
    alert_severity: Severity | null;
    alert_source: Source | null;
    channel_name: string | null;
    channel_kind: ChannelKind | null;
    status: DeliveryStatus;
    status_tone: 'info' | 'success' | 'danger' | 'muted' | 'warning' | null;
    attempts: number;
    error_message: string | null;
    last_attempt_at: string | null;
    sent_at: string | null;
    created_at: string | null;
}

interface Paginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

const props = defineProps<{
    channels: Channel[];
    preferences: Preference[];
    deliveries: Paginator<Delivery>;
    options: {
        kinds: Array<{ value: ChannelKind; label: string }>;
        severities: Severity[];
        sources: Source[];
    };
}>();

type Tab = 'channels' | 'rules' | 'deliveries';
const activeTab = ref<Tab>('channels');

// ---- Channel add form ----
const newChannel = ref<{ kind: ChannelKind; name: string; config: Record<string, string> }>({
    kind: 'email',
    name: '',
    config: {},
});

const configFields = computed<string[]>(() => {
    switch (newChannel.value.kind) {
        case 'email':
            return ['to'];
        case 'slack':
            return ['webhook_url'];
        case 'webhook':
            return ['url', 'signing_secret'];
    }
    return [];
});

const submitChannel = () => {
    router.post(
        route('settings.notifications.channels.store'),
        {
            kind: newChannel.value.kind,
            name: newChannel.value.name,
            config: newChannel.value.config,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                newChannel.value = { kind: 'email', name: '', config: {} };
            },
        },
    );
};

const testChannel = (channel: Channel) => {
    router.post(
        route('settings.notifications.channels.test', { channel: channel.id }),
        {},
        { preserveScroll: true },
    );
};

const deleteChannel = (channel: Channel) => {
    if (!confirm(`Delete "${channel.name}"? All rules using it will be removed.`)) return;
    router.delete(
        route('settings.notifications.channels.destroy', { channel: channel.id }),
        { preserveScroll: true },
    );
};

// ---- Preference add form ----
const newPreference = ref<{
    channel_id: number | null;
    min_severity: Severity;
    sources: Source[];
    notify_on_resolve: boolean;
    rate_limit_per_hour: number | null;
}>({
    channel_id: null,
    min_severity: 'warning',
    sources: [],
    notify_on_resolve: false,
    rate_limit_per_hour: null,
});

const submitPreference = () => {
    if (!newPreference.value.channel_id) return;
    router.post(
        route('settings.notifications.preferences.store'),
        {
            channel_id: newPreference.value.channel_id,
            min_severity: newPreference.value.min_severity,
            sources: newPreference.value.sources.length > 0 ? newPreference.value.sources : null,
            notify_on_resolve: newPreference.value.notify_on_resolve,
            rate_limit_per_hour: newPreference.value.rate_limit_per_hour,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                newPreference.value = {
                    channel_id: null,
                    min_severity: 'warning',
                    sources: [],
                    notify_on_resolve: false,
                    rate_limit_per_hour: null,
                };
            },
        },
    );
};

const deletePreference = (preference: Preference) => {
    if (!confirm('Delete this rule?')) return;
    router.delete(
        route('settings.notifications.preferences.destroy', { preference: preference.id }),
        { preserveScroll: true },
    );
};

// ---- Delivery retry ----
const retryDelivery = (delivery: Delivery) => {
    if (delivery.status !== 'failed') return;
    router.post(
        route('settings.notifications.deliveries.retry', { delivery: delivery.id }),
        {},
        { preserveScroll: true },
    );
};

const iconFor = (kind: ChannelKind | null) => {
    switch (kind) {
        case 'email':
            return Mail;
        case 'slack':
            return MessageSquare;
        case 'webhook':
            return Webhook;
        default:
            return Bell;
    }
};

const capitalize = (s: string | null) =>
    s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
</script>

<template>
    <Head title="Notifications" />

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
                <h1 class="text-lg font-semibold text-text-primary">Notifications</h1>
            </div>
        </template>

        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header class="flex flex-col gap-1">
                <h2 class="flex items-center gap-2 text-xl font-semibold text-text-primary">
                    <Bell class="h-5 w-5 text-accent-cyan" aria-hidden="true" />
                    Alert notifications
                </h2>
                <p class="text-sm text-text-secondary">
                    Route alerts to email, Slack, or a generic webhook. Rules pick which
                    severities and sources fire which channel.
                </p>
            </header>

            <!-- Tab strip -->
            <nav
                aria-label="Notification tabs"
                class="flex gap-2 border-b border-border-subtle"
            >
                <button
                    v-for="tab in (['channels', 'rules', 'deliveries'] as Tab[])"
                    :key="tab"
                    type="button"
                    class="border-b-2 px-4 py-2 text-sm font-semibold uppercase tracking-[0.18em] transition"
                    :class="
                        activeTab === tab
                            ? 'border-accent-cyan text-text-primary'
                            : 'border-transparent text-text-muted hover:text-text-primary'
                    "
                    @click="activeTab = tab"
                >
                    {{ capitalize(tab) }}
                </button>
            </nav>

            <!-- Channels tab -->
            <section v-if="activeTab === 'channels'" class="space-y-6">
                <div class="glass-card p-5">
                    <h3 class="mb-4 text-sm font-semibold uppercase tracking-[0.18em] text-text-muted">
                        Add channel
                    </h3>
                    <form class="space-y-4" @submit.prevent="submitChannel">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                                Kind
                                <select
                                    v-model="newChannel.kind"
                                    class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                    @change="newChannel.config = {}"
                                >
                                    <option v-for="k in props.options.kinds" :key="k.value" :value="k.value">
                                        {{ k.label }}
                                    </option>
                                </select>
                            </label>
                            <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                                Name
                                <input
                                    v-model="newChannel.name"
                                    type="text"
                                    required
                                    placeholder="Ops on-call"
                                    class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                >
                            </label>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label
                                v-for="field in configFields"
                                :key="field"
                                class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted"
                            >
                                {{ field.replace('_', ' ') }}
                                <input
                                    v-model="newChannel.config[field]"
                                    :type="field === 'signing_secret' ? 'password' : 'text'"
                                    :placeholder="field === 'to' ? 'ops@example.com' : field === 'webhook_url' ? 'https://hooks.slack.com/...' : field === 'url' ? 'https://ops.example.com/hook' : 'optional HMAC secret'"
                                    class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                >
                            </label>
                        </div>
                        <button
                            type="submit"
                            class="inline-flex items-center gap-2 rounded-lg bg-accent-cyan px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-accent-cyan/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        >
                            <Plus class="h-4 w-4" aria-hidden="true" />
                            Add channel
                        </button>
                    </form>
                </div>

                <div v-if="props.channels.length > 0" class="glass-card overflow-hidden">
                    <ul class="divide-y divide-border-subtle">
                        <li
                            v-for="channel in props.channels"
                            :key="channel.id"
                            class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <component
                                    :is="iconFor(channel.kind)"
                                    class="mt-0.5 h-5 w-5 shrink-0 text-accent-cyan"
                                    aria-hidden="true"
                                />
                                <div class="flex min-w-0 flex-col gap-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-semibold text-text-primary">{{ channel.name }}</span>
                                        <StatusBadge :tone="channel.verified ? 'success' : 'warning'">
                                            {{ channel.verified ? 'Verified' : 'Unverified' }}
                                        </StatusBadge>
                                        <StatusBadge v-if="!channel.enabled" tone="muted">Disabled</StatusBadge>
                                    </div>
                                    <div class="flex flex-wrap gap-3 text-[11px] text-text-muted">
                                        <span>{{ channel.kind_label }}</span>
                                        <span v-if="channel.verified_at">Verified {{ channel.verified_at }}</span>
                                        <span v-for="(v, k) in channel.config_preview" :key="k">
                                            {{ k }}: <span class="font-mono">{{ v }}</span>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-1.5 text-xs font-semibold text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                    @click="testChannel(channel)"
                                >
                                    <Send class="h-3.5 w-3.5" aria-hidden="true" />
                                    Send test
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-1.5 text-xs font-semibold text-status-danger transition hover:border-status-danger/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-status-danger/60"
                                    @click="deleteChannel(channel)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" aria-hidden="true" />
                                    Delete
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>
                <div v-else class="glass-card p-6 text-center text-sm text-text-muted">
                    No channels yet. Add one above to start receiving notifications.
                </div>
            </section>

            <!-- Rules tab -->
            <section v-if="activeTab === 'rules'" class="space-y-6">
                <div class="glass-card p-5">
                    <h3 class="mb-4 text-sm font-semibold uppercase tracking-[0.18em] text-text-muted">
                        Add rule
                    </h3>
                    <form class="space-y-4" @submit.prevent="submitPreference">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                                Channel
                                <select
                                    v-model="newPreference.channel_id"
                                    required
                                    class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                >
                                    <option :value="null" disabled>Pick a channel</option>
                                    <option v-for="c in props.channels" :key="c.id" :value="c.id">
                                        {{ c.name }} ({{ c.kind_label }})
                                    </option>
                                </select>
                            </label>
                            <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                                Minimum severity
                                <select
                                    v-model="newPreference.min_severity"
                                    class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                >
                                    <option v-for="s in props.options.severities" :key="s" :value="s">
                                        {{ capitalize(s) }} and above
                                    </option>
                                </select>
                            </label>
                        </div>
                        <fieldset>
                            <legend class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                                Sources (empty = all)
                            </legend>
                            <div class="flex flex-wrap gap-3">
                                <label
                                    v-for="src in props.options.sources"
                                    :key="src"
                                    class="inline-flex items-center gap-2 text-sm text-text-secondary"
                                >
                                    <input
                                        v-model="newPreference.sources"
                                        type="checkbox"
                                        :value="src"
                                    >
                                    {{ capitalize(src) }}
                                </label>
                            </div>
                        </fieldset>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="inline-flex items-center gap-2 text-sm text-text-secondary">
                                <input v-model="newPreference.notify_on_resolve" type="checkbox">
                                Notify on resolve too
                            </label>
                            <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                                Rate limit / hour (blank = default)
                                <input
                                    v-model.number="newPreference.rate_limit_per_hour"
                                    type="number"
                                    min="1"
                                    max="1000"
                                    class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                >
                            </label>
                        </div>
                        <button
                            type="submit"
                            :disabled="props.channels.length === 0"
                            class="inline-flex items-center gap-2 rounded-lg bg-accent-cyan px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-accent-cyan/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <Plus class="h-4 w-4" aria-hidden="true" />
                            Add rule
                        </button>
                    </form>
                </div>

                <div v-if="props.preferences.length > 0" class="glass-card overflow-hidden">
                    <ul class="divide-y divide-border-subtle">
                        <li
                            v-for="preference in props.preferences"
                            :key="preference.id"
                            class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <component
                                    :is="iconFor(preference.channel_kind)"
                                    class="mt-0.5 h-5 w-5 shrink-0 text-accent-cyan"
                                    aria-hidden="true"
                                />
                                <div class="flex min-w-0 flex-col gap-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-semibold text-text-primary">
                                            {{ preference.channel_name || '(deleted channel)' }}
                                        </span>
                                        <StatusBadge :tone="preference.enabled ? 'success' : 'muted'">
                                            {{ preference.enabled ? 'Enabled' : 'Disabled' }}
                                        </StatusBadge>
                                    </div>
                                    <div class="flex flex-wrap gap-3 text-[11px] text-text-muted">
                                        <span>{{ capitalize(preference.min_severity) }} and above</span>
                                        <span>{{ preference.sources.length === 0 ? 'All sources' : preference.sources.map(capitalize).join(', ') }}</span>
                                        <span v-if="preference.notify_on_resolve">Resolves too</span>
                                        <span v-if="preference.rate_limit_per_hour">{{ preference.rate_limit_per_hour }}/hr</span>
                                    </div>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-1.5 text-xs font-semibold text-status-danger transition hover:border-status-danger/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-status-danger/60"
                                @click="deletePreference(preference)"
                            >
                                <Trash2 class="h-3.5 w-3.5" aria-hidden="true" />
                                Delete
                            </button>
                        </li>
                    </ul>
                </div>
                <div v-else class="glass-card p-6 text-center text-sm text-text-muted">
                    No rules yet. Add a rule above to route alerts to a channel.
                </div>
            </section>

            <!-- Deliveries tab -->
            <section v-if="activeTab === 'deliveries'" class="space-y-4">
                <div v-if="props.deliveries.data.length > 0" class="glass-card overflow-hidden">
                    <ul class="divide-y divide-border-subtle">
                        <li
                            v-for="delivery in props.deliveries.data"
                            :key="delivery.id"
                            class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="flex min-w-0 flex-col gap-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <StatusBadge v-if="delivery.status_tone" :tone="delivery.status_tone">
                                        {{ capitalize(delivery.status) }}
                                    </StatusBadge>
                                    <span class="text-sm font-semibold text-text-primary">
                                        {{ delivery.alert_title || `Alert #${delivery.alert_id}` }}
                                    </span>
                                    <span class="text-xs text-text-muted">
                                        → {{ delivery.channel_name }}
                                    </span>
                                </div>
                                <div class="flex flex-wrap gap-3 text-[11px] text-text-muted">
                                    <span v-if="delivery.alert_severity">{{ capitalize(delivery.alert_severity) }}</span>
                                    <span v-if="delivery.alert_source">{{ capitalize(delivery.alert_source) }}</span>
                                    <span>{{ delivery.attempts }} attempt{{ delivery.attempts === 1 ? '' : 's' }}</span>
                                    <span v-if="delivery.sent_at">Sent {{ delivery.sent_at }}</span>
                                    <span v-else-if="delivery.last_attempt_at">Last attempt {{ delivery.last_attempt_at }}</span>
                                    <span v-else-if="delivery.created_at">{{ delivery.created_at }}</span>
                                </div>
                                <p
                                    v-if="delivery.error_message"
                                    class="text-xs text-status-danger"
                                >
                                    {{ delivery.error_message }}
                                </p>
                            </div>
                            <button
                                v-if="delivery.status === 'failed'"
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-1.5 text-xs font-semibold text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                @click="retryDelivery(delivery)"
                            >
                                <RefreshCw class="h-3.5 w-3.5" aria-hidden="true" />
                                Retry
                            </button>
                        </li>
                    </ul>
                </div>
                <div v-else class="glass-card p-6 text-center text-sm text-text-muted">
                    No deliveries yet. Trigger an alert (or hit "Send test" on a channel) and the log lands here.
                </div>

                <nav
                    v-if="props.deliveries.last_page > 1"
                    aria-label="Delivery pagination"
                    class="flex flex-wrap gap-2"
                >
                    <Link
                        v-for="link in props.deliveries.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-1.5 text-xs font-semibold text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary"
                        :class="{
                            'border-accent-cyan/60 text-text-primary': link.active,
                            'pointer-events-none opacity-40': !link.url,
                        }"
                        v-html="link.label"
                    />
                </nav>
            </section>
        </div>
    </AppLayout>
</template>
