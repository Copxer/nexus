<?php

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Jobs\DispatchAlertNotificationJob;
use App\Enums\AlertDeliveryStatus;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AlertNotificationChannel;
use App\Models\AlertNotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Spec 042 — per-user × per-channel rate limit. Default 30/hour; the
 * 31st send in the same hour writes a `skipped` delivery with
 * `error_message = rate_limited`.
 */
class AlertNotificationRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('notif:*');
        Http::fake(['*' => Http::response('ok', 200)]);
    }

    public function test_send_at_the_ceiling_lands_skipped_with_rate_limited(): void
    {
        $user = User::factory()->create();
        $channel = AlertNotificationChannel::factory()
            ->webhook('https://ops.example.com/hook')
            ->for($user)
            ->create();

        // Custom low ceiling makes the test cheap.
        AlertNotificationPreference::factory()
            ->for($user)
            ->for($channel, 'channel')
            ->create(['rate_limit_per_hour' => 3]);

        // 3 sends succeed.
        for ($i = 0; $i < 3; $i++) {
            $alert = Alert::factory()->create();
            (new DispatchAlertNotificationJob($alert->id, $channel->id))->handle();
        }

        // 4th send: rate-limited.
        $blockedAlert = Alert::factory()->create();
        (new DispatchAlertNotificationJob($blockedAlert->id, $channel->id))->handle();

        $delivery = AlertDelivery::query()
            ->where('alert_id', $blockedAlert->id)
            ->where('channel_id', $channel->id)
            ->firstOrFail();

        $this->assertSame(AlertDeliveryStatus::Skipped, $delivery->status);
        $this->assertSame('rate_limited', $delivery->error_message);
    }
}
