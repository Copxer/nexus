<?php

namespace App\Enums;

/**
 * Three-state PR enum, derived from GitHub's `state` + `merged` flag
 * by `NormalizeGitHubPullRequestAction`.
 *
 *  - `open`    — not merged, not closed
 *  - `closed`  — closed without merging
 *  - `merged`  — closed via merge
 *
 * Skipping the richer derived states (`draft`, `needs_review`,
 * `approved`, `changes_requested`, `checks_failed`, `merge_conflict`,
 * `ready_to_merge`, `stale`) — those need review + check sync, which
 * is phase 9 polish.
 */
enum GithubPullRequestState: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Merged = 'merged';

    public function badgeTone(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::Closed => 'muted',
            self::Merged => 'success',
        };
    }
}
