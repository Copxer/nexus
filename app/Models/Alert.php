<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'source',
        'source_id',
        'type',
        'severity',
        'status',
        'title',
        'description',
        'triggered_at',
        'acknowledged_at',
        'resolved_at',
        'last_seen_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'source' => AlertSource::class,
            'severity' => AlertSeverity::class,
            'status' => AlertStatus::class,
            'triggered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
