<?php

namespace App\Models;

use App\Enums\WebsiteCheckStatus;
use Database\Factories\WebsiteCheckFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteCheck extends Model
{
    /** @use HasFactory<WebsiteCheckFactory> */
    use HasFactory;

    protected $fillable = [
        'website_id',
        'status',
        'http_status_code',
        'response_time_ms',
        'error_message',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WebsiteCheckStatus::class,
            'http_status_code' => 'integer',
            'response_time_ms' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
