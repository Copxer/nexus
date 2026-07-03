<?php

namespace App\Models;

use Database\Factories\PublicStatusSubscriberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Spec 047 — anonymous public-status-page subscribers. Distinct from
 * spec 042's `AlertNotificationChannel` (which is user-scoped +
 * multi-driver): this row is email-only + tied to a project + carries
 * its own confirm / unsubscribe token pair.
 */
class PublicStatusSubscriber extends Model
{
    /** @use HasFactory<PublicStatusSubscriberFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'email',
        'confirmation_token',
        'unsubscribe_token',
        'confirmed_at',
    ];

    protected $hidden = [
        // Never leak subscriber tokens through model serialization; the
        // controllers read them explicitly.
        'confirmation_token',
        'unsubscribe_token',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public static function freshToken(): string
    {
        return Str::random(64);
    }
}
