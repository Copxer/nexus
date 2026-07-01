<?php

namespace Tests\Feature\Settings;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WebhookDeliveriesIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_for_a_verified_user(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->get(route('settings.webhook-deliveries.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Settings/WebhookDeliveries')
                    ->has('deliveries.data')
                    ->where('filters.status', 'all'),
            );
    }

    public function test_index_lists_deliveries_in_received_at_desc_order(): void
    {
        $user = $this->verifiedUser();
        WebhookDelivery::factory()->create([
            'event' => 'issues',
            'received_at' => now()->subMinutes(10),
        ]);
        WebhookDelivery::factory()->create([
            'event' => 'pull_request',
            'received_at' => now()->subMinutes(2),
        ]);

        $this->actingAs($user)
            ->get(route('settings.webhook-deliveries.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('deliveries.data', 2)
                    ->where('deliveries.data.0.event', 'pull_request')
                    ->where('deliveries.data.1.event', 'issues'),
            );
    }

    public function test_status_filter_narrows_the_result_set(): void
    {
        $user = $this->verifiedUser();
        WebhookDelivery::factory()->create([
            'status' => WebhookDeliveryStatus::Processed->value,
        ]);
        WebhookDelivery::factory()->create([
            'status' => WebhookDeliveryStatus::Failed->value,
        ]);

        $this->actingAs($user)
            ->get(route('settings.webhook-deliveries.index', ['status' => 'failed']))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('deliveries.data', 1)
                    ->where('deliveries.data.0.status', 'failed')
                    ->where('filters.status', 'failed'),
            );
    }

    public function test_event_filter_narrows_the_result_set(): void
    {
        $user = $this->verifiedUser();
        WebhookDelivery::factory()->create(['event' => 'issues']);
        WebhookDelivery::factory()->create(['event' => 'pull_request']);

        $this->actingAs($user)
            ->get(route('settings.webhook-deliveries.index', ['event' => 'pull_request']))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('deliveries.data', 1)
                    ->where('deliveries.data.0.event', 'pull_request'),
            );
    }

    public function test_repository_filter_does_a_partial_match(): void
    {
        $user = $this->verifiedUser();
        WebhookDelivery::factory()->create(['repository_full_name' => 'acme/api']);
        WebhookDelivery::factory()->create(['repository_full_name' => 'acme/web']);
        WebhookDelivery::factory()->create(['repository_full_name' => 'other/thing']);

        $this->actingAs($user)
            ->get(route('settings.webhook-deliveries.index', ['repository' => 'acme/']))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page->has('deliveries.data', 2),
            );
    }

    public function test_payload_carries_per_row_shape(): void
    {
        $user = $this->verifiedUser();
        WebhookDelivery::factory()->create([
            'event' => 'issues',
            'action' => 'opened',
            'repository_full_name' => 'acme/api',
            'status' => WebhookDeliveryStatus::Failed->value,
            'error_message' => 'boom',
        ]);

        $this->actingAs($user)
            ->get(route('settings.webhook-deliveries.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('deliveries.data.0.event', 'issues')
                    ->where('deliveries.data.0.action', 'opened')
                    ->where('deliveries.data.0.repository_full_name', 'acme/api')
                    ->where('deliveries.data.0.status', 'failed')
                    ->where('deliveries.data.0.status_tone', 'danger')
                    ->where('deliveries.data.0.error_message', 'boom'),
            );
    }

    public function test_rejects_unknown_status_value(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->get(route('settings.webhook-deliveries.index', ['status' => 'sepia']))
            ->assertSessionHasErrors(['status']);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('settings.webhook-deliveries.index'))
            ->assertRedirect(route('login'));
    }
}
