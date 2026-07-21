<?php

namespace App\Models;

use App\Enums\DailyBriefingStatus;
use Database\Factories\DailyBriefingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyBriefing extends Model
{
    /** @use HasFactory<DailyBriefingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'briefing_date',
        'status',
        'input_snapshot',
        'summary',
        'highlights',
        'risks',
        'prompt_version',
        'generated_at',
        'delivered_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'briefing_date' => 'immutable_date',
            'status' => DailyBriefingStatus::class,
            'input_snapshot' => 'array',
            'highlights' => 'array',
            'risks' => 'array',
            'generated_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
