<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SystemHealthCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_payload_carries_system_health_shape(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Settings/Index')
                    ->has('systemHealth.queue.pending')
                    ->has('systemHealth.queue.failed_5m')
                    ->has('systemHealth.queue.status')
                    ->has('systemHealth.webhooks.deliveries_5m')
                    ->has('systemHealth.webhooks.failures_5m')
                    ->has('systemHealth.webhooks.status')
                    ->has('systemHealth.github_rate_limit.status')
                    ->has('systemHealth.agent_auth.failures_5m')
                    ->has('systemHealth.agent_auth.status'),
            );
    }
}
