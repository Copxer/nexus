<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_renders_for_a_verified_user_with_default_range(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->get(route('analytics.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Analytics/Index')
                    ->where('filters.range', '30d')
                    ->has('metrics.deployments')
                    ->has('metrics.alerts')
                    ->has('metrics.websites')
                    ->has('metrics.containers'),
            );
    }

    public function test_accepts_each_valid_range(): void
    {
        $user = $this->verifiedUser();

        foreach (['7d', '30d', '90d'] as $range) {
            $this->actingAs($user)
                ->get(route('analytics.index', ['range' => $range]))
                ->assertSuccessful()
                ->assertInertia(
                    fn (AssertableInertia $page) => $page
                        ->where('filters.range', $range),
                );
        }
    }

    public function test_rejects_an_invalid_range(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->get(route('analytics.index', ['range' => '1y']))
            ->assertStatus(302); // validation redirect
    }

    public function test_payload_carries_expected_metric_shape(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->get(route('analytics.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('metrics.deployments.frequency.total')
                    ->has('metrics.deployments.frequency.sparkline')
                    ->has('metrics.deployments.success_rate.status')
                    ->has('metrics.alerts.frequency.total')
                    ->has('metrics.alerts.mttr.status')
                    ->has('metrics.websites.uptime.status')
                    ->has('metrics.websites.response_time.status')
                    ->has('metrics.containers.cpu.status')
                    ->has('metrics.containers.memory.status'),
            );
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('analytics.index'))
            ->assertRedirect(route('login'));
    }
}
