<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    protected $table = 'github_webhook_deliveries';

    protected $fillable = [
        'github_delivery_id',
        'event',
        'action',
        'repository_full_name',
        'payload_json',
        'signature',
        'status',
        'error_message',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WebhookDeliveryStatus::class,
            'payload_json' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
