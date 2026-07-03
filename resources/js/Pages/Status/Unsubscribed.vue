<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    project: { name: string | null; slug: string | null };
}>();
</script>

<template>
    <Head title="Unsubscribed" />

    <div class="min-h-screen bg-slate-950 text-slate-100">
        <main class="mx-auto flex max-w-lg flex-col items-center px-4 py-16 text-center">
            <p class="text-[11px] font-semibold uppercase tracking-[0.32em] text-slate-500">
                Unsubscribed
            </p>
            <h1 class="mt-2 text-2xl font-semibold text-slate-50">You're unsubscribed</h1>
            <p class="mt-4 text-sm text-slate-300">
                You won't receive further updates
                <template v-if="project.name">
                    for <strong class="text-slate-50">{{ project.name }}</strong>
                </template>.
            </p>
            <Link
                v-if="project.slug"
                :href="route('public-status.show', { project: project.slug })"
                class="mt-6 rounded-lg border border-slate-700 bg-slate-900 px-4 py-2 text-sm font-semibold text-slate-100 transition hover:border-cyan-400/40"
            >
                View status page
            </Link>
        </main>
    </div>
</template>
