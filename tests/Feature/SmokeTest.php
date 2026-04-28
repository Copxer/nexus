<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_renders_for_a_verified_user(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/overview')
            ->assertStatus(200);
    }

    public function test_overview_carries_the_mock_dashboard_payload(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/overview')
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Overview')
                    ->has('dashboard', fn (AssertableInertia $dashboard) => $dashboard
                        ->has('projects.active')
                        ->has('projects.sparkline')
                        ->where('projects.status', 'success')
                        ->has('deployments.successful_24h')
                        ->has('services.running')
                        ->has('hosts.online')
                        ->has('alerts.active')
                        ->where('alerts.status', 'danger')
                        ->has('uptime.overall')
                    )
            );
    }
}
