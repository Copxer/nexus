<?php

namespace Tests\Feature\DailyBriefings;

use App\Domain\AI\Contracts\LlmClient;
use App\Domain\AI\DataTransferObjects\LlmPrompt;
use App\Domain\AI\DataTransferObjects\LlmResponse;
use App\Domain\DailyBriefings\Actions\GenerateDailyBriefingAction;
use App\Domain\DailyBriefings\Jobs\GenerateDailyBriefingJob;
use App\Domain\DailyBriefings\Jobs\SendDailyBriefingJob;
use App\Domain\DailyBriefings\Queries\GetDailyBriefingInputQuery;
use App\Enums\DailyBriefingStatus;
use App\Models\DailyBriefing;
use App\Models\DailyBriefingPreference;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerateDailyBriefingJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_unique_by_user_and_briefing_date(): void
    {
        $job = new GenerateDailyBriefingJob(123, '2026-07-20');

        $this->assertSame('123:2026-07-20', $job->uniqueId());
    }

    public function test_builds_input_snapshot_and_generates_briefing_for_enabled_user(): void
    {
        Queue::fake();
        config(['services.llm.enabled' => true]);
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'owner_user_id' => $user->id,
            'name' => 'Checkout API',
            'health_score' => 42,
        ]);
        DailyBriefingPreference::factory()->enabled()->create([
            'user_id' => $user->id,
            'timezone' => 'America/New_York',
            'include_projects' => [$project->id],
        ]);
        $client = new GenerateJobFakeLlmClient;
        $this->app->instance(LlmClient::class, $client);

        (new GenerateDailyBriefingJob($user->id, '2026-07-20'))->handle(
            app(GetDailyBriefingInputQuery::class),
            app(GenerateDailyBriefingAction::class),
        );

        $briefing = DailyBriefing::query()->sole();
        $this->assertSame(DailyBriefingStatus::Generated, $briefing->status);
        $this->assertSame('2026-07-20', $briefing->briefing_date->toDateString());
        $this->assertSame('America/New_York', $briefing->input_snapshot['window']['timezone']);
        $this->assertSame($project->id, $briefing->input_snapshot['projects']['sample'][0]['id']);
        $this->assertNotNull($client->prompt);
        Queue::assertPushed(SendDailyBriefingJob::class, fn (SendDailyBriefingJob $job): bool => $job->briefingId === $briefing->id);
    }

    public function test_existing_generated_briefing_is_sent_without_llm_regeneration(): void
    {
        Queue::fake();
        config(['services.llm.enabled' => true]);
        $user = User::factory()->create();
        DailyBriefingPreference::factory()->enabled()->create(['user_id' => $user->id]);
        $briefing = DailyBriefing::factory()->generated()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
        ]);
        $client = new GenerateJobFakeLlmClient;
        $this->app->instance(LlmClient::class, $client);

        (new GenerateDailyBriefingJob($user->id, '2026-07-20'))->handle(
            app(GetDailyBriefingInputQuery::class),
            app(GenerateDailyBriefingAction::class),
        );

        $this->assertDatabaseCount('daily_briefings', 1);
        $this->assertNull($client->prompt);
        Queue::assertPushed(SendDailyBriefingJob::class, fn (SendDailyBriefingJob $job): bool => $job->briefingId === $briefing->id);
    }

    public function test_failed_delivery_with_generated_content_is_resent_without_llm_regeneration(): void
    {
        Queue::fake();
        config(['services.llm.enabled' => true]);
        $user = User::factory()->create();
        DailyBriefingPreference::factory()->enabled()->create(['user_id' => $user->id]);
        $briefing = DailyBriefing::factory()->generated()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
            'status' => DailyBriefingStatus::Failed->value,
            'error_message' => 'Slack timed out',
        ]);
        $client = new GenerateJobFakeLlmClient;
        $this->app->instance(LlmClient::class, $client);

        (new GenerateDailyBriefingJob($user->id, '2026-07-20'))->handle(
            app(GetDailyBriefingInputQuery::class),
            app(GenerateDailyBriefingAction::class),
        );

        $this->assertNull($client->prompt);
        $this->assertSame(DailyBriefingStatus::Failed, $briefing->refresh()->status);
        Queue::assertPushed(SendDailyBriefingJob::class, fn (SendDailyBriefingJob $job): bool => $job->briefingId === $briefing->id);
    }

    public function test_pending_briefing_does_not_permanently_suppress_generation(): void
    {
        Queue::fake();
        config(['services.llm.enabled' => true]);
        $user = User::factory()->create();
        DailyBriefingPreference::factory()->enabled()->create(['user_id' => $user->id]);
        $pending = DailyBriefing::factory()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
            'status' => DailyBriefingStatus::Pending->value,
        ]);
        $client = new GenerateJobFakeLlmClient;
        $this->app->instance(LlmClient::class, $client);

        (new GenerateDailyBriefingJob($user->id, '2026-07-20'))->handle(
            app(GetDailyBriefingInputQuery::class),
            app(GenerateDailyBriefingAction::class),
        );

        $this->assertSame($pending->id, DailyBriefing::query()->sole()->id);
        $this->assertSame(DailyBriefingStatus::Generated, $pending->refresh()->status);
        $this->assertNotNull($client->prompt);
        Queue::assertPushed(SendDailyBriefingJob::class, fn (SendDailyBriefingJob $job): bool => $job->briefingId === $pending->id);
    }

    public function test_failed_handler_marks_pending_briefing_failed_after_retries_are_exhausted(): void
    {
        $user = User::factory()->create();
        $briefing = DailyBriefing::factory()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
            'status' => DailyBriefingStatus::Pending->value,
        ]);
        $job = new GenerateDailyBriefingJob($user->id, '2026-07-20');

        $this->assertSame(2, $job->tries);

        $job->failed(new \RuntimeException('Snapshot failed'));

        $this->assertSame(DailyBriefingStatus::Failed, $briefing->refresh()->status);
        $this->assertSame('Snapshot failed', $briefing->error_message);
    }

    public function test_does_not_generate_when_user_is_not_opted_in_or_ai_is_disabled(): void
    {
        Queue::fake();
        config(['services.llm.enabled' => false]);
        $user = User::factory()->create();
        DailyBriefingPreference::factory()->enabled()->create(['user_id' => $user->id]);

        (new GenerateDailyBriefingJob($user->id, '2026-07-20'))->handle(
            app(GetDailyBriefingInputQuery::class),
            app(GenerateDailyBriefingAction::class),
        );

        $this->assertDatabaseCount('daily_briefings', 0);
        Queue::assertNotPushed(SendDailyBriefingJob::class);
    }

    public function test_does_not_generate_when_user_is_not_opted_in(): void
    {
        Queue::fake();
        config(['services.llm.enabled' => true]);
        $user = User::factory()->create();

        (new GenerateDailyBriefingJob($user->id, '2026-07-20'))->handle(
            app(GetDailyBriefingInputQuery::class),
            app(GenerateDailyBriefingAction::class),
        );

        $this->assertDatabaseCount('daily_briefings', 0);
        Queue::assertNotPushed(SendDailyBriefingJob::class);
    }
}

class GenerateJobFakeLlmClient implements LlmClient
{
    public ?LlmPrompt $prompt = null;

    public function complete(LlmPrompt $prompt): LlmResponse
    {
        $this->prompt = $prompt;

        return new LlmResponse(json_encode([
            'summary' => 'Yesterday was stable with a few concrete items to review.',
            'highlights' => [
                'Checkout API was included in the briefing input.',
                'Project health was available for prioritization.',
                'No delivery channel was invoked in this generation slice.',
            ],
            'risks' => [],
        ], JSON_THROW_ON_ERROR));
    }
}
