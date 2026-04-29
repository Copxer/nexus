<script setup lang="ts">
import StatusBadge from '@/Components/Dashboard/StatusBadge.vue';
import type { ActivityEvent, ActivityEventType } from '@/types';
import {
    AlertOctagon,
    AlertTriangle,
    Boxes,
    CheckCircle2,
    Eye,
    GitMerge,
    GitPullRequest,
    GitPullRequestClosed,
    Globe,
    MessageSquare,
    RotateCcw,
    Rocket,
    Server,
    Tag,
    XCircle,
    type LucideIcon,
} from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    event: ActivityEvent;
}>();

/**
 * Lucide icon per event-type. Choices follow the §8.10 vocabulary:
 *   - `*.merged` / `*.succeeded` / `*.recovered` / `*.resolved` → cheerful icons
 *   - `*.failed` / `*.down` / `*.offline` / `*.triggered` → warning/error icons
 *   - `pull_request.review_requested` / `issue.*` → review/messaging icons
 */
const iconMap: Record<ActivityEventType, LucideIcon> = {
    'issue.created': MessageSquare,
    'issue.closed': CheckCircle2,
    'issue.reopened': RotateCcw,
    'issue.updated': MessageSquare,
    'pull_request.opened': GitPullRequest,
    'pull_request.merged': GitMerge,
    'pull_request.closed': GitPullRequestClosed,
    'pull_request.review_requested': Eye,
    'workflow.failed': XCircle,
    'workflow.succeeded': CheckCircle2,
    'release.published': Tag,
    'deployment.started': Rocket,
    'deployment.succeeded': Rocket,
    'deployment.failed': AlertOctagon,
    'website.down': Globe,
    'website.recovered': Globe,
    'host.offline': Server,
    'host.recovered': Server,
    'container.unhealthy': Boxes,
    'alert.triggered': AlertTriangle,
    'alert.resolved': CheckCircle2,
};

/** Tailwind text-color class per severity. Glow reserved for `success`. */
const severityIconClass = {
    success: 'text-status-success',
    warning: 'text-status-warning',
    danger: 'text-status-danger',
    info: 'text-status-info',
    muted: 'text-text-muted',
} as const;

const icon = computed<LucideIcon>(() => iconMap[props.event.type]);
</script>

<template>
    <li
        :aria-label="`${event.title} — ${event.source} ${event.occurred_at}`"
        class="flex items-start gap-3 rounded-lg border border-border-subtle bg-background-panel-hover/40 p-3"
    >
        <!-- Severity-tinted icon column -->
        <span
            class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-border-subtle bg-slate-950/40"
        >
            <component
                :is="icon"
                class="h-3.5 w-3.5"
                :class="severityIconClass[event.severity]"
                aria-hidden="true"
            />
        </span>

        <!-- Two-line content -->
        <div class="min-w-0 flex-1">
            <p class="line-clamp-2 text-xs leading-snug text-text-primary">
                {{ event.title }}
            </p>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <span class="font-mono text-[10px] text-text-muted">
                    {{ event.source }} · {{ event.occurred_at }}
                </span>
                <StatusBadge v-if="event.metadata" tone="muted">
                    {{ event.metadata }}
                </StatusBadge>
            </div>
        </div>
    </li>
</template>
