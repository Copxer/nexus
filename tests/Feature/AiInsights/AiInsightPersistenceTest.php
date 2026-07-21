<?php

namespace Tests\Feature\AiInsights;

use App\Enums\HealthScoreBand;
use App\Enums\ProjectHealthExplanationStatus;
use App\Enums\PullRequestRiskAssessmentStatus;
use App\Enums\PullRequestRiskLevel;
use App\Models\GithubPullRequest;
use App\Models\Project;
use App\Models\ProjectHealthExplanation;
use App\Models\PullRequestRiskAssessment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiInsightPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pull_request_risk_assessment_casts_payloads_and_relationships(): void
    {
        $pullRequest = GithubPullRequest::factory()->create();

        $assessment = PullRequestRiskAssessment::factory()
            ->scored()
            ->for($pullRequest, 'pullRequest')
            ->create(['assessed_at' => '2026-07-21 15:30:00']);

        $this->assertSame(PullRequestRiskAssessmentStatus::Scored, $assessment->status);
        $this->assertSame(PullRequestRiskLevel::High, $assessment->risk_level);
        $this->assertSame(82, $assessment->risk_score);
        $this->assertSame(['Large change size', 'Recent workflow failure'], $assessment->reasons);
        $this->assertSame(['Review failing workflow before merge'], $assessment->recommended_actions);
        $this->assertSame(['pull_request' => ['number' => 42, 'changed_files' => 18]], $assessment->input_snapshot);
        $this->assertSame('2026-07-21 15:30:00', $assessment->assessed_at->format('Y-m-d H:i:s'));
        $this->assertTrue($assessment->pullRequest->is($pullRequest));
        $this->assertTrue($pullRequest->riskAssessment->is($assessment));
    }

    public function test_pull_request_has_one_current_risk_assessment_and_cascades_delete(): void
    {
        $pullRequest = GithubPullRequest::factory()->create();

        PullRequestRiskAssessment::factory()->for($pullRequest, 'pullRequest')->create();

        $this->expectException(UniqueConstraintViolationException::class);

        PullRequestRiskAssessment::factory()->for($pullRequest, 'pullRequest')->create();
    }

    public function test_pull_request_risk_assessment_is_deleted_with_pull_request(): void
    {
        $pullRequest = GithubPullRequest::factory()->create();
        $assessment = PullRequestRiskAssessment::factory()->for($pullRequest, 'pullRequest')->create();

        $pullRequest->delete();

        $this->assertDatabaseMissing('pull_request_risk_assessments', ['id' => $assessment->id]);
    }

    public function test_project_health_explanation_casts_payloads_and_relationships(): void
    {
        $project = Project::factory()->create(['health_score' => 42]);

        $explanation = ProjectHealthExplanation::factory()
            ->explained()
            ->for($project)
            ->create(['explained_at' => '2026-07-21 15:35:00']);

        $this->assertSame(ProjectHealthExplanationStatus::Explained, $explanation->status);
        $this->assertSame(42, $explanation->health_score);
        $this->assertSame(HealthScoreBand::Warning, $explanation->health_band);
        $this->assertSame(['2 critical alerts', '1 failing website check'], $explanation->drivers);
        $this->assertSame(['Investigate the critical alerts first'], $explanation->recommended_actions);
        $this->assertSame(['project' => ['health_score' => 42]], $explanation->input_snapshot);
        $this->assertSame('2026-07-21 15:35:00', $explanation->explained_at->format('Y-m-d H:i:s'));
        $this->assertTrue($explanation->project->is($project));
        $this->assertTrue($project->healthExplanation->is($explanation));
    }

    public function test_project_has_one_current_health_explanation_and_cascades_delete(): void
    {
        $project = Project::factory()->create();

        ProjectHealthExplanation::factory()->for($project)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        ProjectHealthExplanation::factory()->for($project)->create();
    }

    public function test_project_health_explanation_is_deleted_with_project(): void
    {
        $project = Project::factory()->create();
        $explanation = ProjectHealthExplanation::factory()->for($project)->create();

        $project->delete();

        $this->assertDatabaseMissing('project_health_explanations', ['id' => $explanation->id]);
    }
}
