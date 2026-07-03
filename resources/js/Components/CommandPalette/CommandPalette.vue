<script setup lang="ts">
import CommandPaletteRow from '@/Components/CommandPalette/CommandPaletteRow.vue';
import {
    buildAsyncCommands,
    buildEntityCommands,
    commandGroupLabels,
    compareGroups,
    getCommands,
    pickRecentCommands,
    type Command,
    type CommandGroup,
    type PaletteEntityBundle,
} from '@/lib/commands';
import { fuzzyMatch } from '@/lib/fuzzyMatch';
import { getRecentCommandIds, pushRecentCommand } from '@/lib/paletteRecent';
import { searchPaletteEntities } from '@/lib/paletteSearch';
import { router, usePage } from '@inertiajs/vue3';
import { Loader2, Search } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import type { PageProps } from '@/types';

const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const staticCommands = getCommands();

/**
 * Pre-loaded entity bundle shared by `HandleInertiaRequests` (spec 043).
 * Empty bundle when the user hasn't created any entities yet — still
 * safe to iterate.
 */
const entityBundle = computed<PaletteEntityBundle | null>(() => {
    const page = usePage<PageProps>();
    return page.props.palette?.entities ?? null;
});

const entityCommands = computed<Command[]>(() =>
    buildEntityCommands(entityBundle.value),
);

/** Async server-side search results, refreshed on debounce. */
const asyncResults = ref<Command[]>([]);
const asyncLoading = ref(false);
let asyncAbort: AbortController | null = null;
let debounceHandle: ReturnType<typeof setTimeout> | null = null;

const recentCommands = ref<Command[]>([]);

/** Rebuild the recent-commands slice from localStorage. */
const refreshRecent = () => {
    recentCommands.value = pickRecentCommands(
        staticCommands,
        getRecentCommandIds(),
    );
};

const query = ref('');
const highlightedIndex = ref(0);
const inputRef = ref<HTMLInputElement | null>(null);
const listRef = ref<HTMLElement | null>(null);

/**
 * True once the user has moved the mouse inside the dialog after open.
 * Prevents the cursor's resting position from stealing the highlight from
 * the keyboard-set "first enabled row" when the palette is opened via Cmd+K.
 */
const mouseMovedSinceOpen = ref(false);

/** Element that had focus before the palette opened — restored on close. */
let returnFocusTo: HTMLElement | null = null;

/**
 * Filter + score commands.
 *
 * Empty query: recent (if any) + full static registry in declaration
 * order. Entity groups are skipped because rendering hundreds of
 * pre-loaded entities without a query would be noise.
 *
 * Non-empty query: fuzzy-match against static + entity + async pools,
 * drop misses, sort by score (desc), push disabled rows to bottom.
 */
const filtered = computed<Command[]>(() => {
    const q = query.value.trim();

    if (!q) {
        return [...recentCommands.value, ...staticCommands];
    }

    const pool: Command[] = [
        ...staticCommands,
        ...entityCommands.value,
        ...asyncResults.value,
    ];

    const scored = fuzzyMatch(pool, q, (c) => [c.label, ...(c.keywords ?? [])]);
    return scored
        .sort((a, b) => {
            // Disabled rows always sink below enabled rows.
            const aDisabled = a.item.disabled ? 1 : 0;
            const bDisabled = b.item.disabled ? 1 : 0;
            if (aDisabled !== bDisabled) return aDisabled - bDisabled;
            return b.score - a.score;
        })
        .map(({ item }) => item);
});

/** Result cap per entity group so a single project's 100 repos can't
 *  push everything else off-screen. */
const PER_GROUP_CAP = 8;

/** Which groups are entity groups — subject to the per-group cap. */
const ENTITY_GROUPS: readonly CommandGroup[] = [
    'projects',
    'repositories',
    'hosts',
    'websites',
    'workItems',
    'alerts',
];

/** Where the "Show all N" overflow row navigates for each entity kind. */
const OVERFLOW_URL_BUILDERS: Partial<Record<CommandGroup, (q: string) => string>> = {
    projects: () => route('projects.index'),
    repositories: () => route('repositories.index'),
    hosts: () => route('monitoring.hosts.index'),
    websites: () => route('monitoring.websites.index'),
    workItems: (q) => `${route('work-items.index')}?q=${encodeURIComponent(q)}`,
    alerts: () => route('alerts.index'),
};

const overflowUrl = (group: CommandGroup): string | null => {
    const builder = OVERFLOW_URL_BUILDERS[group];
    return builder ? builder(query.value.trim()) : null;
};

/**
 * Group filtered commands for rendering. Returns an array of
 * `{ group, items, hiddenCount }` in stable group order so the layout
 * doesn't jitter as the filter changes. Entity groups get capped at
 * PER_GROUP_CAP with a `hiddenCount` marker surfaced by the template.
 */
const grouped = computed<
    { group: CommandGroup; items: Command[]; hiddenCount: number }[]
>(() => {
    const buckets = new Map<CommandGroup, Command[]>();
    for (const cmd of filtered.value) {
        if (!buckets.has(cmd.group)) buckets.set(cmd.group, []);
        buckets.get(cmd.group)!.push(cmd);
    }
    return [...buckets.entries()]
        .sort(([a], [b]) => compareGroups(a, b))
        .map(([group, items]) => {
            if (ENTITY_GROUPS.includes(group) && items.length > PER_GROUP_CAP) {
                return {
                    group,
                    items: items.slice(0, PER_GROUP_CAP),
                    hiddenCount: items.length - PER_GROUP_CAP,
                };
            }
            return { group, items, hiddenCount: 0 };
        });
});

const noMatches = computed(() => filtered.value.length === 0);

/** Find the first non-disabled visible row, or 0 if everything is disabled. */
const firstEnabledIndex = computed(() => {
    const idx = filtered.value.findIndex((c) => !c.disabled);
    return idx === -1 ? 0 : idx;
});

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            returnFocusTo = document.activeElement as HTMLElement | null;
            query.value = '';
            asyncResults.value = [];
            asyncLoading.value = false;
            refreshRecent();
            highlightedIndex.value = firstEnabledIndex.value;
            mouseMovedSinceOpen.value = false;
            await nextTick();
            inputRef.value?.focus();
        } else {
            // Cancel any in-flight async request when the palette closes.
            asyncAbort?.abort();
            asyncAbort = null;
            if (debounceHandle) {
                clearTimeout(debounceHandle);
                debounceHandle = null;
            }
            if (returnFocusTo && document.contains(returnFocusTo)) {
                returnFocusTo.focus();
                returnFocusTo = null;
            }
        }
    },
);

/**
 * Debounced async server-side search. Fires 200ms after the last
 * keystroke; aborts any in-flight request when a new keystroke lands.
 * A two-character floor matches the server-side guard.
 */
watch(query, (next) => {
    if (debounceHandle) {
        clearTimeout(debounceHandle);
        debounceHandle = null;
    }
    asyncAbort?.abort();
    asyncAbort = null;

    const trimmed = next.trim();
    if (trimmed.length < 2) {
        asyncResults.value = [];
        asyncLoading.value = false;
        return;
    }

    debounceHandle = setTimeout(async () => {
        const controller = new AbortController();
        asyncAbort = controller;
        asyncLoading.value = true;

        try {
            const results = await searchPaletteEntities(trimmed, controller.signal);
            if (asyncAbort === controller) {
                asyncResults.value = buildAsyncCommands(
                    results.workItems,
                    results.alerts,
                );
                asyncLoading.value = false;
            }
        } catch {
            if (asyncAbort === controller) {
                asyncResults.value = [];
                asyncLoading.value = false;
            }
        }
    }, 200);
});

// Whenever the filter changes, reset the highlight to the first enabled row
// so the user always sees a meaningful preselection after typing.
watch(query, () => {
    highlightedIndex.value = firstEnabledIndex.value;
});

// Keep the highlighted row scrolled into view.
watch(highlightedIndex, async () => {
    await nextTick();
    const el = listRef.value?.querySelector<HTMLElement>(
        '[aria-selected="true"]',
    );
    el?.scrollIntoView({ block: 'nearest' });
});

const moveHighlight = (delta: number) => {
    const list = filtered.value;
    if (list.length === 0) return;
    let next = highlightedIndex.value;
    for (let step = 0; step < list.length; step++) {
        next = (next + delta + list.length) % list.length;
        if (!list[next].disabled) break;
    }
    highlightedIndex.value = next;
};

const runHighlighted = () => {
    const cmd = filtered.value[highlightedIndex.value];
    if (!cmd || cmd.disabled || !cmd.run) return;
    trackRecent(cmd);
    cmd.run();
    emit('close');
};

const runCommand = (cmd: Command) => {
    if (cmd.disabled || !cmd.run) return;
    trackRecent(cmd);
    cmd.run();
    emit('close');
};

/** Overflow row click: navigate to the entity's index page with the
 *  current query preserved as a filter (where the target index supports one).
 */
const runOverflow = (group: CommandGroup) => {
    const url = overflowUrl(group);
    if (!url) return;
    router.visit(url);
    emit('close');
};

/**
 * Only static commands land in Recent. Entity rows (projects, repos,
 * hosts, websites, work items, alerts) skip tracking because they're
 * already bookmarks in the sidebar / URL; surfacing them in Recent
 * would push out the actually-repeated actions.
 *
 * A `recent-*` row that the user re-runs from the Recent group maps
 * back to its canonical id so bumping doesn't create dupes.
 */
const trackRecent = (cmd: Command) => {
    if (cmd.isEntity) return;
    const canonicalId = cmd.id.startsWith('recent-')
        ? cmd.id.slice('recent-'.length)
        : cmd.id;
    pushRecentCommand(canonicalId);
};

const onKeydown = (event: KeyboardEvent) => {
    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            moveHighlight(1);
            break;
        case 'ArrowUp':
            event.preventDefault();
            moveHighlight(-1);
            break;
        case 'Enter':
            event.preventDefault();
            runHighlighted();
            break;
        case 'Escape':
            event.preventDefault();
            emit('close');
            break;
        case 'Tab':
            // Trap focus inside the dialog — input is the only tabbable
            // element so we keep focus pinned there.
            event.preventDefault();
            inputRef.value?.focus();
            break;
    }
};

/** Resolve the absolute index of a command in `filtered` (for hover highlight). */
const indexOf = (cmd: Command) => filtered.value.indexOf(cmd);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-100"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-50 bg-background-base/80 backdrop-blur-sm"
                @click="emit('close')"
                aria-hidden="true"
            />
        </Transition>
        <Transition
            enter-active-class="transition duration-150"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-100"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-2"
        >
            <div
                v-if="open"
                role="dialog"
                aria-modal="true"
                aria-label="Command palette"
                class="fixed inset-x-0 top-[12vh] z-50 mx-auto w-[92%] max-w-2xl rounded-2xl border border-border-subtle bg-background-panel shadow-panel backdrop-blur-xl"
                @keydown="onKeydown"
                @click.stop
                @mousemove="mouseMovedSinceOpen = true"
            >
                <!-- Search input row -->
                <div
                    class="flex items-center gap-3 border-b border-border-subtle px-4 py-3"
                >
                    <Search
                        class="h-4 w-4 shrink-0 text-text-muted"
                        aria-hidden="true"
                    />
                    <input
                        ref="inputRef"
                        v-model="query"
                        type="text"
                        placeholder="Type a command or search…"
                        aria-label="Command palette search"
                        aria-controls="command-palette-list"
                        :aria-activedescendant="
                            filtered[highlightedIndex]
                                ? `command-${filtered[highlightedIndex].id}`
                                : undefined
                        "
                        autocomplete="off"
                        autocorrect="off"
                        autocapitalize="off"
                        spellcheck="false"
                        class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-text-primary placeholder:text-text-muted focus:border-transparent focus:outline-none focus:ring-0"
                    />
                    <!-- Spec 043 — async search loading indicator. Announces to
                         SRs via role="status" + aria-live. -->
                    <span
                        v-if="asyncLoading"
                        role="status"
                        aria-live="polite"
                        class="shrink-0 text-text-muted"
                        aria-label="Searching…"
                    >
                        <Loader2 class="h-4 w-4 animate-spin" aria-hidden="true" />
                    </span>
                    <kbd
                        v-else
                        class="hidden shrink-0 rounded border border-border-subtle bg-slate-950/40 px-1.5 py-0.5 font-mono text-[10px] text-text-muted sm:inline-block"
                    >
                        Esc
                    </kbd>
                </div>

                <!-- Results -->
                <div
                    v-if="noMatches"
                    class="px-4 py-8 text-center text-sm text-text-muted"
                >
                    No matching commands.
                </div>
                <div
                    v-else
                    ref="listRef"
                    id="command-palette-list"
                    role="listbox"
                    aria-label="Commands"
                    class="max-h-[60vh] overflow-y-auto p-2"
                >
                    <template v-for="bucket in grouped" :key="bucket.group">
                        <div
                            class="px-3 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-[0.24em] text-text-muted"
                        >
                            {{ commandGroupLabels[bucket.group] }}
                        </div>
                        <ul class="flex flex-col gap-0.5">
                            <CommandPaletteRow
                                v-for="cmd in bucket.items"
                                :key="cmd.id"
                                :command="cmd"
                                :selected="indexOf(cmd) === highlightedIndex"
                                @select="runCommand(cmd)"
                                @mouseenter="
                                    () => {
                                        if (!mouseMovedSinceOpen) return;
                                        const i = indexOf(cmd);
                                        if (i !== -1) highlightedIndex = i;
                                    }
                                "
                            />
                            <li
                                v-if="bucket.hiddenCount > 0"
                                :class="[
                                    'flex cursor-pointer items-center gap-3 rounded-lg px-4 py-1.5 text-[11px] text-text-muted transition',
                                    'hover:bg-background-panel-hover hover:text-text-primary',
                                    'focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60',
                                ]"
                                tabindex="0"
                                role="option"
                                @click="runOverflow(bucket.group)"
                                @keydown.enter="runOverflow(bucket.group)"
                            >
                                Show all {{ bucket.items.length + bucket.hiddenCount }} in
                                {{ commandGroupLabels[bucket.group] }} →
                            </li>
                        </ul>
                    </template>
                </div>

                <!-- Footer hint row -->
                <div
                    class="flex items-center justify-between gap-3 border-t border-border-subtle px-4 py-2 text-[11px] text-text-muted"
                >
                    <span class="hidden sm:inline">
                        Navigate with
                        <kbd
                            class="rounded border border-border-subtle bg-slate-950/40 px-1 font-mono"
                        >↑</kbd>
                        <kbd
                            class="rounded border border-border-subtle bg-slate-950/40 px-1 font-mono"
                        >↓</kbd>
                        ·
                        <kbd
                            class="rounded border border-border-subtle bg-slate-950/40 px-1 font-mono"
                        >Enter</kbd>
                        to run
                    </span>
                    <span class="ms-auto">
                        Greyed entries land in later phases.
                    </span>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
