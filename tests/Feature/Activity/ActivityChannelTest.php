<?php

namespace Tests\Feature\Activity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use ReflectionClass;
use Tests\TestCase;

/**
 * Spec 019 — `routes/channels.php` registers `users.{userId}.activity`.
 * Authorization is symmetric with the existing user-model channel: a
 * subscriber is allowed only when their own ID matches the channel's
 * `{userId}` placeholder.
 *
 * Tests the channel callback directly (extracted from the broadcaster's
 * registered channels) rather than the full HTTP auth round-trip — the
 * closure result is what gates a connection. Going through
 * `/broadcasting/auth` adds session/CSRF middleware noise that isn't
 * about the security rule we're testing.
 */
class ActivityChannelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pull the registered channel callback out of the broadcaster.
     * Same logic the BroadcastManager runs at request time.
     */
    private function activityChannelCallback(): callable
    {
        $broadcaster = Broadcast::getFacadeRoot()
            ->driver();

        $reflection = new ReflectionClass($broadcaster);
        $property = $reflection->getProperty('channels');
        $property->setAccessible(true);

        /** @var array<string, callable> $channels */
        $channels = $property->getValue($broadcaster);

        $key = collect(array_keys($channels))
            ->first(fn (string $name) => str_contains($name, 'users.{userId}.activity'));

        $this->assertNotNull(
            $key,
            'Activity channel `users.{userId}.activity` is not registered. Check routes/channels.php.',
        );

        return $channels[$key];
    }

    public function test_owner_is_authorized_for_their_own_channel(): void
    {
        $user = User::factory()->create();
        $callback = $this->activityChannelCallback();

        $this->assertTrue((bool) $callback($user, $user->id));
    }

    public function test_other_user_is_not_authorized(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $callback = $this->activityChannelCallback();

        $this->assertFalse((bool) $callback($other, $owner->id));
    }

    public function test_string_user_id_matches_when_numerically_equal(): void
    {
        // Pusher delivers channel placeholders as strings; the
        // callback's `(int)` casts must accept the string form of the
        // user's ID and compare it against the in-memory User model.
        $user = User::factory()->create();
        $callback = $this->activityChannelCallback();

        $this->assertTrue((bool) $callback($user, (string) $user->id));
    }
}
