<?php

namespace App\Enums;

/**
 * Lifecycle of a GitHub Actions workflow run, mirroring GitHub's `status`
 * field on the `actions/runs` payload. `conclusion` (separate enum) is
 * only set once status flips to `completed`.
 *
 * Listed here are the three values GitHub commits to in their docs:
 * https://docs.github.com/en/rest/actions/workflow-runs — newer states
 * (e.g. `requested`, `waiting`, `pending`) collapse to `Queued` when we
 * normalize, since the user-visible distinction isn't meaningful.
 */
enum WorkflowRunStatus: string
{
    case Queued = 'queued';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    /** Tone used by `StatusBadge` (muted/info/success/warning/danger). */
    public function badgeTone(): string
    {
        return match ($this) {
            self::Queued => 'muted',
            self::InProgress => 'info',
            self::Completed => 'success',
        };
    }
}
