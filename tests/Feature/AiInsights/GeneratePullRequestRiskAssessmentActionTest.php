<?php

namespace Tests\Feature\AiInsights;

use App\Domain\AI\Contracts\LlmClient;
use App\Domain\AI\DataTransferObjects\LlmPrompt;
use App\Domain\AI\DataTransferObjects\LlmResponse;
use App\Domain\AiInsights\Actions\GeneratePullRequestRiskAssessmentAction;
use App\Enums\PullRequestRiskAssessmentStatus;
use App\Enums\PullRequestRiskLevel;
use App\Models\GithubPullRequest;
use App\Models\PullRequestRiskAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class GeneratePullRequestRiskAssessmentActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_versioned_prompt_calls_llm_and_persists_scored_output(): void
    {
        config(['services.llm.enabled' => true]);

        $pullRequest = GithubPullRequest::factory()->create();
        $client = new RiskAssessmentFakeLlmClient(new LlmResponse(json_encode([
            'risk_level' => 'high',
            'risk_score' => 82,
            'summary' => 'Large change with failing workflow needs careful review.',
            'reasons' => ['Changed files are high.', 'Recent workflow failed.'],
            'recommended_actions' => ['Review failed workflow before merge.'],
        ], JSON_THROW_ON_ERROR), 'claude-3-5-haiku-latest'));
        $this->app->instance(LlmClient::class, $client);

        $assessment = app(GeneratePullRequestRiskAssessmentAction::class)->execute($pullRequest, $this->snapshot());

        $this->assertSame(PullRequestRiskAssessmentStatus::Scored, $assessment->status);
        $this->assertSame(PullRequestRiskLevel::High, $assessment->risk_level);
        $this->assertSame(82, $assessment->risk_score);
        $this->assertSame('Large change with failing workflow needs careful review.', $assessment->summary);
        $this->assertSame(['Changed files are high.', 'Recent workflow failed.'], $assessment->reasons);
        $this->assertSame(['Review failed workflow before merge.'], $assessment->recommended_actions);
        $this->assertSame($this->snapshot(), $assessment->input_snapshot);
        $this->assertSame(GeneratePullRequestRiskAssessmentAction::PROMPT_VERSION, $assessment->prompt_version);
        $this->assertSame('claude-3-5-haiku-latest', $assessment->model);
        $this->assertNotNull($assessment->assessed_at);
        $this->assertNull($assessment->failed_at);
        $this->assertNull($assessment->error_message);

        $this->assertNotNull($client->prompt);
        $this->assertSame(GeneratePullRequestRiskAssessmentAction::PROMPT_VERSION, $client->prompt->version);
        $this->assertStringContainsString('Return only valid JSON', $client->prompt->system);
        $this->assertStringContainsString('"prompt_version":"pr-risk-v1"', $client->prompt->user);
        $this->assertStringContainsString('"Checkout refactor"', $client->prompt->user);
    }

    public function test_sanitizes_structured_output_before_persisting(): void
    {
        config(['services.llm.enabled' => true]);

        $pullRequest = GithubPullRequest::factory()->create();
        $this->app->instance(LlmClient::class, new RiskAssessmentFakeLlmClient(new LlmResponse(json_encode([
            'risk_level' => 'medium',
            'risk_score' => 54,
            'summary' => '<strong>Moderate</strong> risk from size.',
            'reasons' => ["<b>Large diff</b>\nneeds review"],
            'recommended_actions' => ['<script>bad()</script>Review workflow status.'],
        ], JSON_THROW_ON_ERROR))));

        $assessment = app(GeneratePullRequestRiskAssessmentAction::class)->execute($pullRequest, $this->snapshot());

        $this->assertSame('Moderate risk from size.', $assessment->summary);
        $this->assertSame(['Large diff needs review'], $assessment->reasons);
        $this->assertSame(['bad()Review workflow status.'], $assessment->recommended_actions);
    }

    public function test_persists_failed_status_when_llm_client_errors(): void
    {
        config(['services.llm.enabled' => true]);

        $pullRequest = GithubPullRequest::factory()->create();
        $this->app->instance(LlmClient::class, new RiskAssessmentFakeLlmClient(exception: new RuntimeException('Provider timed out')));

        $assessment = app(GeneratePullRequestRiskAssessmentAction::class)->execute($pullRequest, $this->snapshot());

        $this->assertSame(PullRequestRiskAssessmentStatus::Failed, $assessment->status);
        $this->assertNull($assessment->risk_level);
        $this->assertNull($assessment->risk_score);
        $this->assertNull($assessment->summary);
        $this->assertSame([], $assessment->reasons);
        $this->assertSame($this->snapshot(), $assessment->input_snapshot);
        $this->assertNull($assessment->assessed_at);
        $this->assertNotNull($assessment->failed_at);
        $this->assertSame('Provider timed out', $assessment->error_message);
    }

    public function test_fails_closed_without_calling_llm_when_ai_features_are_disabled(): void
    {
        config(['services.llm.enabled' => false]);

        $pullRequest = GithubPullRequest::factory()->create();
        $client = new RiskAssessmentFakeLlmClient(new LlmResponse('{}'));
        $this->app->instance(LlmClient::class, $client);

        $assessment = app(GeneratePullRequestRiskAssessmentAction::class)->execute($pullRequest, $this->snapshot());

        $this->assertSame(PullRequestRiskAssessmentStatus::Failed, $assessment->status);
        $this->assertSame('AI features are disabled.', $assessment->error_message);
        $this->assertNull($client->prompt);
    }

    public function test_invalid_llm_output_is_stored_as_failed_assessment(): void
    {
        config(['services.llm.enabled' => true]);

        $pullRequest = GithubPullRequest::factory()->create();
        $this->app->instance(LlmClient::class, new RiskAssessmentFakeLlmClient(new LlmResponse(json_encode([
            'risk_level' => 'urgent',
            'risk_score' => 101,
            'summary' => 'Invalid risk.',
            'reasons' => ['Unsupported risk level.'],
        ], JSON_THROW_ON_ERROR))));

        $assessment = app(GeneratePullRequestRiskAssessmentAction::class)->execute($pullRequest, $this->snapshot());

        $this->assertSame(PullRequestRiskAssessmentStatus::Failed, $assessment->status);
        $this->assertSame('LLM response risk_level is invalid.', $assessment->error_message);
    }

    public function test_reuses_existing_current_row_when_regenerating(): void
    {
        config(['services.llm.enabled' => true]);

        $pullRequest = GithubPullRequest::factory()->create();
        $existing = PullRequestRiskAssessment::factory()->for($pullRequest, 'pullRequest')->create([
            'status' => PullRequestRiskAssessmentStatus::Failed->value,
            'error_message' => 'Previous failure',
        ]);
        $this->app->instance(LlmClient::class, new RiskAssessmentFakeLlmClient(new LlmResponse(json_encode([
            'risk_level' => 'low',
            'risk_score' => 12,
            'summary' => 'Small PR with no active risk signals.',
            'reasons' => ['Small changed file count.'],
            'recommended_actions' => [],
        ], JSON_THROW_ON_ERROR))));

        $assessment = app(GeneratePullRequestRiskAssessmentAction::class)->execute($pullRequest, $this->snapshot());

        $this->assertSame($existing->id, $assessment->id);
        $this->assertSame(PullRequestRiskAssessmentStatus::Scored, $assessment->status);
        $this->assertSame(PullRequestRiskLevel::Low, $assessment->risk_level);
        $this->assertDatabaseCount('pull_request_risk_assessments', 1);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'snapshot_version' => 'pr-risk-input-v1',
            'project' => ['id' => 1, 'name' => 'Checkout API', 'health_score' => 42],
            'repository' => ['id' => 1, 'full_name' => 'nexus/checkout-api'],
            'pull_request' => ['id' => 1, 'number' => 10, 'title' => 'Checkout refactor', 'changed_files' => 12],
            'recent_failed_workflows' => [['id' => 1, 'conclusion' => 'failure']],
            'active_alerts' => [],
        ];
    }
}

class RiskAssessmentFakeLlmClient implements LlmClient
{
    public ?LlmPrompt $prompt = null;

    public function __construct(
        private readonly ?LlmResponse $response = null,
        private readonly ?RuntimeException $exception = null,
    ) {}

    public function complete(LlmPrompt $prompt): LlmResponse
    {
        $this->prompt = $prompt;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->response ?? new LlmResponse('{}');
    }
}
