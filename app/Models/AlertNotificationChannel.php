<?php

namespace App\Models;

use App\Enums\NotificationChannelKind;
use Database\Factories\AlertNotificationChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertNotificationChannel extends Model
{
    /** @use HasFactory<AlertNotificationChannelFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'kind',
        'name',
        'config',
        'enabled',
        'verified_at',
    ];

    protected $hidden = [
        // The Slack webhook URL and generic-webhook signing_secret sit
        // inside `config`. Keep the whole column out of Inertia / JSON
        // responses; the settings page reads it explicitly.
        'config',
    ];

    protected function casts(): array
    {
        return [
            'kind' => NotificationChannelKind::class,
            'config' => 'encrypted:array',
            'enabled' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(AlertNotificationPreference::class, 'channel_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class, 'channel_id');
    }
}
