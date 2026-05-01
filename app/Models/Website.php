<?php

namespace App\Models;

use App\Enums\WebsiteStatus;
use Database\Factories\WebsiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Website extends Model
{
    /** @use HasFactory<WebsiteFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'url',
        'method',
        'expected_status_code',
        'timeout_ms',
        'check_interval_seconds',
        'status',
        'last_checked_at',
        'last_success_at',
        'last_failure_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WebsiteStatus::class,
            'expected_status_code' => 'integer',
            'timeout_ms' => 'integer',
            'check_interval_seconds' => 'integer',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(WebsiteCheck::class);
    }
}
