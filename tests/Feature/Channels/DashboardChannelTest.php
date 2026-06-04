<?php

namespace Tests\Feature\Channels;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use ReflectionClass;
use Tests\TestCase;

/**
 * Spec 033 — `routes/channels.php` registers
 * `users.{userId}.dashboard`. Authorization mirrors the existing
 * activity / monitoring / hosts / alerts channels: only the matching
 * user can subscribe.
 *
 * Tests the channel callback directly (extracted from the
 * broadcaster's registered channels) rather than the full HTTP auth
 * round-trip — the closure result is what gates a connection.
 */
class DashboardChannelTest extends TestCase
{
    use RefreshDatabase;

    private function dashboardChannelCallback(): callable
    {
        $broadcaster = Broadcast::getFacadeRoot()->driver();

        $reflection = new ReflectionClass($broadcaster);
        $property = $reflection->getProperty('channels');
        $property->setAccessible(true);

        /** @var array<string, callable> $channels */
        $channels = $property->getValue($broadcaster);

        $key = collect(array_keys($channels))
            ->first(fn (string $name) => str_contains($name, 'users.{userId}.dashboard'));

        $this->assertNotNull(
            $key,
            'Dashboard channel `users.{userId}.dashboard` is not registered. Check routes/channels.php.',
        );

        return $channels[$key];
    }

    public function test_owner_is_authorized_for_their_own_channel(): void
    {
        $user = User::factory()->create();
        $callback = $this->dashboardChannelCallback();

        $this->assertTrue((bool) $callback($user, $user->id));
    }

    public function test_other_user_is_not_authorized(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $callback = $this->dashboardChannelCallback();

        $this->assertFalse((bool) $callback($other, $owner->id));
    }

    public function test_string_user_id_matches_when_numerically_equal(): void
    {
        $user = User::factory()->create();
        $callback = $this->dashboardChannelCallback();

        $this->assertTrue((bool) $callback($user, (string) $user->id));
    }
}
