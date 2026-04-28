<?php

namespace Tests\Feature\Horizon;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_horizon_dashboard_is_reachable_for_a_verified_user_in_local_or_testing(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/horizon')
            ->assertSuccessful();
    }
}
