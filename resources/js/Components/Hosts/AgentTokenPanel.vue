<script setup lang="ts">
import DangerButton from '@/Components/DangerButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    Copy,
    KeyRound,
    RefreshCcw,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface ActiveAgentToken {
    id: number;
    name: string | null;
    last_used_at: string | null;
    created_at: string | null;
}

const props = defineProps<{
    hostId: number;
    activeToken: ActiveAgentToken | null;
    canManageTokens: boolean;
}>();

// Plaintext flashed by the controller after issue/rotate. Read-only —
// once the user navigates away the page reload drops it. We never echo
// it into anything but the copy-to-clipboard / display affordance below.
const page = usePage();
const flashedPlaintext = computed<string | null>(
    () => page.props.flash?.agentTokenPlaintext ?? null,
);

const copied = ref(false);
const copyToClipboard = async () => {
    if (!flashedPlaintext.value) return;
    try {
        await navigator.clipboard.writeText(flashedPlaintext.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Clipboard unavailable (insecure context, denied permission).
        // The user can still select-and-copy the visible string.
    }
};

const issueToken = () => {
    if (!props.canManageTokens) return;
    router.post(
        route('monitoring.hosts.tokens.store', props.hostId),
        {},
        { preserveScroll: true },
    );
};

const rotateToken = () => {
    if (!props.canManageTokens || !props.activeToken) return;
    if (
        !window.confirm(
            'Rotate this agent token? The current token will stop working immediately.',
        )
    ) {
        return;
    }
    router.post(
        route('monitoring.hosts.tokens.rotate', [
            props.hostId,
            props.activeToken.id,
        ]),
        {},
        { preserveScroll: true },
    );
};

const revokeToken = () => {
    if (!props.canManageTokens || !props.activeToken) return;
    if (
        !window.confirm(
            'Revoke this agent token? The host will stop ingesting telemetry until a new token is minted.',
        )
    ) {
        return;
    }
    router.delete(
        route('monitoring.hosts.tokens.destroy', [
            props.hostId,
            props.activeToken.id,
        ]),
        { preserveScroll: true },
    );
};
</script>

<template>
    <section
        class="glass-card flex flex-col gap-4 p-6"
        aria-labelledby="agent-token-heading"
    >
        <header class="flex items-center gap-2">
            <KeyRound
                class="h-4 w-4 text-accent-cyan"
                aria-hidden="true"
            />
            <h2
                id="agent-token-heading"
                class="text-sm font-semibold uppercase tracking-[0.32em] text-text-secondary"
            >
                Agent token
            </h2>
        </header>

        <div
            v-if="flashedPlaintext"
            class="flex flex-col gap-2 rounded-lg border border-status-warning/40 bg-status-warning/10 p-3"
            role="status"
        >
            <div
                class="flex items-start gap-2 text-xs text-status-warning"
            >
                <AlertTriangle
                    class="mt-0.5 h-4 w-4 shrink-0"
                    aria-hidden="true"
                />
                <p class="leading-relaxed">
                    Copy this token now — Nexus stores only a hash and
                    cannot show it again. Anyone with this token can
                    submit telemetry as this host.
                </p>
            </div>
            <div
                class="flex items-center gap-2 rounded-md border border-border-subtle bg-slate-950/80 px-3 py-2"
            >
                <code
                    class="flex-1 truncate font-mono text-xs text-text-primary"
                    aria-label="Agent token plaintext"
                >
                    {{ flashedPlaintext }}
                </code>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md border border-accent-cyan/40 bg-accent-cyan/10 px-2 py-1 text-[11px] font-semibold text-accent-cyan transition hover:border-accent-cyan/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-cyan/60"
                    @click="copyToClipboard"
                >
                    <component
                        :is="copied ? Check : Copy"
                        class="h-3 w-3"
                        aria-hidden="true"
                    />
                    {{ copied ? 'Copied' : 'Copy' }}
                </button>
            </div>
        </div>

        <div
            v-if="activeToken"
            class="flex flex-col gap-1 rounded-md border border-border-subtle bg-background-panel-hover px-3 py-2 text-xs text-text-secondary"
        >
            <div class="flex items-center gap-2 text-status-success">
                <ShieldCheck class="h-4 w-4" aria-hidden="true" />
                <span class="font-semibold uppercase tracking-[0.2em]">
                    Active token
                </span>
            </div>
            <p>
                <span v-if="activeToken.last_used_at">
                    Last seen {{ activeToken.last_used_at }}
                </span>
                <span v-else>Never used yet — awaiting first telemetry.</span>
            </p>
        </div>

        <div
            v-else
            class="flex items-start gap-2 rounded-md border border-border-subtle bg-background-panel-hover px-3 py-2 text-xs text-text-muted"
        >
            <ShieldAlert
                class="mt-0.5 h-4 w-4 shrink-0"
                aria-hidden="true"
            />
            <p>
                No active token. Mint one to authorise the agent that
                runs on this host.
            </p>
        </div>

        <div
            v-if="canManageTokens"
            class="flex flex-wrap items-center justify-end gap-2"
        >
            <PrimaryButton
                v-if="!activeToken"
                type="button"
                @click="issueToken"
            >
                Mint agent token
            </PrimaryButton>
            <template v-else>
                <SecondaryButton type="button" @click="rotateToken">
                    <RefreshCcw class="mr-1 h-3 w-3" aria-hidden="true" />
                    Rotate
                </SecondaryButton>
                <DangerButton type="button" @click="revokeToken">
                    Revoke
                </DangerButton>
            </template>
        </div>
    </section>
</template>
