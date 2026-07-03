<?php

namespace Tests\Feature\Notifications;

use App\Models\AlertNotificationChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 042 — the "Send test" button synthesizes a test payload +
 * pushes through the driver. On 2xx, `verified_at` gets stamped.
 */
class SendChannelTestNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_test_flips_verified_at_on_success(): void
    {
        Http::fake([
            'hooks.slack.com/*' => Http::response('ok', 200),
        ]);

        $user = User::factory()->create();
        $channel = AlertNotificationChannel::factory()
            ->slack('https://hooks.slack.com/services/T00/B00/XXX')
            ->unverified()
            ->for($user)
            ->create();

        $this->assertNull($channel->verified_at);

        $this->actingAs($user)
            ->post(route('settings.notifications.channels.test', ['channel' => $channel->id]))
            ->assertRedirect();

        $this->assertNotNull($channel->fresh()->verified_at);
    }

    public function test_send_test_leaves_verified_at_null_on_failure(): void
    {
        Http::fake([
            'hooks.slack.com/*' => Http::response('bad token', 401),
        ]);

        $user = User::factory()->create();
        $channel = AlertNotificationChannel::factory()
            ->slack('https://hooks.slack.com/services/T00/B00/XXX')
            ->unverified()
            ->for($user)
            ->create();

        $this->actingAs($user)
            ->post(route('settings.notifications.channels.test', ['channel' => $channel->id]))
            ->assertRedirect();

        $this->assertNull($channel->fresh()->verified_at);
    }

    public function test_send_test_rejects_other_users_channel(): void
    {
        $ownerUser = User::factory()->create();
        $strangerUser = User::factory()->create();
        $channel = AlertNotificationChannel::factory()->email()->for($ownerUser)->create();

        $this->actingAs($strangerUser)
            ->post(route('settings.notifications.channels.test', ['channel' => $channel->id]))
            ->assertForbidden();
    }
}
