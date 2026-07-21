<?php

namespace Tests\Feature\DailyBriefings;

use App\Domain\DailyBriefings\Jobs\DispatchDueDailyBriefingsJob;
use App\Domain\DailyBriefings\Jobs\GenerateDailyBriefingJob;
use App\Enums\DailyBriefingStatus;
use App\Models\DailyBriefing;
use App\Models\DailyBriefingPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchDueDailyBriefingsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dispatches_generation_for_enabled_preference_after_local_delivery_time(): void
    {
        config(['services.llm.enabled' => true]);
        Carbon::setTestNow('2026-07-21 12:30:00');
        Queue::fake();
        $user = User::factory()->create();
        DailyBriefingPreference::factory()->enabled()->create([
            'user_id' => $user->id,
            'delivery_time' => '08:00:00',
            'timezone' => 'America/New_York',
        ]);

        (new DispatchDueDailyBriefingsJob)->handle();

        Queue::assertPushed(
            GenerateDailyBriefingJob::class,
            fn (GenerateDailyBriefingJob $job): bool => $job->userId === $user->id
                && $job->briefingDate === '2026-07-20',
        );
    }

    public function test_respects_timezone_when_delivery_time_has_not_passed(): void
    {
        config(['services.llm.enabled' => true]);
        Carbon::setTestNow('2026-07-21 12:30:00');
        Queue::fake();
        DailyBriefingPreference::factory()->enabled()->create([
            'delivery_time' => '08:00:00',
            'timezone' => 'America/Los_Angeles',
        ]);

        (new DispatchDueDailyBriefingsJob)->handle();

        Queue::assertNotPushed(GenerateDailyBriefingJob::class);
    }

    public function test_skips_disabled_preferences_and_disabled_ai_feature_gate(): void
    {
        config(['services.llm.enabled' => false]);
        Queue::fake();
        DailyBriefingPreference::factory()->enabled()->create();
        DailyBriefingPreference::factory()->create(['enabled' => false]);

        (new DispatchDueDailyBriefingsJob)->handle();

        Queue::assertNothingPushed();
    }

    public function test_skips_when_briefing_date_was_already_sent(): void
    {
        config(['services.llm.enabled' => true]);
        Carbon::setTestNow('2026-07-21 13:00:00');
        Queue::fake();
        DailyBriefingPreference::factory()->enabled()->create([
            'delivery_time' => '08:00:00',
            'timezone' => 'UTC',
            'last_sent_for_date' => '2026-07-20',
        ]);

        (new DispatchDueDailyBriefingsJob)->handle();

        Queue::assertNotPushed(GenerateDailyBriefingJob::class);
    }

    public function test_dispatches_when_existing_briefing_still_needs_generation_or_delivery(): void
    {
        config(['services.llm.enabled' => true]);
        Carbon::setTestNow('2026-07-21 13:00:00');
        Queue::fake();
        $user = User::factory()->create();
        DailyBriefingPreference::factory()->enabled()->create([
            'user_id' => $user->id,
            'delivery_time' => '08:00:00',
            'timezone' => 'UTC',
        ]);
        DailyBriefing::factory()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
            'status' => DailyBriefingStatus::Pending->value,
        ]);

        (new DispatchDueDailyBriefingsJob)->handle();

        Queue::assertPushed(GenerateDailyBriefingJob::class);
    }

    public function test_skips_existing_delivered_briefing_rows(): void
    {
        config(['services.llm.enabled' => true]);
        Carbon::setTestNow('2026-07-21 13:00:00');
        Queue::fake();
        $user = User::factory()->create();
        DailyBriefingPreference::factory()->enabled()->create([
            'user_id' => $user->id,
            'delivery_time' => '08:00:00',
            'timezone' => 'UTC',
        ]);
        DailyBriefing::factory()->generated()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
            'status' => DailyBriefingStatus::Delivered->value,
        ]);

        (new DispatchDueDailyBriefingsJob)->handle();

        Queue::assertNotPushed(GenerateDailyBriefingJob::class);
    }
}
