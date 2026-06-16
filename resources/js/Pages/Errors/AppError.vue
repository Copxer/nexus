<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { AlertOctagon, RefreshCw } from 'lucide-vue-next';

/**
 * Spec 036 — fallback page rendered by the `withExceptions` hook
 * in `bootstrap/app.php` when an unhandled 500-class exception
 * escapes. Validation 422s + 403/404 + auth redirects all bypass
 * this page (and surface through their existing UX).
 *
 * The user sees a stable card with a `Try again` action that
 * re-issues the failed visit via `router.visit(window.location)`.
 * The stack trace stays in the server logs.
 */
const props = defineProps<{
    status: number;
    message: string;
}>();

const retry = () => {
    router.visit(window.location.href, { preserveScroll: true });
};
</script>

<template>
    <Head title="Something went wrong" />

    <AppLayout>
        <template #title>
            <div class="flex flex-col">
                <span
                    class="text-[11px] font-semibold uppercase tracking-[0.32em] text-status-danger"
                >
                    Error {{ props.status }}
                </span>
                <h1 class="text-lg font-semibold text-text-primary">
                    Something went wrong
                </h1>
            </div>
        </template>

        <div class="flex items-center justify-center px-4 py-16 sm:px-6 lg:px-8">
            <section
                class="glass-card flex max-w-md flex-col items-center gap-5 p-8 text-center"
            >
                <span
                    class="flex h-14 w-14 items-center justify-center rounded-full border border-status-danger/30 bg-status-danger/10"
                >
                    <AlertOctagon
                        class="h-7 w-7 text-status-danger"
                        aria-hidden="true"
                    />
                </span>
                <div class="flex flex-col gap-2">
                    <h2 class="text-lg font-semibold text-text-primary">
                        {{ props.message }}
                    </h2>
                    <p class="text-sm text-text-secondary">
                        The error has been logged. You can try again, or head
                        back to the overview.
                    </p>
                </div>
                <div class="flex flex-wrap items-center justify-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel-hover px-3 py-2 text-sm font-semibold text-text-primary transition hover:border-accent-cyan/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        @click="retry"
                    >
                        <RefreshCw class="h-4 w-4" aria-hidden="true" />
                        Try again
                    </button>
                    <a
                        :href="route('overview')"
                        class="inline-flex items-center gap-2 rounded-lg border border-border-subtle bg-background-panel px-3 py-2 text-sm font-semibold text-text-secondary transition hover:border-accent-cyan/40 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    >
                        Back to overview
                    </a>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
