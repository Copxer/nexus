<?php

namespace Tests\Feature\AiInsights;

use App\Domain\AI\Contracts\LlmClient;
use App\Domain\AI\DataTransferObjects\LlmPrompt;
use App\Domain\AI\DataTransferObjects\LlmResponse;
use App\Domain\AiInsights\Actions\GenerateProjectHealthExplanationAction;
use App\Domain\AiInsights\Jobs\GenerateProjectHealthExplanationJob;
use App\Domain\AiInsights\Queries\GetProjectHealthExplanationInputQuery;
use App\Enums\ProjectHealthExplanationStatus;
use App\Models\Project;
use App\Models\ProjectHealthExplanation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerateProjectHealthExplanationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_unique_by_project(): void
    {
        $job = new GenerateProjectHealthExplanationJob(123);

        $this->assertSame('123', $job->uniqueId());
        $this->assertSame(2, $job->tries);
    }

    public function test_builds_input_snapshot_and_generates_health_explanation(): void
    {
        config(['services.llm.enabled' => true]);
        $project = $this->project();
        $client = new HealthJobFakeLlmClient;
        $this->app->instance(LlmClient::class, $client);

        (new GenerateProjectHealthExplanationJob($project->id))->handle(
            app(GetProjectHealthExplanationInputQuery::class),
            app(GenerateProjectHealthExplanationAction::class),
        );

        $explanation = ProjectHealthExplanation::query()->sole();
        $this->assertSame(ProjectHealthExplanationStatus::Explained, $explanation->status);
        $this->assertSame($project->id, $explanation->input_snapshot['project']['id']);
        $this->assertNotNull($client->prompt);
    }

    public function test_does_not_generate_when_ai_is_disabled(): void
    {
        config(['services.llm.enabled' => false]);
        $project = $this->project();
        $client = new HealthJobFakeLlmClient;
        $this->app->instance(LlmClient::class, $client);

        (new GenerateProjectHealthExplanationJob($project->id))->handle(
            app(GetProjectHealthExplanationInputQuery::class),
            app(GenerateProjectHealthExplanationAction::class),
        );

        $this->assertDatabaseCount('project_health_explanations', 0);
        $this->assertNull($client->prompt);
    }

    public function test_failed_handler_marks_pending_explanation_failed_after_retries_are_exhausted(): void
    {
        $project = $this->project();
        $explanation = ProjectHealthExplanation::factory()->for($project)->create([
            'status' => ProjectHealthExplanationStatus::Pending->value,
        ]);

        (new GenerateProjectHealthExplanationJob($project->id))->failed(new \RuntimeException('Snapshot failed'));

        $this->assertSame(ProjectHealthExplanationStatus::Failed, $explanation->refresh()->status);
        $this->assertSame('Snapshot failed', $explanation->error_message);
        $this->assertNotNull($explanation->failed_at);
    }

    public function test_null_scoped_snapshot_marks_pending_explanation_skipped(): void
    {
        config(['services.llm.enabled' => true]);
        Queue::fake();
        $project = $this->project();
        $client = new HealthJobFakeLlmClient;
        $this->app->instance(LlmClient::class, $client);

        (new GenerateProjectHealthExplanationJob($project->id))->handle(
            new NullProjectHealthExplanationInputQuery,
            app(GenerateProjectHealthExplanationAction::class),
        );

        $explanation = ProjectHealthExplanation::query()->sole();
        $this->assertSame(ProjectHealthExplanationStatus::Skipped, $explanation->status);
        $this->assertStringContainsString('scoped AI input query', $explanation->error_message);
        $this->assertNull($client->prompt);
    }

    private function project(): Project
    {
        $user = User::factory()->create();

        return Project::factory()->create([
            'owner_user_id' => $user->id,
            'health_score' => 42,
        ]);
    }
}

class NullProjectHealthExplanationInputQuery extends GetProjectHealthExplanationInputQuery
{
    public function execute(User $user, Project $project): ?array
    {
        return null;
    }
}

class HealthJobFakeLlmClient implements LlmClient
{
    public ?LlmPrompt $prompt = null;

    public function complete(LlmPrompt $prompt): LlmResponse
    {
        $this->prompt = $prompt;

        return new LlmResponse(json_encode([
            'summary' => 'Health is reduced by active alerts and failed checks.',
            'drivers' => ['Critical alerts are active', 'Website checks are failing'],
            'recommended_actions' => ['Investigate the critical alerts first'],
        ], JSON_THROW_ON_ERROR));
    }
}
