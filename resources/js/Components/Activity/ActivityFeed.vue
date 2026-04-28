<script setup lang="ts">
import ActivityFeedItem from '@/Components/Activity/ActivityFeedItem.vue';
import type { ActivityEvent } from '@/types';
import { ref } from 'vue';

defineProps<{
    events: ActivityEvent[];
}>();

/**
 * Visual-only "Recent / All" tab pill. Real filtering arrives when real
 * integrations land — same treatment as the disabled rail-header filter.
 */
const activeTab = ref<'recent' | 'all'>('recent');
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col gap-3">
        <!-- Tab pill (visual-only) -->
        <div
            class="inline-flex w-fit rounded-full border border-border-subtle bg-slate-950/40 p-0.5 text-[10px] font-semibold uppercase tracking-[0.18em]"
        >
            <button
                type="button"
                class="rounded-full px-2.5 py-1 transition"
                :class="
                    activeTab === 'recent'
                        ? 'bg-accent-cyan/15 text-accent-cyan'
                        : 'text-text-muted hover:text-text-secondary'
                "
                @click="activeTab = 'recent'"
            >
                Recent
            </button>
            <button
                type="button"
                class="rounded-full px-2.5 py-1 transition"
                :class="
                    activeTab === 'all'
                        ? 'bg-accent-cyan/15 text-accent-cyan'
                        : 'text-text-muted hover:text-text-secondary'
                "
                title="Activity filtering arrives with the real feed."
                @click="activeTab = 'all'"
            >
                All
            </button>
        </div>

        <!-- Feed -->
        <ul
            aria-label="Recent activity"
            class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto"
        >
            <ActivityFeedItem
                v-for="event in events"
                :key="event.id"
                :event="event"
            />
        </ul>
    </div>
</template>
