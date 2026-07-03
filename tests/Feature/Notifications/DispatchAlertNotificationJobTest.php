<?php

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Jobs\DispatchAlertNotificationJob;
use App\Enums\AlertDeliveryStatus;
use App\Mail\AlertNotificationMail;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AlertNotificationChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class DispatchAlertNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_driver_sends_and_marks_delivery_sent(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $channel = AlertNotificationChannel::factory()->email('ops@example.com')->for($user)->create();
        $alert = Alert::factory()->create();

        (new DispatchAlertNotificationJob($alert->id, $channel->id))->handle();

        Mail::assertSent(AlertNotificationMail::class);
        $this->assertDatabaseHas('alert_deliveries', [
            'alert_id' => $alert->id,
            'channel_id' => $channel->id,
            'status' => AlertDeliveryStatus::Sent->value,
        ]);
    }

    public function test_slack_driver_posts_to_webhook_url_and_marks_delivery_sent(): void
    {
        Http::fake([
            'hooks.slack.com/*' => Http::response('ok', 200),
        ]);

        $user = User::factory()->create();
        $channel = AlertNotificationChannel::factory()
            ->slack('https://hooks.slack.com/services/T00/B00/XXX')
            ->for($user)
            ->create();
        $alert = Alert::factory()->create(['severity' => 'critical']);

        (new DispatchAlertNotificationJob($alert->id, $channel->id))->handle();

        Http::assertSent(fn ($req) => str_contains($req->url(), 'hooks.slack.com')
            && is_array($req['blocks']));
        $this->assertDatabaseHas('alert_deliveries', [
            'alert_id' => $alert->id,
            'channel_id' => $channel->id,
            'status' => AlertDeliveryStatus::Sent->value,
        ]);
    }

    public function test_generic_webhook_driver_posts_json_body(): void
    {
        Http::fake([
            'ops.example.com/*' => Http::response('', 202),
        ]);

        $user = User::factory()->create();
        $channel = AlertNotificationChannel::factory()
            ->webhook('https://ops.example.com/hook')
            ->for($user)
            ->create();
        $alert = Alert::factory()->create();

        (new DispatchAlertNotificationJob($alert->id, $channel->id))->handle();

        Http::assertSent(fn ($req) => str_contains($req->url(), 'ops.example.com')
            && $req->hasHeader('Content-Type', 'application/json')
            && ! $req->hasHeader('X-Nexus-Signature'));

        $this->assertDatabaseHas('alert_deliveries', [
            'alert_id' => $alert->id,
            'channel_id' => $channel->id,
            'status' => AlertDeliveryStatus::Sent->value,
        ]);
    }

    public function test_5xx_response_raises_exception_and_lands_delivery_pending_with_error(): void
    {
        Http::fake([
            'ops.example.com/*' => Http::response('down for maintenance', 503),
        ]);

        $user = User::factory()->create();
        $channel = AlertNotificationChannel::factory()
            ->webhook('https://ops.example.com/hook')
            ->for($user)
            ->create();
        $alert = Alert::factory()->create();

        try {
            (new DispatchAlertNotificationJob($alert->id, $channel->id))->handle();
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }

        $delivery = AlertDelivery::query()
            ->where('alert_id', $alert->id)
            ->where('channel_id', $channel->id)
            ->firstOrFail();

        $this->assertSame(AlertDeliveryStatus::Pending, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringContainsString('503', $delivery->error_message);
    }

    public function test_failed_hook_lands_delivery_status_failed(): void
    {
        $user = User::factory()->create();
        $channel = AlertNotificationChannel::factory()->email()->for($user)->create();
        $alert = Alert::factory()->create();

        AlertDelivery::factory()
            ->for($alert)
            ->for($channel, 'channel')
            ->create(['status' => AlertDeliveryStatus::Pending->value, 'attempts' => 3]);

        (new DispatchAlertNotificationJob($alert->id, $channel->id))
            ->failed(new RuntimeException('SMTP timeout after 3 attempts'));

        $this->assertDatabaseHas('alert_deliveries', [
            'alert_id' => $alert->id,
            'channel_id' => $channel->id,
            'status' => AlertDeliveryStatus::Failed->value,
            'error_message' => 'SMTP timeout after 3 attempts',
        ]);
    }
}
