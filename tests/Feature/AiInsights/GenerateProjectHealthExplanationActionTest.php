<?php

namespace Tests\Feature\AiInsights;

use App\Domain\AI\Contracts\LlmClient;
use App\Domain\AI\DataTransferObjects\LlmPrompt;
use App\Domain\AI\DataTransferObjects\LlmResponse;
use App\Domain\AiInsights\Actions\GenerateProjectHealthExplanationAction;
use App\Enums\HealthScoreBand;
use App\Enums\ProjectHealthExplanationStatus;
use App\Models\Project;
use App\Models\ProjectHealthExplanation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class GenerateProjectHealthExplanationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_versioned_prompt_calls_llm_and_persists_explained_output(): void
    {
        config(['services.llm.enabled' => true]);

        $project = Project::factory()->create(['health_score' => 28]);
        $client = new HealthExplanationFakeLlmClient(new LlmResponse(json_encode([
            'summary' => 'Health is critical because active alerts and failed checks are concentrated on production.',
            'drivers' => ['Seven critical alerts are active.', 'Default branch workflow failures increased.'],
            'recommended_actions' => ['Investigate active critical alerts first.'],
        ], JSON_THROW_ON_ERROR), 'claude-3-5-haiku-latest'));
        $this->app->instance(LlmClient::class, $client);

        $explanation = app(GenerateProjectHealthExplanationAction::class)->execute($project, $this->snapshot());

        $this->assertSame(ProjectHealthExplanationStatus::Explained, $explanation->status);
        $this->assertSame(28, $explanation->health_score);
        $this->assertSame(HealthScoreBand::Critical, $explanation->health_band);
        $this->assertSame('Health is critical because active alerts and failed checks are concentrated on production.', $explanation->summary);
        $this->assertSame(['Seven critical alerts are active.', 'Default branch workflow failures increased.'], $explanation->drivers);
        $this->assertSame(['Investigate active critical alerts first.'], $explanation->recommended_actions);
        $this->assertSame($this->snapshot(), $explanation->input_snapshot);
        $this->assertSame(GenerateProjectHealthExplanationAction::PROMPT_VERSION, $explanation->prompt_version);
        $this->assertSame('claude-3-5-haiku-latest', $explanation->model);
        $this->assertNotNull($explanation->explained_at);
        $this->assertNull($explanation->failed_at);
        $this->assertNull($explanation->error_message);

        $this->assertNotNull($client->prompt);
        $this->assertSame(GenerateProjectHealthExplanationAction::PROMPT_VERSION, $client->prompt->version);
        $this->assertStringContainsString('Return only valid JSON', $client->prompt->system);
        $this->assertStringContainsString('Do not invent a different score', $client->prompt->system);
        $this->assertStringContainsString('"prompt_version":"project-health-explanation-v1"', $client->prompt->user);
        $this->assertStringContainsString('"Checkout Platform"', $client->prompt->user);
    }

    public function test_sanitizes_structured_output_before_persisting(): void
    {
        config(['services.llm.enabled' => true]);

        $project = Project::factory()->create(['health_score' => 28]);
        $this->app->instance(LlmClient::class, new HealthExplanationFakeLlmClient(new LlmResponse(json_encode([
            'summary' => '<strong>Critical</strong> health needs attention.',
            'drivers' => ["<b>Alerts</b>\nare active"],
            'recommended_actions' => ['<script>bad()</script>Check production site.'],
        ], JSON_THROW_ON_ERROR))));

        $explanation = app(GenerateProjectHealthExplanationAction::class)->execute($project, $this->snapshot());

        $this->assertSame('Critical health needs attention.', $explanation->summary);
        $this->assertSame(['Alerts are active'], $explanation->drivers);
        $this->assertSame(['bad()Check production site.'], $explanation->recommended_actions);
    }

    public function test_persists_failed_status_when_llm_client_errors(): void
    {
        config(['services.llm.enabled' => true]);

        $project = Project::factory()->create(['health_score' => 28]);
        $this->app->instance(LlmClient::class, new HealthExplanationFakeLlmClient(exception: new RuntimeException('Provider timed out')));

        $explanation = app(GenerateProjectHealthExplanationAction::class)->execute($project, $this->snapshot());

        $this->assertSame(ProjectHealthExplanationStatus::Failed, $explanation->status);
        $this->assertSame(28, $explanation->health_score);
        $this->assertSame(HealthScoreBand::Critical, $explanation->health_band);
        $this->assertNull($explanation->summary);
        $this->assertSame([], $explanation->drivers);
        $this->assertSame($this->snapshot(), $explanation->input_snapshot);
        $this->assertNull($explanation->explained_at);
        $this->assertNotNull($explanation->failed_at);
        $this->assertSame('Provider timed out', $explanation->error_message);
    }

    public function test_fails_closed_without_calling_llm_when_ai_features_are_disabled(): void
    {
        config(['services.llm.enabled' => false]);

        $project = Project::factory()->create(['health_score' => 28]);
        $client = new HealthExplanationFakeLlmClient(new LlmResponse('{}'));
        $this->app->instance(LlmClient::class, $client);

        $explanation = app(GenerateProjectHealthExplanationAction::class)->execute($project, $this->snapshot());

        $this->assertSame(ProjectHealthExplanationStatus::Failed, $explanation->status);
        $this->assertSame('AI features are disabled.', $explanation->error_message);
        $this->assertNull($client->prompt);
    }

    public function test_invalid_llm_output_is_stored_as_failed_explanation(): void
    {
        config(['services.llm.enabled' => true]);

        $project = Project::factory()->create(['health_score' => 28]);
        $this->app->instance(LlmClient::class, new HealthExplanationFakeLlmClient(new LlmResponse(json_encode([
            'summary' => 'Missing drivers.',
            'drivers' => [],
        ], JSON_THROW_ON_ERROR))));

        $explanation = app(GenerateProjectHealthExplanationAction::class)->execute($project, $this->snapshot());

        $this->assertSame(ProjectHealthExplanationStatus::Failed, $explanation->status);
        $this->assertSame('LLM response must include 1 to 6 drivers.', $explanation->error_message);
    }

    public function test_reuses_existing_current_row_when_regenerating(): void
    {
        config(['services.llm.enabled' => true]);

        $project = Project::factory()->create(['health_score' => 28]);
        $existing = ProjectHealthExplanation::factory()->for($project)->create([
            'status' => ProjectHealthExplanationStatus::Failed->value,
            'error_message' => 'Previous failure',
        ]);
        $this->app->instance(LlmClient::class, new HealthExplanationFakeLlmClient(new LlmResponse(json_encode([
            'summary' => 'Health improved but active alerts remain.',
            'drivers' => ['Current health score is 28.'],
            'recommended_actions' => [],
        ], JSON_THROW_ON_ERROR))));

        $explanation = app(GenerateProjectHealthExplanationAction::class)->execute($project, $this->snapshot());

        $this->assertSame($existing->id, $explanation->id);
        $this->assertSame(ProjectHealthExplanationStatus::Explained, $explanation->status);
        $this->assertDatabaseCount('project_health_explanations', 1);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'snapshot_version' => 'project-health-explanation-input-v1',
            'project' => [
                'id' => 1,
                'name' => 'Checkout Platform',
                'health_score' => 28,
                'health_band' => 'critical',
            ],
            'drivers' => [
                'alerts' => ['active_total' => 7],
                'deployments' => ['failed_default_branch_last_24h' => 2],
            ],
            'health_delta' => null,
        ];
    }
}

class HealthExplanationFakeLlmClient implements LlmClient
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
