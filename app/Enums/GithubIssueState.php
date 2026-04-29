<?php

namespace App\Enums;

/**
 * GitHub issue state. The `/issues` endpoint only returns `open` or
 * `closed` for issues — pull requests have a richer derived state set
 * which lives in spec 016's PR enum.
 */
enum GithubIssueState: string
{
    case Open = 'open';
    case Closed = 'closed';

    /** Tone used by `StatusBadge` (info/warning/success/danger/muted). */
    public function badgeTone(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::Closed => 'muted',
        };
    }
}
