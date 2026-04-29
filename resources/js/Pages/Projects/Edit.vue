<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import ProjectForm from '@/Pages/Projects/Partials/ProjectForm.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft } from 'lucide-vue-next';

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
        </div>
    </AppLayout>
</template>
