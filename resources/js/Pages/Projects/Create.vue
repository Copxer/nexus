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

defineProps<{
    options: {
        statuses: OptionPill[];
        priorities: OptionPill[];
        colors: string[];
        icons: string[];
    };
}>();

const initial = {
    name: '',
    description: '',
    status: null,
    priority: null,
    environment: '',
    color: null,
    icon: null,
};
</script>

<template>
    <Head title="New project" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <Link
                    :href="route('projects.index')"
                    class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan transition hover:text-accent-cyan/80"
                >
                    <ChevronLeft class="h-3 w-3" aria-hidden="true" />
                    Projects
                </Link>
                <h1 class="text-lg font-semibold text-text-primary">
                    Create project
                </h1>
            </div>
        </template>

        <div class="px-4 py-6 sm:px-6 lg:px-8">
            <section class="glass-card mx-auto max-w-3xl p-6 sm:p-8">
                <ProjectForm
                    :action="route('projects.store')"
                    method="post"
                    :initial="initial"
                    :options="options"
                    :cancel-to="route('projects.index')"
                    submit-label="Create project"
                />
            </section>
        </div>
    </AppLayout>
</template>
