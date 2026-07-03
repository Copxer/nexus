<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use Database\Factories\AlertNotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertNotificationPreference extends Model
{
    /** @use HasFactory<AlertNotificationPreferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'channel_id',
        'min_severity',
        'sources',
        'enabled',
        'notify_on_resolve',
        'rate_limit_per_hour',
    ];

    protected function casts(): array
    {
        return [
            'min_severity' => AlertSeverity::class,
            'sources' => 'array',
            'enabled' => 'boolean',
            'notify_on_resolve' => 'boolean',
            'rate_limit_per_hour' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(AlertNotificationChannel::class, 'channel_id');
    }
}
