<?php

namespace Tests\Feature\DailyBriefings;

use App\Domain\AI\Contracts\LlmClient;
use App\Domain\AI\DataTransferObjects\LlmPrompt;
use App\Domain\AI\DataTransferObjects\LlmResponse;
use App\Domain\DailyBriefings\Actions\GenerateDailyBriefingAction;
use App\Enums\DailyBriefingStatus;
use App\Models\DailyBriefing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class GenerateDailyBriefingActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_versioned_prompt_calls_llm_and_persists_generated_output(): void
    {
        config(['services.llm.enabled' => true]);

        $user = User::factory()->create();
        $client = new FakeLlmClient(new LlmResponse(json_encode([
            'summary' => "Yesterday was mostly stable.\n\nTwo items need operator review.",
            'highlights' => [
                'Billing API had one merged pull request.',
                'Checkout had one successful deployment.',
                'No critical alerts were triggered.',
            ],
            'risks' => ['Checkout API has current health score 42.'],
            'next_steps' => ['Review Checkout API health.'],
        ], JSON_THROW_ON_ERROR)));

        $this->app->instance(LlmClient::class, $client);

        $briefing = app(GenerateDailyBriefingAction::class)->execute($user, $this->snapshot());

        $this->assertSame(DailyBriefingStatus::Generated, $briefing->status);
        $this->assertSame(GenerateDailyBriefingAction::PROMPT_VERSION, $briefing->prompt_version);
        $this->assertSame($this->snapshot(), $briefing->input_snapshot);
        $this->assertSame('Yesterday was mostly stable. Two items need operator review.', $briefing->summary);
        $this->assertCount(3, $briefing->highlights);
        $this->assertSame(['Checkout API has current health score 42.'], $briefing->risks);
        $this->assertNotNull($briefing->generated_at);
        $this->assertNull($briefing->error_message);

        $this->assertNotNull($client->prompt);
        $this->assertSame(GenerateDailyBriefingAction::PROMPT_VERSION, $client->prompt->version);
        $this->assertStringContainsString('Return only valid JSON', $client->prompt->system);
        $this->assertStringContainsString('"prompt_version":"daily-briefing-v1"', $client->prompt->user);
        $this->assertStringContainsString('"Checkout API"', $client->prompt->user);
    }

    public function test_sanitizes_structured_output_before_persisting(): void
    {
        config(['services.llm.enabled' => true]);

        $user = User::factory()->create();
        $this->app->instance(LlmClient::class, new FakeLlmClient(new LlmResponse(json_encode([
            'summary' => '<strong>Stable</strong> day with one follow-up.',
            'highlights' => [
                '<b>Deployment succeeded</b>',
                "Alert resolved\ncleanly",
                'Health score held steady',
            ],
            'risks' => ['<script>bad()</script>Checkout API remains low.'],
        ], JSON_THROW_ON_ERROR))));

        $briefing = app(GenerateDailyBriefingAction::class)->execute($user, $this->snapshot());

        $this->assertSame('Stable day with one follow-up.', $briefing->summary);
        $this->assertSame(['Deployment succeeded', 'Alert resolved cleanly', 'Health score held steady'], $briefing->highlights);
        $this->assertSame(['bad()Checkout API remains low.'], $briefing->risks);
    }

    public function test_persists_failed_status_when_llm_client_errors(): void
    {
        config(['services.llm.enabled' => true]);

        $user = User::factory()->create();
        $this->app->instance(LlmClient::class, new FakeLlmClient(exception: new RuntimeException('Provider timed out')));

        $briefing = app(GenerateDailyBriefingAction::class)->execute($user, $this->snapshot());

        $this->assertSame(DailyBriefingStatus::Failed, $briefing->status);
        $this->assertSame($this->snapshot(), $briefing->input_snapshot);
        $this->assertNull($briefing->summary);
        $this->assertNull($briefing->generated_at);
        $this->assertSame('Provider timed out', $briefing->error_message);
    }

    public function test_fails_closed_without_calling_llm_when_ai_features_are_disabled(): void
    {
        config(['services.llm.enabled' => false]);

        $user = User::factory()->create();
        $client = new FakeLlmClient(new LlmResponse('{}'));
        $this->app->instance(LlmClient::class, $client);

        $briefing = app(GenerateDailyBriefingAction::class)->execute($user, $this->snapshot());

        $this->assertSame(DailyBriefingStatus::Failed, $briefing->status);
        $this->assertSame('AI features are disabled.', $briefing->error_message);
        $this->assertNull($client->prompt);
    }

    public function test_invalid_llm_output_is_stored_as_failed_briefing(): void
    {
        config(['services.llm.enabled' => true]);

        $user = User::factory()->create();
        $this->app->instance(LlmClient::class, new FakeLlmClient(new LlmResponse(json_encode([
            'summary' => 'Missing enough highlights.',
            'highlights' => ['Only one'],
            'risks' => [],
        ], JSON_THROW_ON_ERROR))));

        $briefing = app(GenerateDailyBriefingAction::class)->execute($user, $this->snapshot());

        $this->assertSame(DailyBriefingStatus::Failed, $briefing->status);
        $this->assertSame('LLM response must include 3 to 6 highlights.', $briefing->error_message);
    }

    public function test_reuses_existing_daily_briefing_row_for_same_user_and_date(): void
    {
        config(['services.llm.enabled' => true]);

        $user = User::factory()->create();
        $existing = DailyBriefing::factory()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
            'status' => DailyBriefingStatus::Failed->value,
            'error_message' => 'Previous failure',
        ]);
        $this->app->instance(LlmClient::class, new FakeLlmClient(new LlmResponse(json_encode([
            'summary' => 'A clean retry generated the daily briefing.',
            'highlights' => ['One issue opened', 'One PR merged', 'No new alerts'],
            'risks' => [],
        ], JSON_THROW_ON_ERROR))));

        $briefing = app(GenerateDailyBriefingAction::class)->execute($user, $this->snapshot());

        $this->assertSame($existing->id, $briefing->id);
        $this->assertSame(DailyBriefingStatus::Generated, $briefing->status);
        $this->assertDatabaseCount('daily_briefings', 1);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'window' => [
                'briefing_date' => '2026-07-20',
                'timezone' => 'UTC',
                'starts_at_utc' => '2026-07-20T00:00:00+00:00',
                'ends_at_utc' => '2026-07-21T00:00:00+00:00',
            ],
            'projects' => [
                'total' => 1,
                'sample' => [['id' => 1, 'name' => 'Checkout API', 'health_score' => 42]],
            ],
            'github' => [
                'issues' => ['opened' => 1, 'closed' => 0],
                'pull_requests' => ['opened' => 0, 'merged' => 1, 'closed' => 1],
                'work_items' => [],
            ],
            'deployments' => ['successful' => 1, 'failed' => 0, 'failed_workflows' => []],
            'alerts' => ['triggered' => 0, 'resolved' => 0, 'groups' => [], 'sample' => []],
            'monitoring' => ['website_checks' => ['total' => 0], 'hosts' => [], 'containers' => []],
            'health' => ['deltas' => [], 'worst_projects' => [['id' => 1, 'name' => 'Checkout API', 'health_score' => 42]]],
            'activity' => ['total' => 0, 'by_project' => [], 'top_events' => []],
        ];
    }
}

class FakeLlmClient implements LlmClient
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
