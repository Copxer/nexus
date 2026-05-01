<?php

namespace App\Models;

use App\Enums\WorkflowRunConclusion;
use App\Enums\WorkflowRunStatus;
use Database\Factories\WorkflowRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowRun extends Model
{
    /** @use HasFactory<WorkflowRunFactory> */
    use HasFactory;

    protected $table = 'workflow_runs';

    protected $fillable = [
        'repository_id',
        'github_id',
        'run_number',
        'name',
        'event',
        'status',
        'conclusion',
        'head_branch',
        'head_sha',
        'actor_login',
        'html_url',
        'run_started_at',
        'run_updated_at',
        'run_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkflowRunStatus::class,
            'conclusion' => WorkflowRunConclusion::class,
            'run_number' => 'integer',
            'run_started_at' => 'datetime',
            'run_updated_at' => 'datetime',
            'run_completed_at' => 'datetime',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
