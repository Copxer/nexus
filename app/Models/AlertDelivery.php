<?php

namespace App\Models;

use App\Enums\AlertDeliveryStatus;
use Database\Factories\AlertDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertDelivery extends Model
{
    /** @use HasFactory<AlertDeliveryFactory> */
    use HasFactory;

    protected $fillable = [
        'alert_id',
        'channel_id',
        'status',
        'attempts',
        'last_attempt_at',
        'sent_at',
        'error_message',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => AlertDeliveryStatus::class,
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(AlertNotificationChannel::class, 'channel_id');
    }
}
