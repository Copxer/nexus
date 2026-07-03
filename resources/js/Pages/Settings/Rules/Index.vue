<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    ChevronLeft,
    Gauge,
    Plus,
    RotateCcw,
    Save,
    Trash2,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Severity = 'info' | 'warning' | 'critical';
type Kind =
    | 'queue.backlog_trend'
    | 'deploy_frequency_drop'
    | 'uptime_slope'
    | 'deploy_failure_rate';

interface Rule {
    id: number;
    name: string;
    kind: Kind;
    kind_label: string;
    severity: Severity;
    config: Record<string, number>;
    enabled: boolean;
    cool_down_minutes: number;
    last_evaluated_at: string | null;
    last_triggered_at: string | null;
}

interface Template {
    id: string;
    name: string;
    kind: Kind;
    severity: Severity;
    config: Record<string, number>;
    description: string;
}

interface Weights {
    alert_critical: number | null;
    alert_warning: number | null;
    deploy_failed: number | null;
    website_slow: number | null;
    website_down: number | null;
    host_offline: number | null;
    container_unhealthy: number | null;
    gh_sync_failed: number | null;
}

const props = defineProps<{
    weights: {
        defaults: Weights;
        overrides: Weights | null;
        resolved: Weights;
    };
    rules: Rule[];
    options: {
        kinds: Array<{ value: Kind; label: string }>;
        severities: Severity[];
        templates: Template[];
    };
}>();

type Tab = 'weights' | 'rules';
const activeTab = ref<Tab>('weights');

const weightFields: Array<keyof Weights> = [
    'alert_critical',
    'alert_warning',
    'deploy_failed',
    'website_slow',
    'website_down',
    'host_offline',
    'container_unhealthy',
    'gh_sync_failed',
];

const weightLabels: Record<keyof Weights, string> = {
    alert_critical: 'Critical alert',
    alert_warning: 'Warning alert',
    deploy_failed: 'Failed deploy (24h)',
    website_slow: 'Slow website',
    website_down: 'Down website',
    host_offline: 'Offline host',
    container_unhealthy: 'Unhealthy container',
    gh_sync_failed: 'Failed GitHub sync',
};

// One editable slot per weight field: null means "use the default,"
// number means "override with this value." Seed from the resolved
// bundle so users see the currently-active value in every slot.
const draftWeights = ref<Record<keyof Weights, number>>({
    alert_critical: props.weights.resolved.alert_critical ?? 0,
    alert_warning: props.weights.resolved.alert_warning ?? 0,
    deploy_failed: props.weights.resolved.deploy_failed ?? 0,
    website_slow: props.weights.resolved.website_slow ?? 0,
    website_down: props.weights.resolved.website_down ?? 0,
    host_offline: props.weights.resolved.host_offline ?? 0,
    container_unhealthy: props.weights.resolved.container_unhealthy ?? 0,
    gh_sync_failed: props.weights.resolved.gh_sync_failed ?? 0,
});

const saveWeights = () => {
    router.patch(
        route('settings.rules.weights.update'),
        draftWeights.value,
        { preserveScroll: true },
    );
};

const resetWeights = () => {
    if (!confirm('Reset every weight to its default?')) return;
    router.delete(route('settings.rules.weights.reset'), { preserveScroll: true });
};

// ─── Rules tab state ────────────────────────────────────────────

const newRuleFromTemplate = ref<Template | null>(null);
const newRule = ref<{
    name: string;
    kind: Kind;
    severity: Severity;
    config: Record<string, number>;
    cool_down_minutes: number;
}>({
    name: '',
    kind: 'queue.backlog_trend',
    severity: 'warning',
    config: { window_minutes: 15, threshold_delta: 100 },
    cool_down_minutes: 30,
});

const applyTemplate = (tpl: Template) => {
    newRuleFromTemplate.value = tpl;
    newRule.value = {
        name: tpl.name,
        kind: tpl.kind,
        severity: tpl.severity,
        config: { ...tpl.config },
        cool_down_minutes: 30,
    };
};

const submitRule = () => {
    router.post(
        route('settings.rules.store'),
        newRule.value,
        {
            preserveScroll: true,
            onSuccess: () => {
                newRuleFromTemplate.value = null;
                newRule.value = {
                    name: '',
                    kind: 'queue.backlog_trend',
                    severity: 'warning',
                    config: { window_minutes: 15, threshold_delta: 100 },
                    cool_down_minutes: 30,
                };
            },
        },
    );
};

const toggleRule = (rule: Rule) => {
    router.patch(
        route('settings.rules.update', { rule: rule.id }),
        { enabled: !rule.enabled },
        { preserveScroll: true },
    );
};

const deleteRule = (rule: Rule) => {
    if (!confirm(`Delete "${rule.name}"?`)) return;
    router.delete(
        route('settings.rules.destroy', { rule: rule.id }),
        { preserveScroll: true },
    );
};

const configEntries = (config: Record<string, number>) =>
    Object.entries(config).map(([key, value]) => ({ key, value }));

const capitalize = (s: string | null) =>
    s ? s.charAt(0).toUpperCase() + s.slice(1) : '';

const hasOverride = computed(() => props.weights.overrides !== null);
</script>

<template>
    <Head title="Rules & health weights" />

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
                <h1 class="text-lg font-semibold text-text-primary">Rules & health weights</h1>
            </div>
        </template>

        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <header class="flex flex-col gap-1">
                <h2 class="flex items-center gap-2 text-xl font-semibold text-text-primary">
                    <Gauge class="h-5 w-5 text-accent-cyan" aria-hidden="true" />
                    Custom rules
                </h2>
                <p class="text-sm text-text-secondary">
                    Tune how the project health score reacts to each signal, and add metric-driven
                    alert rules that fire through your notification channels.
                </p>
            </header>

            <nav
                aria-label="Rules tabs"
                class="flex gap-2 border-b border-border-subtle"
            >
                <button
                    v-for="tab in (['weights', 'rules'] as Tab[])"
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

            <!-- Weights tab -->
            <section v-if="activeTab === 'weights'" class="space-y-6">
                <div class="glass-card p-5">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-text-muted">
                            Deduction weights
                        </h3>
                        <StatusBadge :tone="hasOverride ? 'info' : 'muted'">
                            {{ hasOverride ? 'Custom overrides active' : 'Using defaults' }}
                        </StatusBadge>
                    </div>
                    <form class="space-y-4" @submit.prevent="saveWeights">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label
                                v-for="field in weightFields"
                                :key="field"
                                class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted"
                            >
                                {{ weightLabels[field] }}
                                <span class="text-[10px] text-text-muted">
                                    Default: {{ props.weights.defaults[field] ?? 0 }}
                                </span>
                                <input
                                    v-model.number="draftWeights[field]"
                                    type="number"
                                    min="0"
                                    max="100"
                                    class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                >
                            </label>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <button
                                type="submit"
                                class="inline-flex items-center gap-2 rounded-lg bg-accent-cyan px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-accent-cyan/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                            >
                                <Save class="h-4 w-4" aria-hidden="true" />
                                Save weights
                            </button>
                            <button
                                type="button"
                                :disabled="!hasOverride"
                                class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-4 py-2 text-sm font-semibold text-text-secondary transition hover:border-status-danger/40 hover:text-status-danger focus:outline-none focus-visible:ring-2 focus-visible:ring-status-danger/60 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="resetWeights"
                            >
                                <RotateCcw class="h-4 w-4" aria-hidden="true" />
                                Reset to defaults
                            </button>
                        </div>
                        <p class="text-[11px] text-text-muted">
                            Values are absolute deductions (0-100). Saving reasserts the score
                            across every project you own.
                        </p>
                    </form>
                </div>
            </section>

            <!-- Rules tab -->
            <section v-if="activeTab === 'rules'" class="space-y-6">
                <div class="glass-card p-5">
                    <h3 class="mb-4 text-sm font-semibold uppercase tracking-[0.18em] text-text-muted">
                        Start from a template
                    </h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            v-for="tpl in props.options.templates"
                            :key="tpl.id"
                            type="button"
                            class="glass-card flex flex-col gap-1 p-4 text-left transition hover:border-accent-cyan/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                            :class="{
                                'border-accent-cyan/60': newRuleFromTemplate?.id === tpl.id,
                            }"
                            @click="applyTemplate(tpl)"
                        >
                            <span class="text-sm font-semibold text-text-primary">{{ tpl.name }}</span>
                            <span class="text-[11px] text-text-muted">{{ tpl.description }}</span>
                        </button>
                    </div>
                </div>

                <div class="glass-card p-5">
                    <h3 class="mb-4 text-sm font-semibold uppercase tracking-[0.18em] text-text-muted">
                        Add rule
                    </h3>
                    <form class="space-y-4" @submit.prevent="submitRule">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                                Name
                                <input
                                    v-model="newRule.name"
                                    type="text"
                                    required
                                    placeholder="Queue backlog watch"
                                    class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                >
                            </label>
                            <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                                Kind
                                <select
                                    v-model="newRule.kind"
                                    class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                >
                                    <option v-for="k in props.options.kinds" :key="k.value" :value="k.value">
                                        {{ k.label }}
                                    </option>
                                </select>
                            </label>
                            <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                                Severity
                                <select
                                    v-model="newRule.severity"
                                    class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                >
                                    <option v-for="s in props.options.severities" :key="s" :value="s">
                                        {{ capitalize(s) }}
                                    </option>
                                </select>
                            </label>
                            <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                                Cool-down (minutes)
                                <input
                                    v-model.number="newRule.cool_down_minutes"
                                    type="number"
                                    min="1"
                                    max="1440"
                                    class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                >
                            </label>
                        </div>
                        <fieldset class="grid gap-4 sm:grid-cols-2">
                            <legend class="col-span-full text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                                Config
                            </legend>
                            <label
                                v-for="entry in configEntries(newRule.config)"
                                :key="entry.key"
                                class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted"
                            >
                                {{ entry.key.replace('_', ' ') }}
                                <input
                                    v-model.number="newRule.config[entry.key]"
                                    type="number"
                                    step="any"
                                    class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                >
                            </label>
                        </fieldset>
                        <button
                            type="submit"
                            class="inline-flex items-center gap-2 rounded-lg bg-accent-cyan px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-accent-cyan/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        >
                            <Plus class="h-4 w-4" aria-hidden="true" />
                            Add rule
                        </button>
                    </form>
                </div>

                <div v-if="props.rules.length > 0" class="glass-card overflow-hidden">
                    <ul class="divide-y divide-border-subtle">
                        <li
                            v-for="rule in props.rules"
                            :key="rule.id"
                            class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <AlertTriangle
                                    class="mt-0.5 h-5 w-5 shrink-0 text-accent-cyan"
                                    aria-hidden="true"
                                />
                                <div class="flex min-w-0 flex-col gap-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-semibold text-text-primary">
                                            {{ rule.name }}
                                        </span>
                                        <StatusBadge :tone="rule.enabled ? 'success' : 'muted'">
                                            {{ rule.enabled ? 'Enabled' : 'Disabled' }}
                                        </StatusBadge>
                                        <StatusBadge
                                            :tone="rule.severity === 'critical' ? 'danger' : rule.severity === 'warning' ? 'warning' : 'info'"
                                        >
                                            {{ capitalize(rule.severity) }}
                                        </StatusBadge>
                                    </div>
                                    <div class="flex flex-wrap gap-3 text-[11px] text-text-muted">
                                        <span>{{ rule.kind_label }}</span>
                                        <span>Cool-down {{ rule.cool_down_minutes }} min</span>
                                        <span v-if="rule.last_triggered_at">
                                            Last triggered {{ rule.last_triggered_at }}
                                        </span>
                                        <span v-else-if="rule.last_evaluated_at">
                                            Evaluated {{ rule.last_evaluated_at }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-1.5 text-xs font-semibold text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                    @click="toggleRule(rule)"
                                >
                                    <Activity class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ rule.enabled ? 'Disable' : 'Enable' }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-1.5 text-xs font-semibold text-status-danger transition hover:border-status-danger/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-status-danger/60"
                                    @click="deleteRule(rule)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" aria-hidden="true" />
                                    Delete
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>
                <div v-else class="glass-card p-6 text-center text-sm text-text-muted">
                    No rules yet. Pick a template above or fill in the form to add one.
                </div>
            </section>
        </div>
    </AppLayout>
</template>
