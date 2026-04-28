<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Overview" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <span
                    class="text-[11px] font-semibold uppercase tracking-[0.32em] text-accent-cyan"
                >
                    Phase 0
                </span>
                <h1 class="text-lg font-semibold text-text-primary">
                    Overview
                </h1>
            </div>
        </template>

        <div class="px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <div class="glass-card p-8 text-text-secondary">
                    The dashboard shell is live. Real KPI cards, charts, and
                    activity stream land in spec 006.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
