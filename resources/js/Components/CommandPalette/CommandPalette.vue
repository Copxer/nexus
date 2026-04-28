<script setup lang="ts">
import CommandPaletteRow from '@/Components/CommandPalette/CommandPaletteRow.vue';
import {
    commandGroupLabels,
    compareGroups,
    getCommands,
    type Command,
    type CommandGroup,
} from '@/lib/commands';
import { fuzzyMatch } from '@/lib/fuzzyMatch';
import { Search } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const commands = getCommands();

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
 * Filter + score commands. Empty query returns the full registry in
 * declaration order (stable). Non-empty query runs fuzzyMatch against
 * label + keywords, drops misses, sorts by score (desc), and pushes
 * disabled rows to the bottom of each group so real matches come first.
 */
const filtered = computed<Command[]>(() => {
    const q = query.value.trim();
    if (!q) return commands;

    const scored = fuzzyMatch(commands, q, (c) => [c.label, ...(c.keywords ?? [])]);
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

/**
 * Group filtered commands for rendering. Returns an array of
 * `{ group, items }` in stable group order so the layout doesn't jitter
 * as the filter changes.
 */
const grouped = computed<{ group: CommandGroup; items: Command[] }[]>(() => {
    const buckets = new Map<CommandGroup, Command[]>();
    for (const cmd of filtered.value) {
        if (!buckets.has(cmd.group)) buckets.set(cmd.group, []);
        buckets.get(cmd.group)!.push(cmd);
    }
    return [...buckets.entries()]
        .sort(([a], [b]) => compareGroups(a, b))
        .map(([group, items]) => ({ group, items }));
});

/** Flat array of visible commands — index aligns with `highlightedIndex`. */
const flatVisible = computed<Command[]>(() => filtered.value);

const noMatches = computed(() => flatVisible.value.length === 0);

/** Find the first non-disabled visible row, or 0 if everything is disabled. */
const firstEnabledIndex = computed(() => {
    const idx = flatVisible.value.findIndex((c) => !c.disabled);
    return idx === -1 ? 0 : idx;
});

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            returnFocusTo = document.activeElement as HTMLElement | null;
            query.value = '';
            highlightedIndex.value = firstEnabledIndex.value;
            mouseMovedSinceOpen.value = false;
            await nextTick();
            inputRef.value?.focus();
        } else if (returnFocusTo && document.contains(returnFocusTo)) {
            returnFocusTo.focus();
            returnFocusTo = null;
        }
    },
);

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
    const list = flatVisible.value;
    if (list.length === 0) return;
    let next = highlightedIndex.value;
    for (let step = 0; step < list.length; step++) {
        next = (next + delta + list.length) % list.length;
        if (!list[next].disabled) break;
    }
    highlightedIndex.value = next;
};

const runHighlighted = () => {
    const cmd = flatVisible.value[highlightedIndex.value];
    if (!cmd || cmd.disabled || !cmd.run) return;
    cmd.run();
    emit('close');
};

const runCommand = (cmd: Command) => {
    if (cmd.disabled || !cmd.run) return;
    cmd.run();
    emit('close');
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

/** Resolve the absolute index of a command in flatVisible (for hover highlight). */
const indexOf = (cmd: Command) => flatVisible.value.indexOf(cmd);
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
                            flatVisible[highlightedIndex]
                                ? `command-${flatVisible[highlightedIndex].id}`
                                : undefined
                        "
                        autocomplete="off"
                        autocorrect="off"
                        autocapitalize="off"
                        spellcheck="false"
                        class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-text-primary placeholder:text-text-muted focus:border-transparent focus:outline-none focus:ring-0"
                    />
                    <kbd
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
