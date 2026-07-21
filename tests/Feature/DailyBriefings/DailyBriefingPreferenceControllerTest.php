<?php

namespace Tests\Feature\DailyBriefings;

use App\Domain\AI\Contracts\LlmClient;
use App\Domain\AI\DataTransferObjects\LlmPrompt;
use App\Domain\AI\DataTransferObjects\LlmResponse;
use App\Enums\DailyBriefingStatus;
use App\Mail\DailyBriefingMail;
use App\Models\AlertNotificationChannel;
use App\Models\DailyBriefing;
use App\Models\DailyBriefingPreference;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DailyBriefingPreferenceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_creates_default_preference_and_renders_options(): void
    {
        $user = $this->verifiedUser();
        $channel = AlertNotificationChannel::factory()->email('ops@example.com')->for($user)->create();
        AlertNotificationChannel::factory()->unverified()->for($user)->create();
        $project = Project::factory()->for($user, 'owner')->create(['name' => 'Billing API']);
        DailyBriefing::factory()->generated()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
            'status' => DailyBriefingStatus::Failed->value,
            'error_message' => 'Provider timed out',
        ]);

        $this->actingAs($user)
            ->get(route('settings.daily-briefing.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Settings/DailyBriefing')
                    ->where('preference.enabled', false)
                    ->where('preference.delivery_time', '08:00')
                    ->where('channels.0.id', $channel->id)
                    ->where('projects.0.id', $project->id)
                    ->where('status.status', 'failed')
                    ->where('status.error_message', 'Provider timed out')
                    ->has('timezones')
            );

        $this->assertDatabaseHas('daily_briefing_preferences', [
            'user_id' => $user->id,
            'enabled' => false,
        ]);
    }

    public function test_user_can_update_daily_briefing_preferences(): void
    {
        $user = $this->verifiedUser();
        $channel = AlertNotificationChannel::factory()->slack()->for($user)->create();
        $project = Project::factory()->for($user, 'owner')->create();

        $this->actingAs($user)
            ->patch(route('settings.daily-briefing.update'), [
                'enabled' => true,
                'delivery_time' => '07:30',
                'timezone' => 'America/New_York',
                'channel_id' => $channel->id,
                'include_projects' => [$project->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Daily briefing preferences saved.');

        $this->assertDatabaseHas('daily_briefing_preferences', [
            'user_id' => $user->id,
            'enabled' => true,
            'delivery_time' => '07:30:00',
            'timezone' => 'America/New_York',
            'channel_id' => $channel->id,
        ]);

        $this->assertSame([$project->id], DailyBriefingPreference::query()->where('user_id', $user->id)->firstOrFail()->include_projects);
    }

    public function test_update_rejects_channels_not_owned_verified_and_enabled(): void
    {
        $user = $this->verifiedUser();
        $otherUser = User::factory()->create();
        $otherChannel = AlertNotificationChannel::factory()->for($otherUser)->create();
        $unverified = AlertNotificationChannel::factory()->unverified()->for($user)->create();
        $disabled = AlertNotificationChannel::factory()->disabled()->for($user)->create();

        foreach ([$otherChannel, $unverified, $disabled] as $channel) {
            $this->actingAs($user)
                ->from(route('settings.daily-briefing.index'))
                ->patch(route('settings.daily-briefing.update'), [
                    'enabled' => true,
                    'delivery_time' => '08:00',
                    'timezone' => 'UTC',
                    'channel_id' => $channel->id,
                    'include_projects' => [],
                ])
                ->assertRedirect(route('settings.daily-briefing.index'))
                ->assertSessionHasErrors('channel_id');
        }

        $this->assertDatabaseMissing('daily_briefing_preferences', [
            'user_id' => $user->id,
            'channel_id' => $otherChannel->id,
        ]);
    }

    public function test_update_rejects_project_filters_not_owned_by_user(): void
    {
        $user = $this->verifiedUser();
        $otherUser = User::factory()->create();
        $otherProject = Project::factory()->for($otherUser, 'owner')->create();

        $this->actingAs($user)
            ->from(route('settings.daily-briefing.index'))
            ->patch(route('settings.daily-briefing.update'), [
                'enabled' => true,
                'delivery_time' => '08:00',
                'timezone' => 'UTC',
                'channel_id' => null,
                'include_projects' => [$otherProject->id],
            ])
            ->assertRedirect(route('settings.daily-briefing.index'))
            ->assertSessionHasErrors('include_projects');
    }

    public function test_test_send_generates_and_delivers_immediately(): void
    {
        config(['services.llm.enabled' => true]);
        Mail::fake();

        $user = $this->verifiedUser();
        $channel = AlertNotificationChannel::factory()->email('ops@example.com')->for($user)->create();
        DailyBriefingPreference::factory()->enabled()->create([
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'timezone' => 'UTC',
        ]);
        $this->app->instance(LlmClient::class, new PreferenceFakeLlmClient);

        $this->actingAs($user)
            ->post(route('settings.daily-briefing.test'))
            ->assertRedirect()
            ->assertSessionHas('status', 'Test daily briefing sent.');

        Mail::assertSent(DailyBriefingMail::class);
        $this->assertDatabaseHas('daily_briefings', [
            'user_id' => $user->id,
            'status' => DailyBriefingStatus::Delivered->value,
            'error_message' => null,
        ]);
        $this->assertNotNull(DailyBriefingPreference::query()->where('user_id', $user->id)->firstOrFail()->last_sent_for_date);
    }

    public function test_test_send_is_throttled(): void
    {
        $user = $this->verifiedUser();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)
                ->post(route('settings.daily-briefing.test'))
                ->assertRedirect();
        }

        $this->actingAs($user)
            ->post(route('settings.daily-briefing.test'))
            ->assertStatus(429);
    }

    public function test_guest_cannot_access_daily_briefing_settings(): void
    {
        $this->get(route('settings.daily-briefing.index'))->assertRedirect(route('login'));
        $this->patch(route('settings.daily-briefing.update'))->assertRedirect(route('login'));
        $this->post(route('settings.daily-briefing.test'))->assertRedirect(route('login'));
    }
}

class PreferenceFakeLlmClient implements LlmClient
{
    public function complete(LlmPrompt $prompt): LlmResponse
    {
        return new LlmResponse(json_encode([
            'summary' => 'Yesterday was stable enough for a test briefing.',
            'highlights' => [
                'No critical alerts were found.',
                'Project activity was summarized.',
                'Delivery settings were verified.',
            ],
            'risks' => [],
        ], JSON_THROW_ON_ERROR));
    }
}
