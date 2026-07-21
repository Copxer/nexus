<?php

namespace App\Models;

use App\Enums\HealthScoreBand;
use App\Enums\ProjectHealthExplanationStatus;
use Database\Factories\ProjectHealthExplanationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectHealthExplanation extends Model
{
    /** @use HasFactory<ProjectHealthExplanationFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'status',
        'health_score',
        'health_band',
        'summary',
        'drivers',
        'recommended_actions',
        'input_snapshot',
        'prompt_version',
        'model',
        'explained_at',
        'failed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectHealthExplanationStatus::class,
            'health_score' => 'integer',
            'health_band' => HealthScoreBand::class,
            'drivers' => 'array',
            'recommended_actions' => 'array',
            'input_snapshot' => 'array',
            'explained_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
