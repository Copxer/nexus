<?php

namespace Tests\Feature\DailyBriefings;

use App\Enums\DailyBriefingStatus;
use App\Models\AlertNotificationChannel;
use App\Models\DailyBriefing;
use App\Models\DailyBriefingPreference;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyBriefingPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_llm_config_defaults_to_disabled_anthropic_provider(): void
    {
        $this->assertFalse(config('services.llm.enabled'));
        $this->assertSame('anthropic', config('services.llm.provider'));
        $this->assertSame('claude-3-5-haiku-latest', config('services.llm.model'));
        $this->assertSame(20, config('services.llm.timeout'));
    }

    public function test_daily_briefing_preference_defaults_and_relationships(): void
    {
        $user = User::factory()->create();
        $channel = AlertNotificationChannel::factory()->email()->for($user)->create();
        $project = Project::factory()->for($user, 'owner')->create();

        $preference = DailyBriefingPreference::factory()
            ->for($user)
            ->for($channel, 'channel')
            ->create([
                'enabled' => true,
                'timezone' => 'America/New_York',
                'include_projects' => [$project->id],
                'last_sent_for_date' => '2026-07-20',
            ]);

        $this->assertTrue($preference->enabled);
        $this->assertSame('08:00:00', $preference->delivery_time);
        $this->assertSame('America/New_York', $preference->timezone);
        $this->assertSame([$project->id], $preference->include_projects);
        $this->assertSame('2026-07-20', $preference->last_sent_for_date->toDateString());
        $this->assertTrue($preference->user->is($user));
        $this->assertTrue($preference->channel->is($channel));
        $this->assertTrue($user->dailyBriefingPreference->is($preference));
        $this->assertTrue($channel->dailyBriefingPreferences->first()->is($preference));
    }

    public function test_daily_briefing_casts_status_dates_and_json_payloads(): void
    {
        $user = User::factory()->create();

        $briefing = DailyBriefing::factory()
            ->generated()
            ->for($user)
            ->create([
                'briefing_date' => '2026-07-20',
                'delivered_at' => '2026-07-21 08:15:00',
            ]);

        $this->assertSame(DailyBriefingStatus::Generated, $briefing->status);
        $this->assertSame('2026-07-20', $briefing->briefing_date->toDateString());
        $this->assertSame(['counts' => ['alerts' => 2]], $briefing->input_snapshot);
        $this->assertSame(['Two alerts triggered', 'One deployment succeeded'], $briefing->highlights);
        $this->assertSame(['Billing API health score dropped'], $briefing->risks);
        $this->assertSame('2026-07-21 08:15:00', $briefing->delivered_at->format('Y-m-d H:i:s'));
        $this->assertTrue($briefing->user->is($user));
        $this->assertTrue($user->dailyBriefings->first()->is($briefing));
    }

    public function test_user_can_have_one_preference_and_one_briefing_per_date(): void
    {
        $user = User::factory()->create();

        DailyBriefingPreference::factory()->for($user)->create();
        DailyBriefing::factory()->for($user)->create(['briefing_date' => '2026-07-20']);

        $this->expectException(UniqueConstraintViolationException::class);

        DailyBriefingPreference::factory()->for($user)->create();
    }

    public function test_daily_briefing_is_unique_per_user_and_briefing_date(): void
    {
        $user = User::factory()->create();

        DailyBriefing::factory()->for($user)->create(['briefing_date' => '2026-07-20']);

        $this->expectException(UniqueConstraintViolationException::class);

        DailyBriefing::factory()->for($user)->create(['briefing_date' => '2026-07-20']);
    }
}
