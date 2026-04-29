<script setup lang="ts">
import RightActivityRail from '@/Components/Activity/RightActivityRail.vue';
import CommandPalette from '@/Components/CommandPalette/CommandPalette.vue';
import Sidebar from '@/Components/Sidebar/Sidebar.vue';
import TopBar from '@/Components/TopBar/TopBar.vue';
import type { ActivityEvent, PageProps } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    /**
     * Optional populated feed forwarded into both `<RightActivityRail>`
     * instances (column + drawer). When **omitted** (the common case),
     * AppLayout falls back to the shared `activity.recent` Inertia prop
     * registered in `HandleInertiaRequests::share()` (spec 018), so any
     * authenticated page automatically lights up the rail without
     * explicit pass-through. Pages that need a different feed (e.g. a
     * project-scoped slice) can pass their own array. Pass an explicit
     * empty array to force the empty-state instead of falling back to
     * the shared feed.
     */
    activityEvents?: ActivityEvent[];
}>();

const sidebarOpen = ref(false);
const activityRailOpen = ref(false);
const paletteOpen = ref(false);

// Surface controller `->with('status'|'error', …)` flashes as a top
// banner. Pulled from the shared Inertia prop set in
// HandleInertiaRequests::share(). We snapshot to local refs on each
// page navigation so dismissing the banner via the X is sticky for the
// current page (and the next nav clears it, regardless).
const page = usePage<PageProps>();
const flashStatus = ref<string | null>(null);
const flashError = ref<string | null>(null);

const syncFlash = () => {
    flashStatus.value = page.props.flash?.status ?? null;
    flashError.value = page.props.flash?.error ?? null;
};

syncFlash();
router.on('navigate', syncFlash);

// Activity feed sourcing: an **explicit** prop (including an explicit
// empty array, to force the empty-state) wins. When the prop is absent
// entirely, fall back to the shared `activity.recent` Inertia prop
// populated by `HandleInertiaRequests::share()`. Reactive on every
// navigation so a fresh page sees fresh events without flickering.
const resolvedActivityEvents = computed<ActivityEvent[]>(() => {
    if (props.activityEvents !== undefined) {
        return props.activityEvents;
    }
    return page.props.activity?.recent ?? [];
});

const hasFlash = computed(
    () => flashStatus.value !== null || flashError.value !== null,
);

// Lock body scroll while a drawer or the palette is open. Reset on unmount
// in case the user navigates away mid-overlay.
const setBodyScroll = (locked: boolean) => {
    document.body.classList.toggle('overflow-hidden', locked);
};

watch([sidebarOpen, activityRailOpen, paletteOpen], ([s, a, p]) => {
    setBodyScroll(s || a || p);
});

// True when focus is inside a text-input-like element. Used to avoid
// hijacking Cmd+K while the user is typing into a real form field.
const isTextInput = (target: EventTarget | null): boolean => {
    if (!(target instanceof HTMLElement)) return false;
    const tag = target.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || target.isContentEditable;
};

const onKeydown = (event: KeyboardEvent) => {
    // Cmd+K (mac) / Ctrl+K (others) opens the command palette from anywhere
    // outside text inputs. The palette's own Escape/Enter/arrow handling
    // takes over once it's open and focused.
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        // No-op (but still preventDefault) when the palette is already open
        // so the keystroke isn't delivered to the palette's own input or
        // hijacked by the browser's "search bar" shortcut.
        if (paletteOpen.value) {
            event.preventDefault();
            return;
        }
        if (isTextInput(event.target)) return;
        event.preventDefault();
        paletteOpen.value = true;
        return;
    }

    if (event.key !== 'Escape') return;
    // Palette has its own Escape handler scoped to the dialog; bail here so
    // we don't double-fire (and so a future drawer + palette overlap doesn't
    // close the wrong overlay).
    if (paletteOpen.value) return;
    if (sidebarOpen.value) {
        sidebarOpen.value = false;
    } else if (activityRailOpen.value) {
        activityRailOpen.value = false;
    }
};

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    setBodyScroll(false);
});
</script>

<template>
    <div
        class="relative isolate flex min-h-screen bg-app-gradient text-text-primary"
    >
        <!-- Persistent sidebar (≥ lg) -->
        <div class="relative z-30 hidden lg:flex">
            <Sidebar variant="column" />
        </div>

        <!-- Sidebar drawer (< lg) -->
        <Transition
            enter-active-class="transition duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="sidebarOpen"
                class="fixed inset-0 z-40 bg-background-base/80 backdrop-blur-sm lg:hidden"
                @click="sidebarOpen = false"
                aria-hidden="true"
            />
        </Transition>
        <Transition
            enter-active-class="transition duration-200"
            enter-from-class="-translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition duration-150"
            leave-from-class="translate-x-0"
            leave-to-class="-translate-x-full"
        >
            <div
                v-if="sidebarOpen"
                class="fixed inset-y-0 start-0 z-40 lg:hidden"
                role="dialog"
                aria-modal="true"
                aria-label="Navigation"
            >
                <Sidebar variant="drawer" @close="sidebarOpen = false" />
            </div>
        </Transition>

        <!-- Main column -->
        <div class="flex min-w-0 flex-1 flex-col">
            <TopBar
                @open-sidebar="sidebarOpen = true"
                @open-activity-rail="activityRailOpen = true"
                @open-palette="paletteOpen = true"
            >
                <template v-if="$slots.title" #title>
                    <slot name="title" />
                </template>
            </TopBar>

            <!-- Flash banner — surfaces controller `with('status'|'error')`
                 messages so OAuth callbacks, sync triggers, and link
                 actions don't fail silently. -->
            <div
                v-if="hasFlash"
                class="px-4 pt-3 sm:px-6 lg:px-8"
                role="status"
                aria-live="polite"
            >
                <div
                    v-if="flashError"
                    class="flex items-start gap-3 rounded-lg border border-status-danger/40 bg-status-danger/10 p-3 text-sm text-status-danger"
                >
                    <AlertTriangle
                        class="mt-0.5 h-4 w-4 shrink-0"
                        aria-hidden="true"
                    />
                    <p class="flex-1 leading-snug">{{ flashError }}</p>
                    <button
                        type="button"
                        class="rounded p-0.5 text-status-danger/70 transition hover:text-status-danger focus:outline-none focus-visible:ring-2 focus-visible:ring-status-danger/60"
                        aria-label="Dismiss error"
                        @click="flashError = null"
                    >
                        <X class="h-4 w-4" aria-hidden="true" />
                    </button>
                </div>
                <div
                    v-if="flashStatus"
                    class="mt-2 flex items-start gap-3 rounded-lg border border-status-success/40 bg-status-success/10 p-3 text-sm text-status-success"
                    :class="{ 'mt-0': !flashError }"
                >
                    <CheckCircle2
                        class="mt-0.5 h-4 w-4 shrink-0"
                        aria-hidden="true"
                    />
                    <p class="flex-1 leading-snug">{{ flashStatus }}</p>
                    <button
                        type="button"
                        class="rounded p-0.5 text-status-success/70 transition hover:text-status-success focus:outline-none focus-visible:ring-2 focus-visible:ring-status-success/60"
                        aria-label="Dismiss notification"
                        @click="flashStatus = null"
                    >
                        <X class="h-4 w-4" aria-hidden="true" />
                    </button>
                </div>
            </div>

            <main class="flex min-w-0 flex-1">
                <div class="min-w-0 flex-1 overflow-x-hidden">
                    <slot />
                </div>
            </main>
        </div>

        <!-- Persistent activity rail (≥ 2xl) -->
        <div class="relative z-30 hidden 2xl:flex">
            <RightActivityRail variant="column" :events="resolvedActivityEvents" />
        </div>

        <!-- Activity rail drawer (md – 2xl) -->
        <Transition
            enter-active-class="transition duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="activityRailOpen"
                class="fixed inset-0 z-40 bg-background-base/80 backdrop-blur-sm 2xl:hidden"
                @click="activityRailOpen = false"
                aria-hidden="true"
            />
        </Transition>
        <Transition
            enter-active-class="transition duration-200"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition duration-150"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <div
                v-if="activityRailOpen"
                class="fixed inset-y-0 end-0 z-40 2xl:hidden"
                role="dialog"
                aria-modal="true"
                aria-label="Activity"
            >
                <RightActivityRail
                    variant="drawer"
                    :events="resolvedActivityEvents"
                    @close="activityRailOpen = false"
                />
            </div>
        </Transition>

        <!-- Global command palette (spec 005) -->
        <CommandPalette
            :open="paletteOpen"
            @close="paletteOpen = false"
        />
    </div>
</template>
