<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    project: { name: string; slug: string };
}>();
</script>

<template>
    <Head title="Subscription confirmed" />

    <div class="min-h-screen bg-slate-950 text-slate-100">
        <main class="mx-auto flex max-w-lg flex-col items-center px-4 py-16 text-center">
            <p class="text-[11px] font-semibold uppercase tracking-[0.32em] text-slate-500">
                Subscription confirmed
            </p>
            <h1 class="mt-2 text-2xl font-semibold text-slate-50">You're subscribed</h1>
            <p class="mt-4 text-sm text-slate-300">
                You'll receive email updates for incidents affecting
                <strong class="text-slate-50">{{ project.name }}</strong>.
            </p>
            <Link
                :href="route('public-status.show', { project: project.slug })"
                class="mt-6 rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300"
            >
                Back to status page
            </Link>
        </main>
    </div>
</template>
