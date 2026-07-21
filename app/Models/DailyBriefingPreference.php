<?php

namespace App\Models;

use Database\Factories\DailyBriefingPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyBriefingPreference extends Model
{
    /** @use HasFactory<DailyBriefingPreferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'enabled',
        'delivery_time',
        'timezone',
        'channel_id',
        'include_projects',
        'last_sent_for_date',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'include_projects' => 'array',
            'last_sent_for_date' => 'immutable_date',
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
