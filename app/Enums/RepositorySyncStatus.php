<?php

namespace App\Enums;

/**
 * Lifecycle of a repository's GitHub-sync state. Drives the sync-status
 * badge on Repositories index/show. Mirrors roadmap §8.3.
 *
 * `pending` = manually linked, no sync attempted yet (the only state
 * spec 011 actually produces). `syncing|synced|failed` are populated
 * by the GitHub-sync job in phase 2.
 *
 * Spec 037 added `rate_limited` + `unauthorized` to decompose the
 * §18.2 error vocabulary: `rate_limited` is a transient failure that
 * will self-heal on the next retry (sync jobs `release()` until the
 * reset window), `unauthorized` requires user action (re-auth or
 * regenerate the token) and shouldn't auto-retry.
 */
enum RepositorySyncStatus: string
{
    case Pending = 'pending';
    case Syncing = 'syncing';
    case Synced = 'synced';
    case Failed = 'failed';
    case RateLimited = 'rate_limited';
    case Unauthorized = 'unauthorized';

    /** Tone used by `StatusBadge` (info/warning/success/danger/muted). */
    public function badgeTone(): string
    {
        return match ($this) {
            self::Pending => 'muted',
            self::Syncing => 'info',
            self::Synced => 'success',
            self::Failed, self::Unauthorized => 'danger',
            self::RateLimited => 'warning',
        };
    }
}
