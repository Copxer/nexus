<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec 038 — periodic snapshot of GitHub's REST API rate-limit
 * state per connected user. Read-only model — populated by the
 * scheduled `CheckGitHubRateLimitJob` (10-min cadence), consumed
 * by `GetSystemHealthQuery`.
 */
class GithubRateLimitSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'remaining',
        'limit',
        'reset_at',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'remaining' => 'integer',
            'limit' => 'integer',
            'reset_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
