<script setup lang="ts">
import RightActivityRail from '@/Components/Activity/RightActivityRail.vue';
import CommandPalette from '@/Components/CommandPalette/CommandPalette.vue';
import Sidebar from '@/Components/Sidebar/Sidebar.vue';
import TopBar from '@/Components/TopBar/TopBar.vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const sidebarOpen = ref(false);
const activityRailOpen = ref(false);
const paletteOpen = ref(false);

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

            <main class="flex min-w-0 flex-1">
                <div class="min-w-0 flex-1 overflow-x-hidden">
                    <slot />
                </div>
            </main>
        </div>

        <!-- Persistent activity rail (≥ 2xl) -->
        <div class="relative z-30 hidden 2xl:flex">
            <RightActivityRail variant="column" />
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
