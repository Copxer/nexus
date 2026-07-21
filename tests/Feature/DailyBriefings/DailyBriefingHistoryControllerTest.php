<?php

namespace Tests\Feature\DailyBriefings;

use App\Enums\DailyBriefingStatus;
use App\Models\AlertNotificationChannel;
use App\Models\DailyBriefing;
use App\Models\DailyBriefingPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DailyBriefingHistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_lists_only_the_authenticated_users_generated_briefings(): void
    {
        $user = $this->verifiedUser();
        $otherUser = User::factory()->create();
        $channel = AlertNotificationChannel::factory()->email('ops@example.com')->for($user)->create();
        DailyBriefingPreference::factory()->enabled()->create([
            'user_id' => $user->id,
            'channel_id' => $channel->id,
        ]);

        $delivered = DailyBriefing::factory()->generated()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
            'status' => DailyBriefingStatus::Delivered->value,
            'summary' => 'A delivered briefing owned by the authenticated user.',
            'delivered_at' => now(),
        ]);
        $failedDelivery = DailyBriefing::factory()->generated()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-19',
            'status' => DailyBriefingStatus::Failed->value,
            'summary' => 'Generated content remains visible after delivery failure.',
            'error_message' => 'Slack timed out',
        ]);
        DailyBriefing::factory()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-18',
            'status' => DailyBriefingStatus::Pending->value,
            'summary' => null,
        ]);
        DailyBriefing::factory()->generated()->create([
            'user_id' => $otherUser->id,
            'briefing_date' => '2026-07-20',
            'summary' => 'Other user briefing must not leak.',
        ]);

        $this->actingAs($user)
            ->get(route('daily-briefings.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('DailyBriefings/Index')
                    ->has('briefings', 2)
                    ->where('briefings.0.id', $delivered->id)
                    ->where('briefings.0.status', 'delivered')
                    ->where('briefings.0.channel.name', 'Ops email')
                    ->where('briefings.1.id', $failedDelivery->id)
                    ->where('briefings.1.status', 'failed')
            );
    }

    public function test_user_can_view_owned_generated_briefing_detail(): void
    {
        $user = $this->verifiedUser();
        $briefing = DailyBriefing::factory()->generated()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
            'summary' => "Paragraph one.\n\nParagraph two.",
            'highlights' => ['Merged billing fix'],
            'risks' => ['API latency is elevated'],
        ]);

        $this->actingAs($user)
            ->get(route('daily-briefings.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('briefings.0.id', $briefing->id)
                    ->where('briefings.0.summary', "Paragraph one.\n\nParagraph two.")
                    ->where('briefings.0.highlights.0', 'Merged billing fix')
                    ->where('briefings.0.risks.0', 'API latency is elevated')
            );
    }

    public function test_guest_cannot_access_daily_briefing_history(): void
    {
        $this->get(route('daily-briefings.index'))->assertRedirect(route('login'));
    }
}
