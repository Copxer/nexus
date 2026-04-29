<?php

namespace App\Enums;

/**
 * Lifecycle of a repository's GitHub-sync state. Drives the sync-status
 * badge on Repositories index/show. Mirrors roadmap §8.3.
 *
 * `pending` = manually linked, no sync attempted yet (the only state
 * spec 011 actually produces). `syncing|synced|failed` are populated
 * by the GitHub-sync job in phase 2.
 */
enum RepositorySyncStatus: string
{
    case Pending = 'pending';
    case Syncing = 'syncing';
    case Synced = 'synced';
    case Failed = 'failed';

    /** Tone used by `StatusBadge` (info/warning/success/danger). */
    public function badgeTone(): string
    {
        return match ($this) {
            self::Pending => 'muted',
            self::Syncing => 'info',
            self::Synced => 'success',
            self::Failed => 'danger',
        };
    }
}
