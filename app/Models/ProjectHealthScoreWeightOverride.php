<?php

namespace App\Models;

use Database\Factories\ProjectHealthScoreWeightOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec 046 — per-user overrides for the 8 hard-coded `DEDUCT_*`
 * constants in `ComputeProjectHealthScoreAction`. `null` on any column
 * means "use the class default"; the value object
 * (`HealthScoreWeights`) does the fallback resolution so callers stay
 * defaults-safe when the row doesn't exist or a column is unset.
 */
class ProjectHealthScoreWeightOverride extends Model
{
    /** @use HasFactory<ProjectHealthScoreWeightOverrideFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'deduct_alert_critical',
        'deduct_alert_warning',
        'deduct_deploy_failed',
        'deduct_website_slow',
        'deduct_website_down',
        'deduct_host_offline',
        'deduct_container_unhealthy',
        'deduct_gh_sync_failed',
    ];

    protected function casts(): array
    {
        return [
            'deduct_alert_critical' => 'integer',
            'deduct_alert_warning' => 'integer',
            'deduct_deploy_failed' => 'integer',
            'deduct_website_slow' => 'integer',
            'deduct_website_down' => 'integer',
            'deduct_host_offline' => 'integer',
            'deduct_container_unhealthy' => 'integer',
            'deduct_gh_sync_failed' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
