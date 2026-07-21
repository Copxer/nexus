<?php

namespace Tests\Feature\DailyBriefings;

use App\Domain\DailyBriefings\Jobs\SendDailyBriefingJob;
use App\Enums\DailyBriefingStatus;
use App\Mail\DailyBriefingMail;
use App\Models\AlertNotificationChannel;
use App\Models\DailyBriefing;
use App\Models\DailyBriefingPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class SendDailyBriefingJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_generated_briefing_through_verified_selected_channel(): void
    {
        Http::fake([
            'hooks.slack.com/*' => Http::response('ok', 200),
        ]);
        Mail::fake();

        $user = User::factory()->create();
        AlertNotificationChannel::factory()->email('ops@example.com')->for($user)->create();
        $slack = AlertNotificationChannel::factory()
            ->slack('https://hooks.slack.com/services/T00/B00/XXX')
            ->for($user)
            ->create();
        DailyBriefingPreference::factory()->enabled()->create([
            'user_id' => $user->id,
            'channel_id' => $slack->id,
        ]);
        $briefing = DailyBriefing::factory()->generated()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
        ]);

        (new SendDailyBriefingJob($briefing->id))->handle();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'hooks.slack.com')
            && $request['text'] === 'Daily briefing for 2026-07-20'
            && $request['blocks'][4]['elements'][0]['url'] === route('daily-briefings.index'));
        Mail::assertNothingSent();
        $this->assertDatabaseHas('daily_briefings', [
            'id' => $briefing->id,
            'status' => DailyBriefingStatus::Delivered->value,
            'error_message' => null,
        ]);
        $this->assertNotNull($briefing->refresh()->delivered_at);
        $this->assertSame('2026-07-20', $briefing->user->dailyBriefingPreference->refresh()->last_sent_for_date->toDateString());
    }

    public function test_falls_back_to_first_verified_email_channel_when_no_channel_is_selected(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        AlertNotificationChannel::factory()->email('unverified@example.com')->unverified()->for($user)->create();
        AlertNotificationChannel::factory()->email('ops@example.com')->for($user)->create();
        DailyBriefingPreference::factory()->enabled()->create([
            'user_id' => $user->id,
            'channel_id' => null,
        ]);
        $briefing = DailyBriefing::factory()->generated()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
        ]);

        (new SendDailyBriefingJob($briefing->id))->handle();

        Mail::assertSent(DailyBriefingMail::class, fn (DailyBriefingMail $mail): bool => $mail->payload->briefingId === $briefing->id);
        $this->assertSame(DailyBriefingStatus::Delivered, $briefing->refresh()->status);
        $this->assertSame('2026-07-20', $briefing->user->dailyBriefingPreference->refresh()->last_sent_for_date->toDateString());
    }

    public function test_marks_failed_and_does_not_update_last_sent_when_no_verified_channel_exists(): void
    {
        $user = User::factory()->create();
        DailyBriefingPreference::factory()->enabled()->create(['user_id' => $user->id]);
        $briefing = DailyBriefing::factory()->generated()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
        ]);

        (new SendDailyBriefingJob($briefing->id))->handle();

        $this->assertSame(DailyBriefingStatus::Failed, $briefing->refresh()->status);
        $this->assertSame('No verified daily briefing delivery channel is available.', $briefing->error_message);
        $this->assertNull($briefing->user->dailyBriefingPreference->refresh()->last_sent_for_date);
    }

    public function test_driver_failure_marks_failed_without_updating_last_sent(): void
    {
        Http::fake([
            'ops.example.com/*' => Http::response('down', 503),
        ]);

        $user = User::factory()->create();
        $channel = AlertNotificationChannel::factory()
            ->webhook('https://ops.example.com/hook')
            ->for($user)
            ->create();
        DailyBriefingPreference::factory()->enabled()->create([
            'user_id' => $user->id,
            'channel_id' => $channel->id,
        ]);
        $briefing = DailyBriefing::factory()->generated()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
        ]);

        try {
            (new SendDailyBriefingJob($briefing->id))->handle();
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('503', $exception->getMessage());
        }

        $this->assertSame(DailyBriefingStatus::Failed, $briefing->refresh()->status);
        $this->assertStringContainsString('503', $briefing->error_message);
        $this->assertNull($briefing->user->dailyBriefingPreference->refresh()->last_sent_for_date);
    }

    public function test_failed_generated_briefing_can_be_retried_successfully(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        AlertNotificationChannel::factory()->email('ops@example.com')->for($user)->create();
        DailyBriefingPreference::factory()->enabled()->create(['user_id' => $user->id]);
        $briefing = DailyBriefing::factory()->generated()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
            'status' => DailyBriefingStatus::Failed->value,
            'error_message' => 'Previous delivery failure',
        ]);

        (new SendDailyBriefingJob($briefing->id))->handle();

        Mail::assertSent(DailyBriefingMail::class);
        $this->assertSame(DailyBriefingStatus::Delivered, $briefing->refresh()->status);
        $this->assertNull($briefing->error_message);
        $this->assertSame('2026-07-20', $briefing->user->dailyBriefingPreference->refresh()->last_sent_for_date->toDateString());
    }

    public function test_test_delivery_does_not_update_last_sent_date(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        AlertNotificationChannel::factory()->email('ops@example.com')->for($user)->create();
        DailyBriefingPreference::factory()->enabled()->create(['user_id' => $user->id]);
        $briefing = DailyBriefing::factory()->generated()->create([
            'user_id' => $user->id,
            'briefing_date' => '2026-07-20',
            'is_test' => true,
        ]);

        (new SendDailyBriefingJob($briefing->id, updateLastSentForDate: false))->handle();

        Mail::assertSent(DailyBriefingMail::class);
        $this->assertSame(DailyBriefingStatus::Delivered, $briefing->refresh()->status);
        $this->assertNull($briefing->user->dailyBriefingPreference->refresh()->last_sent_for_date);
    }
}
