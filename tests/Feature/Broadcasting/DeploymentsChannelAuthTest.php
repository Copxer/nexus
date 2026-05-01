<?php

namespace Tests\Feature\Broadcasting;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `users.{userId}.deployments` private channel authorization (spec 021).
 * Mirrors the spec 019 activity channel test exactly.
 */
class DeploymentsChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_is_authorized_for_their_own_deployments_channel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-users.{$user->id}.deployments",
                'socket_id' => '123.456',
            ])
            ->assertSuccessful();
    }

    public function test_other_user_is_rejected(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-users.{$owner->id}.deployments",
                'socket_id' => '123.456',
            ])
            ->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $owner = User::factory()->create();

        $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-users.{$owner->id}.deployments",
            'socket_id' => '123.456',
        ])->assertForbidden();
    }
}
