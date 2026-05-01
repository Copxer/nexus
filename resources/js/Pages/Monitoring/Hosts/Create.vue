<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ChevronLeft } from 'lucide-vue-next';

interface ProjectOption {
    id: number;
    name: string;
    color: string | null;
}

interface ConnectionTypeOption {
    value: string;
    label: string;
    enabled: boolean;
}

const props = defineProps<{
    projects: ProjectOption[];
    preselectedProjectId: number | null;
    options: {
        connection_types: ConnectionTypeOption[];
    };
}>();

const form = useForm({
    project_id: props.preselectedProjectId ?? props.projects[0]?.id ?? null,
    name: '',
    provider: '',
    endpoint_url: '',
    connection_type: 'agent',
});

const submit = () => {
    form.post(route('monitoring.hosts.store'));
};
</script>

<template>
    <Head title="New host" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <Link
                    :href="route('monitoring.hosts.index')"
                    class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan transition hover:text-accent-cyan/80"
                >
                    <ChevronLeft class="h-3 w-3" aria-hidden="true" />
                    Hosts
                </Link>
                <h1 class="text-lg font-semibold text-text-primary">
                    New host
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
                        placeholder="prod-frankfurt-01"
                        autofocus
                    />
                    <p class="text-xs text-text-muted">
                        A short label that uniquely identifies this host
                        within the project.
                    </p>
                    <InputError :message="form.errors.name" />
                </div>

                <div class="flex flex-col gap-2">
                    <InputLabel
                        for="provider"
                        value="Provider (optional)"
                    />
                    <TextInput
                        id="provider"
                        v-model="form.provider"
                        type="text"
                        placeholder="DigitalOcean FRA1"
                    />
                    <InputError :message="form.errors.provider" />
                </div>

                <div class="flex flex-col gap-2">
                    <InputLabel
                        for="connection_type"
                        value="Connection"
                    />
                    <select
                        id="connection_type"
                        v-model="form.connection_type"
                        class="rounded-md border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm text-text-primary focus:border-accent-cyan/60 focus:outline-none"
                    >
                        <option
                            v-for="opt in options.connection_types"
                            :key="opt.value"
                            :value="opt.value"
                            :disabled="!opt.enabled"
                        >
                            {{ opt.label }}
                        </option>
                    </select>
                    <p class="text-xs text-text-muted">
                        Phase 6 ships the agent (push) path. SSH and
                        Docker API will be wired up in a later phase.
                    </p>
                    <InputError :message="form.errors.connection_type" />
                </div>

                <div class="flex flex-col gap-2">
                    <InputLabel
                        for="endpoint_url"
                        value="Endpoint URL (optional)"
                    />
                    <TextInput
                        id="endpoint_url"
                        v-model="form.endpoint_url"
                        type="url"
                        placeholder="https://prod-01.example.com"
                    />
                    <p class="text-xs text-text-muted">
                        Informational only for the agent path — the
                        agent pushes to Nexus.
                    </p>
                    <InputError :message="form.errors.endpoint_url" />
                </div>

                <div class="flex items-center justify-end gap-3">
                    <Link
                        :href="route('monitoring.hosts.index')"
                        class="text-sm font-semibold text-text-secondary hover:text-text-primary"
                    >
                        Cancel
                    </Link>
                    <PrimaryButton :disabled="form.processing">
                        Create host
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
