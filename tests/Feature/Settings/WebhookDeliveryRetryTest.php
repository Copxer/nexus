<?php

namespace Tests\Feature\Settings;

use App\Domain\GitHub\Jobs\ProcessGitHubWebhookJob;
use App\Enums\WebhookDeliveryStatus;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WebhookDeliveryRetryTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_retry_flips_failed_delivery_back_to_received_and_dispatches_job(): void
    {
        Bus::fake();
        $user = $this->verifiedUser();
        $delivery = WebhookDelivery::factory()->create([
            'status' => WebhookDeliveryStatus::Failed->value,
            'error_message' => 'Boom',
            'processed_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($user)
            ->post(route('settings.webhook-deliveries.retry', $delivery->id))
            ->assertRedirect()
            ->assertSessionHas('status', 'Webhook re-queued.');

        $fresh = $delivery->fresh();
        $this->assertSame(WebhookDeliveryStatus::Received, $fresh->status);
        $this->assertNull($fresh->error_message);
        $this->assertNull($fresh->processed_at);

        Bus::assertDispatched(
            ProcessGitHubWebhookJob::class,
            fn (ProcessGitHubWebhookJob $job): bool => $job->deliveryId === $delivery->id,
        );
    }

    public function test_retry_refuses_non_failed_delivery(): void
    {
        Bus::fake();
        $user = $this->verifiedUser();
        $delivery = WebhookDelivery::factory()->create([
            'status' => WebhookDeliveryStatus::Processed->value,
        ]);

        $this->actingAs($user)
            ->post(route('settings.webhook-deliveries.retry', $delivery->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(
            WebhookDeliveryStatus::Processed,
            $delivery->fresh()->status,
            'non-failed delivery must not be mutated',
        );

        Bus::assertNotDispatched(ProcessGitHubWebhookJob::class);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $delivery = WebhookDelivery::factory()->create([
            'status' => WebhookDeliveryStatus::Failed->value,
        ]);

        $this->post(route('settings.webhook-deliveries.retry', $delivery->id))
            ->assertRedirect(route('login'));
    }
}
