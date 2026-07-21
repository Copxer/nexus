<?php

namespace Database\Factories;

use App\Enums\PullRequestRiskAssessmentStatus;
use App\Enums\PullRequestRiskLevel;
use App\Models\GithubPullRequest;
use App\Models\PullRequestRiskAssessment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PullRequestRiskAssessment> */
class PullRequestRiskAssessmentFactory extends Factory
{
    protected $model = PullRequestRiskAssessment::class;

    public function definition(): array
    {
        return [
            'github_pull_request_id' => GithubPullRequest::factory(),
            'status' => PullRequestRiskAssessmentStatus::Pending->value,
            'risk_level' => null,
            'risk_score' => null,
            'summary' => null,
            'reasons' => [],
            'recommended_actions' => [],
            'input_snapshot' => null,
            'prompt_version' => 'pr-risk-v1',
            'model' => null,
            'assessed_at' => null,
            'failed_at' => null,
            'error_message' => null,
        ];
    }

    public function scored(): static
    {
        return $this->state(fn () => [
            'status' => PullRequestRiskAssessmentStatus::Scored->value,
            'risk_level' => PullRequestRiskLevel::High->value,
            'risk_score' => 82,
            'summary' => 'Large PR with failing checks needs careful review.',
            'reasons' => ['Large change size', 'Recent workflow failure'],
            'recommended_actions' => ['Review failing workflow before merge'],
            'input_snapshot' => ['pull_request' => ['number' => 42, 'changed_files' => 18]],
            'model' => 'claude-3-5-haiku-latest',
            'assessed_at' => now(),
        ]);
    }
}
