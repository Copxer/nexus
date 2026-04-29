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
                        // No seeded data in this test → projects.status is 'muted'.
                        ->where('projects.status', 'muted')
                        ->has('deployments.successful_24h')
                        ->has('services.running')
                        ->has('hosts.online')
                        ->has('alerts.active')
                        ->where('alerts.status', 'danger')
                        ->has('uptime.overall')
                        ->has('topRepositories')
                    )
            );
    }

    public function test_overview_carries_activity_feed_and_heatmap_payloads(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/overview')
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Overview')
                    ->has('recentActivity', 9)
                    ->has('recentActivity.0', fn (AssertableInertia $event) => $event
                        ->has('id')
                        ->has('type')
                        ->has('severity')
                        ->has('title')
                        ->has('source')
                        ->has('occurred_at')
                        ->etc()
                    )
                    ->has('activityHeatmap', 7)
                    ->has('activityHeatmap.0', 6)
            );
    }
}
