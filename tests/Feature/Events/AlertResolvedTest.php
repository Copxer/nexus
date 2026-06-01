<?php

namespace Tests\Feature\Events;

use App\Events\AlertResolved;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Tests\TestCase;

class AlertResolvedTest extends TestCase
{
    public function test_implements_should_broadcast_now(): void
    {
        $event = new AlertResolved(alertId: 1, ownerUserId: 2);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
    }

    public function test_implements_should_dispatch_after_commit(): void
    {
        $event = new AlertResolved(alertId: 1, ownerUserId: 2);

        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
    }

    public function test_broadcasts_on_owner_alerts_channel(): void
    {
        $event = new AlertResolved(alertId: 1, ownerUserId: 99);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertStringEndsWith('users.99.alerts', $channels[0]->name);
    }

    public function test_no_channels_when_owner_user_id_is_null(): void
    {
        $event = new AlertResolved(alertId: 1, ownerUserId: null);

        $this->assertSame([], $event->broadcastOn());
    }

    public function test_broadcast_with_payload_carries_just_the_alert_id(): void
    {
        $event = new AlertResolved(alertId: 42, ownerUserId: 1);

        $this->assertSame(['alert_id' => 42], $event->broadcastWith());
    }

    public function test_broadcast_as_uses_stable_dotted_name(): void
    {
        $event = new AlertResolved(alertId: 1, ownerUserId: 2);

        $this->assertSame('AlertResolved', $event->broadcastAs());
    }
}
