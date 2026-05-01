<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ChevronLeft } from 'lucide-vue-next';
import { computed } from 'vue';

interface ProjectOption {
    id: number;
    name: string;
    color: string | null;
}

interface MethodOption {
    value: string;
    label: string;
}

interface IntervalOption {
    value: number;
    label: string;
}

const props = defineProps<{
    projects: ProjectOption[];
    preselectedProjectId: number | null;
    options: {
        methods: MethodOption[];
        common_intervals: IntervalOption[];
    };
}>();

const form = useForm({
    project_id: props.preselectedProjectId ?? props.projects[0]?.id ?? null,
    name: '',
    url: '',
    method: 'GET',
    expected_status_code: 200,
    timeout_ms: 10000,
    check_interval_seconds: 300,
});

const submit = () => {
    form.post(route('monitoring.websites.store'));
};

/**
 * Detect when the entered URL points back at the same Nexus instance.
 * `php artisan serve`'s default single-process worker deadlocks when
 * a sync request loops back to itself (probe → controller → probe).
 * Surface a heads-up so the user knows to use a different URL or run
 * the dev server with `--workers=4`.
 *
 * Match by hostname only — port, scheme, and path don't matter for
 * the loop. SSR-safe: `window` may not exist during initial render.
 */
const selfProbeWarning = computed(() => {
    if (typeof window === 'undefined' || !form.url) return false;
    try {
        const target = new URL(form.url);
        return target.hostname === window.location.hostname;
    } catch {
        // `new URL()` throws on malformed input; the form's url
        // validation will catch that path on submit.
        return false;
    }
});
</script>

<template>
    <Head title="New monitor" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <Link
                    :href="route('monitoring.websites.index')"
                    class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan transition hover:text-accent-cyan/80"
                >
                    <ChevronLeft class="h-3 w-3" aria-hidden="true" />
                    Monitoring
                </Link>
                <h1 class="text-lg font-semibold text-text-primary">
                    New monitor
                </h1>
            </div>
        </template>

        <div class="mx-auto max-w-2xl px-4 py-6 sm:px-6 lg:px-8">
            <form
                class="glass-card flex flex-col gap-5 p-6"
                @submit.prevent="submit"
            >
                <div class="flex flex-col gap-2">
                    <InputLabel for="project_id" value="Project" />
                    <select
                        id="project_id"
                        v-model.number="form.project_id"
                        class="rounded-md border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:border-accent-cyan/60 focus:outline-none"
                    >
                        <option
                            v-for="project in projects"
                            :key="project.id"
                            :value="project.id"
                        >
                            {{ project.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.project_id" />
                </div>

                <div class="flex flex-col gap-2">
                    <InputLabel for="name" value="Name" />
                    <TextInput
                        id="name"
                        v-model="form.name"
                        type="text"
                        placeholder="Marketing site"
                        autofocus
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="flex flex-col gap-2">
                    <InputLabel for="url" value="URL" />
                    <TextInput
                        id="url"
                        v-model="form.url"
                        type="url"
                        placeholder="https://example.com/up"
                    />
                    <p class="text-xs text-text-muted">
                        Tip: for Laravel apps, monitor
                        <span class="font-mono">/up</span> — Laravel's
                        built-in health endpoint.
                    </p>
                    <p
                        v-if="selfProbeWarning"
                        class="flex items-start gap-1.5 rounded-md border border-status-warning/40 bg-status-warning/10 p-2 text-xs text-status-warning"
                    >
                        <AlertTriangle
                            class="mt-0.5 h-3.5 w-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="break-words">
                            Heads up — this URL points at the same host
                            as Nexus itself. If you're running
                            <span class="font-mono">php artisan serve</span>,
                            the probe will deadlock (single worker). Use
                            <span class="font-mono">--workers=4</span> or
                            point this monitor at a different URL.
                        </span>
                    </p>
                    <InputError :message="form.errors.url" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-2">
                        <InputLabel for="method" value="HTTP method" />
                        <select
                            id="method"
                            v-model="form.method"
                            class="rounded-md border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:border-accent-cyan/60 focus:outline-none"
                        >
                            <option
                                v-for="opt in options.methods"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.method" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <InputLabel
                            for="expected_status_code"
                            value="Expected status code"
                        />
                        <input
                            id="expected_status_code"
                            v-model.number="form.expected_status_code"
                            type="number"
                            min="100"
                            max="599"
                            class="rounded-lg border border-border-subtle bg-slate-950/60 px-3 py-2 text-text-primary placeholder:text-text-muted shadow-inner shadow-black/20 transition focus:border-accent-cyan focus:ring-2 focus:ring-accent-cyan/40 focus:ring-offset-0"
                        />
                        <InputError
                            :message="form.errors.expected_status_code"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-2">
                        <InputLabel for="timeout_ms" value="Timeout (ms)" />
                        <input
                            id="timeout_ms"
                            v-model.number="form.timeout_ms"
                            type="number"
                            min="1000"
                            max="60000"
                            class="rounded-lg border border-border-subtle bg-slate-950/60 px-3 py-2 text-text-primary placeholder:text-text-muted shadow-inner shadow-black/20 transition focus:border-accent-cyan focus:ring-2 focus:ring-accent-cyan/40 focus:ring-offset-0"
                        />
                        <InputError :message="form.errors.timeout_ms" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <InputLabel
                            for="check_interval_seconds"
                            value="Check interval"
                        />
                        <select
                            id="check_interval_seconds"
                            v-model.number="form.check_interval_seconds"
                            class="rounded-md border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:border-accent-cyan/60 focus:outline-none"
                        >
                            <option
                                v-for="opt in options.common_intervals"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </option>
                        </select>
                        <InputError
                            :message="form.errors.check_interval_seconds"
                        />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3">
                    <Link
                        :href="route('monitoring.websites.index')"
                        class="text-sm font-semibold text-text-secondary hover:text-text-primary"
                    >
                        Cancel
                    </Link>
                    <PrimaryButton :disabled="form.processing">
                        Create monitor
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
