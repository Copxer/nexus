<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import ProjectForm from '@/Pages/Projects/Partials/ProjectForm.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ChevronLeft, Copy, ExternalLink, Globe2 } from 'lucide-vue-next';
import { ref } from 'vue';

interface OptionPill {
    value: string;
    label: string;
    tone: string;
}

interface ProjectShape {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    status: string | null;
    priority: string | null;
    environment: string | null;
    color: string | null;
    icon: string | null;
    public_status_enabled: boolean;
    public_status_headline: string | null;
    public_status_url: string | null;
    public_status_subscriber_count: number;
}

const props = defineProps<{
    project: ProjectShape;
    options: {
        statuses: OptionPill[];
        priorities: OptionPill[];
        colors: string[];
        icons: string[];
    };
}>();

const publicForm = useForm({
    // Spec 047 — only ship the public-status fields. UpdateProjectRequest
    // treats every field as `sometimes`, so the main form's values stay
    // untouched even if the operator saves this panel after editing the
    // main form without a refresh.
    public_status_enabled: props.project.public_status_enabled,
    public_status_headline: props.project.public_status_headline ?? '',
});

const savedFlash = ref<string | null>(null);

const submitPublic = () => {
    publicForm.patch(route('projects.update', props.project.slug), {
        preserveScroll: true,
        onSuccess: () => {
            savedFlash.value = 'Public status settings saved.';
            setTimeout(() => (savedFlash.value = null), 3000);
        },
    });
};

const copyUrl = async () => {
    if (! props.project.public_status_url) return;
    try {
        await navigator.clipboard.writeText(props.project.public_status_url);
        savedFlash.value = 'Public URL copied to clipboard.';
        setTimeout(() => (savedFlash.value = null), 2000);
    } catch {
        // Clipboard API unavailable in some contexts; fall back to selection.
        savedFlash.value = 'Copy the URL from the field above.';
        setTimeout(() => (savedFlash.value = null), 3000);
    }
};
</script>

<template>
    <Head :title="`Edit ${project.name}`" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <Link
                    :href="route('projects.show', project.slug)"
                    class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan transition hover:text-accent-cyan/80"
                >
                    <ChevronLeft class="h-3 w-3" aria-hidden="true" />
                    {{ project.name }}
                </Link>
                <h1 class="text-lg font-semibold text-text-primary">
                    Edit project
                </h1>
            </div>
        </template>

        <div class="px-4 py-6 sm:px-6 lg:px-8">
            <section class="glass-card mx-auto max-w-3xl p-6 sm:p-8">
                <ProjectForm
                    :action="route('projects.update', project.slug)"
                    method="patch"
                    :initial="project"
                    :options="options"
                    :cancel-to="route('projects.show', project.slug)"
                    submit-label="Save changes"
                />
            </section>

            <!-- Spec 047 — Public status page panel -->
            <section class="glass-card mx-auto mt-6 max-w-3xl p-6 sm:p-8">
                <header class="mb-4 flex items-center gap-2">
                    <Globe2 class="h-5 w-5 text-accent-cyan" aria-hidden="true" />
                    <h2 class="text-lg font-semibold text-text-primary">Public status page</h2>
                </header>
                <p class="mb-4 text-sm text-text-secondary">
                    When enabled, anyone with the link can view real-time monitor uptime, active
                    incidents, and recent history. Subscribers get email updates on incident
                    transitions.
                </p>
                <form class="space-y-4" @submit.prevent="submitPublic">
                    <label class="flex items-center gap-3 text-sm text-text-primary">
                        <input
                            v-model="publicForm.public_status_enabled"
                            type="checkbox"
                            class="h-4 w-4 rounded border-border-subtle bg-background-panel-hover"
                        >
                        Enable public status page
                    </label>
                    <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                        Headline (optional)
                        <input
                            v-model="publicForm.public_status_headline"
                            type="text"
                            maxlength="240"
                            placeholder="A short banner shown above incidents."
                            class="rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        >
                    </label>
                    <div v-if="project.public_status_url" class="flex flex-col gap-2">
                        <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-text-muted">
                            Public URL
                            <div class="flex items-center gap-2">
                                <input
                                    :value="project.public_status_url"
                                    type="text"
                                    readonly
                                    class="flex-1 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary"
                                >
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-xs font-semibold text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                    @click="copyUrl"
                                >
                                    <Copy class="h-3.5 w-3.5" aria-hidden="true" />
                                    Copy
                                </button>
                                <a
                                    :href="project.public_status_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-xs font-semibold text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                                >
                                    <ExternalLink class="h-3.5 w-3.5" aria-hidden="true" />
                                    Open
                                </a>
                            </div>
                        </label>
                        <p class="text-[11px] text-text-muted">
                            {{ project.public_status_subscriber_count }} confirmed subscriber<span v-if="project.public_status_subscriber_count !== 1">s</span>
                        </p>
                    </div>
                    <p
                        v-if="savedFlash"
                        role="status"
                        class="text-xs text-emerald-300"
                    >
                        {{ savedFlash }}
                    </p>
                    <button
                        type="submit"
                        :disabled="publicForm.processing"
                        class="inline-flex items-center gap-2 rounded-lg bg-accent-cyan px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-accent-cyan/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Save public status settings
                    </button>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
