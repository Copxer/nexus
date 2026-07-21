<?php

namespace App\Models;

use App\Enums\PullRequestRiskAssessmentStatus;
use App\Enums\PullRequestRiskLevel;
use Database\Factories\PullRequestRiskAssessmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PullRequestRiskAssessment extends Model
{
    /** @use HasFactory<PullRequestRiskAssessmentFactory> */
    use HasFactory;

    protected $fillable = [
        'github_pull_request_id',
        'status',
        'risk_level',
        'risk_score',
        'summary',
        'reasons',
        'recommended_actions',
        'input_snapshot',
        'prompt_version',
        'model',
        'assessed_at',
        'failed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => PullRequestRiskAssessmentStatus::class,
            'risk_level' => PullRequestRiskLevel::class,
            'risk_score' => 'integer',
            'reasons' => 'array',
            'recommended_actions' => 'array',
            'input_snapshot' => 'array',
            'assessed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(GithubPullRequest::class, 'github_pull_request_id');
    }
}
