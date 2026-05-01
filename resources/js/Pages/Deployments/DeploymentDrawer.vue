<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import {
    ExternalLink,
    GitBranch,
    GitCommit,
    Tag,
    User as UserIcon,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import type { DeploymentRow } from './Index.vue';

const props = defineProps<{
    run: DeploymentRow | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const isOpen = computed(() => props.run !== null);

const closeButtonRef = ref<HTMLButtonElement | null>(null);
const drawerRef = ref<HTMLDivElement | null>(null);

// Mirror tone helpers from Index.vue. Duplicated rather than hoisted
// so the drawer is portable as a self-contained component for now.
// If a third consumer arrives we'll extract.
const conclusionTone = (conclusion: string | null) =>
    (
        ({
            success: 'success',
            failure: 'danger',
            cancelled: 'warning',
            timed_out: 'warning',
            action_required: 'warning',
            stale: 'muted',
            neutral: 'muted',
            skipped: 'muted',
        }) as const
    )[conclusion ?? ''] ?? 'muted';

const statusTone = (status: string | null) =>
    (
        ({
            queued: 'muted',
            in_progress: 'info',
            completed: 'success',
        }) as const
    )[status ?? ''] ?? 'muted';

const conclusionLabel = (conclusion: string | null) =>
    conclusion === null ? '—' : conclusion.replace(/_/g, ' ');

// `4m 12s` / `1h 03m 14s` style duration. Bounded by `duration_seconds`
// from the controller payload (server already abs'd it).
const formatDuration = (seconds: number | null): string => {
    if (seconds === null) return '—';
    if (seconds < 60) return `${seconds}s`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    if (m < 60) return `${m}m ${String(s).padStart(2, '0')}s`;
    const h = Math.floor(m / 60);
    const remM = m % 60;
    return `${h}h ${String(remM).padStart(2, '0')}m ${String(s).padStart(2, '0')}s`;
};

// Short SHA + tooltip with full SHA. GitHub's convention is 7 chars.
const shortSha = (sha: string): string => sha.slice(0, 7);

// Esc-to-close + focus management.
const onKeydown = (ev: KeyboardEvent) => {
    if (ev.key === 'Escape' && isOpen.value) {
        emit('close');
    }
};

watch(isOpen, async (open) => {
    if (open) {
        document.addEventListener('keydown', onKeydown);
        await nextTick();
        closeButtonRef.value?.focus();
    } else {
        document.removeEventListener('keydown', onKeydown);
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="isOpen"
                class="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-sm"
                @click="emit('close')"
            />
        </Transition>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <aside
                v-if="isOpen && run"
                ref="drawerRef"
                role="dialog"
                aria-modal="true"
                aria-labelledby="deployment-drawer-title"
                class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col overflow-y-auto border-l border-border-subtle bg-background-panel p-6 shadow-2xl"
            >
                <header
                    class="mb-6 flex items-start justify-between gap-3 border-b border-border-subtle pb-4"
                >
                    <div class="flex flex-col gap-1">
                        <span
                            class="font-mono text-[10px] uppercase tracking-[0.22em] text-accent-cyan"
                        >
                            Workflow run
                        </span>
                        <h2
                            id="deployment-drawer-title"
                            class="text-lg font-semibold text-text-primary"
                        >
                            <span class="font-mono text-text-muted"
                                >#{{ run.run_number }}</span
                            >
                            {{ run.name }}
                        </h2>
                        <p
                            v-if="run.repository"
                            class="font-mono text-xs text-text-secondary"
                        >
                            {{ run.repository.full_name }}
                        </p>
                    </div>
                    <button
                        ref="closeButtonRef"
                        type="button"
                        class="rounded-md border border-border-subtle bg-background-panel-hover p-1.5 text-text-secondary transition hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                        aria-label="Close drawer"
                        @click="emit('close')"
                    >
                        <X class="h-4 w-4" aria-hidden="true" />
                    </button>
                </header>

                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Status
                        </dt>
                        <dd>
                            <StatusBadge
                                v-if="run.status"
                                :tone="statusTone(run.status)"
                            >
                                {{ run.status }}
                            </StatusBadge>
                            <span v-else class="text-text-muted">—</span>
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Conclusion
                        </dt>
                        <dd>
                            <StatusBadge
                                v-if="run.conclusion"
                                :tone="conclusionTone(run.conclusion)"
                            >
                                {{ conclusionLabel(run.conclusion) }}
                            </StatusBadge>
                            <span v-else class="text-text-muted">—</span>
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Trigger
                        </dt>
                        <dd class="flex items-center gap-1.5 font-mono text-text-secondary">
                            <Tag
                                class="h-3 w-3 text-text-muted"
                                aria-hidden="true"
                            />
                            {{ run.event }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Branch
                        </dt>
                        <dd
                            class="flex items-center gap-1.5 font-mono text-text-secondary"
                        >
                            <GitBranch
                                v-if="run.head_branch"
                                class="h-3 w-3 text-text-muted"
                                aria-hidden="true"
                            />
                            {{ run.head_branch ?? '—' }}
                        </dd>
                    </div>
                    <div class="col-span-2 flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Commit
                        </dt>
                        <dd
                            class="flex items-center gap-1.5 font-mono text-text-secondary"
                        >
                            <GitCommit
                                class="h-3 w-3 text-text-muted"
                                aria-hidden="true"
                            />
                            <span :title="run.head_sha">{{ shortSha(run.head_sha) }}</span>
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Actor
                        </dt>
                        <dd
                            class="flex items-center gap-1.5 font-mono text-text-secondary"
                        >
                            <UserIcon
                                v-if="run.actor_login"
                                class="h-3 w-3 text-text-muted"
                                aria-hidden="true"
                            />
                            {{ run.actor_login ? `@${run.actor_login}` : '—' }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Duration
                        </dt>
                        <dd class="font-mono text-text-secondary">
                            {{ formatDuration(run.duration_seconds) }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Started
                        </dt>
                        <dd class="text-text-secondary">
                            {{ run.run_started_at ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt
                            class="font-mono text-[10px] uppercase tracking-[0.18em] text-text-muted"
                        >
                            Updated
                        </dt>
                        <dd class="text-text-secondary">
                            {{ run.run_updated_at ?? '—' }}
                        </dd>
                    </div>
                </dl>

                <a
                    :href="run.html_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-6 inline-flex items-center justify-center gap-2 rounded-lg border border-accent-cyan/40 bg-accent-cyan/15 px-3 py-2 text-sm font-semibold text-accent-cyan transition hover:border-accent-cyan/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                >
                    <ExternalLink class="h-4 w-4" aria-hidden="true" />
                    Open on GitHub
                </a>
            </aside>
        </Transition>
    </Teleport>
</template>
