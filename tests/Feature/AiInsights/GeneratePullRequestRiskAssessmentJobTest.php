<?php

namespace Tests\Feature\AiInsights;

use App\Domain\AI\Contracts\LlmClient;
use App\Domain\AI\DataTransferObjects\LlmPrompt;
use App\Domain\AI\DataTransferObjects\LlmResponse;
use App\Domain\AiInsights\Actions\GeneratePullRequestRiskAssessmentAction;
use App\Domain\AiInsights\Jobs\GeneratePullRequestRiskAssessmentJob;
use App\Domain\AiInsights\Queries\GetPullRequestRiskInputQuery;
use App\Enums\PullRequestRiskAssessmentStatus;
use App\Enums\PullRequestRiskLevel;
use App\Models\GithubPullRequest;
use App\Models\Project;
use App\Models\PullRequestRiskAssessment;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GeneratePullRequestRiskAssessmentJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_unique_by_pull_request(): void
    {
        $job = new GeneratePullRequestRiskAssessmentJob(123);

        $this->assertSame('123', $job->uniqueId());
        $this->assertSame(1, $job->tries);
    }

    public function test_builds_input_snapshot_and_generates_risk_assessment(): void
    {
        config(['services.llm.enabled' => true]);
        $pullRequest = $this->pullRequest();
        $client = new RiskJobFakeLlmClient;
        $this->app->instance(LlmClient::class, $client);

        (new GeneratePullRequestRiskAssessmentJob($pullRequest->id))->handle(
            app(GetPullRequestRiskInputQuery::class),
            app(GeneratePullRequestRiskAssessmentAction::class),
        );

        $assessment = PullRequestRiskAssessment::query()->sole();
        $this->assertSame(PullRequestRiskAssessmentStatus::Scored, $assessment->status);
        $this->assertSame(PullRequestRiskLevel::High, $assessment->risk_level);
        $this->assertSame($pullRequest->id, $assessment->input_snapshot['pull_request']['id']);
        $this->assertNotNull($client->prompt);
    }

    public function test_does_not_generate_when_ai_is_disabled(): void
    {
        config(['services.llm.enabled' => false]);
        $pullRequest = $this->pullRequest();
        $client = new RiskJobFakeLlmClient;
        $this->app->instance(LlmClient::class, $client);

        (new GeneratePullRequestRiskAssessmentJob($pullRequest->id))->handle(
            app(GetPullRequestRiskInputQuery::class),
            app(GeneratePullRequestRiskAssessmentAction::class),
        );

        $this->assertDatabaseCount('pull_request_risk_assessments', 0);
        $this->assertNull($client->prompt);
    }

    public function test_marks_existing_pending_assessment_skipped_when_ai_is_disabled(): void
    {
        config(['services.llm.enabled' => false]);
        $pullRequest = $this->pullRequest();
        $assessment = PullRequestRiskAssessment::factory()->for($pullRequest, 'pullRequest')->create([
            'status' => PullRequestRiskAssessmentStatus::Pending->value,
        ]);
        $client = new RiskJobFakeLlmClient;
        $this->app->instance(LlmClient::class, $client);

        (new GeneratePullRequestRiskAssessmentJob($pullRequest->id))->handle(
            app(GetPullRequestRiskInputQuery::class),
            app(GeneratePullRequestRiskAssessmentAction::class),
        );

        $this->assertSame(PullRequestRiskAssessmentStatus::Skipped, $assessment->refresh()->status);
        $this->assertSame('AI features are disabled.', $assessment->error_message);
        $this->assertNull($client->prompt);
    }

    public function test_failed_handler_marks_pending_assessment_failed_after_retries_are_exhausted(): void
    {
        $pullRequest = $this->pullRequest();
        $assessment = PullRequestRiskAssessment::factory()->for($pullRequest, 'pullRequest')->create([
            'status' => PullRequestRiskAssessmentStatus::Pending->value,
        ]);

        (new GeneratePullRequestRiskAssessmentJob($pullRequest->id))->failed(new \RuntimeException('Snapshot failed'));

        $this->assertSame(PullRequestRiskAssessmentStatus::Failed, $assessment->refresh()->status);
        $this->assertSame('Snapshot failed', $assessment->error_message);
        $this->assertNotNull($assessment->failed_at);
    }

    public function test_null_scoped_snapshot_marks_pending_assessment_skipped(): void
    {
        config(['services.llm.enabled' => true]);
        Queue::fake();
        $pullRequest = $this->pullRequest();
        $client = new RiskJobFakeLlmClient;
        $this->app->instance(LlmClient::class, $client);

        (new GeneratePullRequestRiskAssessmentJob($pullRequest->id))->handle(
            new NullPullRequestRiskInputQuery,
            app(GeneratePullRequestRiskAssessmentAction::class),
        );

        $assessment = PullRequestRiskAssessment::query()->sole();
        $this->assertSame(PullRequestRiskAssessmentStatus::Skipped, $assessment->status);
        $this->assertStringContainsString('scoped AI input query', $assessment->error_message);
        $this->assertNull($client->prompt);
    }

    private function pullRequest(): GithubPullRequest
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repository = Repository::factory()->create(['project_id' => $project->id]);

        return GithubPullRequest::factory()->create(['repository_id' => $repository->id]);
    }
}

class NullPullRequestRiskInputQuery extends GetPullRequestRiskInputQuery
{
    public function execute(User $user, GithubPullRequest $pullRequest): ?array
    {
        return null;
    }
}

class RiskJobFakeLlmClient implements LlmClient
{
    public ?LlmPrompt $prompt = null;

    public function complete(LlmPrompt $prompt): LlmResponse
    {
        $this->prompt = $prompt;

        return new LlmResponse(json_encode([
            'risk_level' => 'high',
            'risk_score' => 82,
            'summary' => 'Large PR with failed checks needs careful review.',
            'reasons' => ['Large change size', 'Recent workflow failure'],
            'recommended_actions' => ['Review failing workflow before merge'],
        ], JSON_THROW_ON_ERROR));
    }
}
