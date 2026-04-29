<?php

namespace App\Models;

use App\Enums\ActivitySeverity;
use Database\Factories\ActivityEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityEvent extends Model
{
    /** @use HasFactory<ActivityEventFactory> */
    use HasFactory;

    protected $fillable = [
        'repository_id',
        'actor_login',
        'source',
        'event_type',
        'severity',
        'title',
        'description',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'severity' => ActivitySeverity::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
