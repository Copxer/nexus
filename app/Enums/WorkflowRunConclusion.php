<?php

namespace App\Enums;

/**
 * Terminal outcome of a GitHub Actions workflow run, mirroring GitHub's
 * `conclusion` field. Only meaningful when `WorkflowRunStatus::Completed`.
 *
 * Tones map to `StatusBadge`:
 *   success     → success
 *   failure     → danger
 *   cancelled   → warning  (user-initiated stop, not catastrophic)
 *   timed_out   → warning  (capped before completion)
 *   action_required → warning (manual intervention pending)
 *   stale       → muted    (workflow superseded)
 *   neutral     → muted    (passing-but-not-failing, used by some checks)
 *   skipped     → muted    (filtered out at workflow level)
 */
enum WorkflowRunConclusion: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Cancelled = 'cancelled';
    case TimedOut = 'timed_out';
    case ActionRequired = 'action_required';
    case Stale = 'stale';
    case Neutral = 'neutral';
    case Skipped = 'skipped';

    public function badgeTone(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::Failure => 'danger',
            self::Cancelled, self::TimedOut, self::ActionRequired => 'warning',
            self::Stale, self::Neutral, self::Skipped => 'muted',
        };
    }
}
