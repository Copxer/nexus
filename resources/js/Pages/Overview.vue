<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Overview" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-text-primary">
                Overview
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="glass-card p-8 text-text-secondary">
                    You're logged in. The futuristic Nexus dashboard lands
                    here in spec 006.
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
