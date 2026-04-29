<script setup lang="ts">
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="relative flex min-h-screen flex-col items-center justify-center overflow-hidden bg-app-gradient px-4 py-12 sm:px-6"
    >
        <!-- Soft neon ambient glows behind the card. -->
        <div
            aria-hidden="true"
            class="pointer-events-none absolute -top-40 left-1/2 h-[480px] w-[480px] -translate-x-1/2 rounded-full bg-accent-cyan/10 blur-3xl"
        />
        <div
            aria-hidden="true"
            class="pointer-events-none absolute -bottom-40 right-10 h-[420px] w-[420px] rounded-full bg-accent-purple/10 blur-3xl"
        />

        <div class="relative">
            <Link href="/" class="flex flex-col items-center gap-3">
                <ApplicationLogo
                    class="h-8 drop-shadow-[0_0_18px_rgba(34,211,238,0.55)]"
                />
                <span class="text-[11px] font-semibold uppercase tracking-[0.32em] text-text-muted">
                    Engineering Command Center
                </span>
            </Link>
        </div>

        <div
            class="glass-card relative mt-8 w-full px-8 py-9 sm:max-w-md"
        >
            <slot />
        </div>
    </div>
</template>
