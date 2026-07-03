<?php

namespace App\Models;

use App\Enums\AlertRuleKind;
use App\Enums\AlertSeverity;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'kind',
        'severity',
        'config',
        'enabled',
        'last_evaluated_at',
        'last_triggered_at',
        'cool_down_minutes',
    ];

    protected function casts(): array
    {
        return [
            'kind' => AlertRuleKind::class,
            'severity' => AlertSeverity::class,
            'config' => 'array',
            'enabled' => 'boolean',
            'last_evaluated_at' => 'datetime',
            'last_triggered_at' => 'datetime',
            'cool_down_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True when the rule is within its cool-down window. Spec 046 —
     * a stuck condition shouldn't re-fire every scheduled tick.
     */
    public function isInCoolDown(): bool
    {
        if ($this->last_triggered_at === null) {
            return false;
        }

        return $this->last_triggered_at
            ->addMinutes($this->cool_down_minutes)
            ->isFuture();
    }
}
