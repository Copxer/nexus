<?php

namespace Tests\Feature\Events;

use App\Events\HostTelemetryRecorded;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Tests\TestCase;

class HostTelemetryRecordedTest extends TestCase
{
    public function test_implements_should_broadcast_now(): void
    {
        $event = new HostTelemetryRecorded(hostId: 1, ownerUserId: 2);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
    }

    public function test_implements_should_dispatch_after_commit(): void
    {
        $event = new HostTelemetryRecorded(hostId: 1, ownerUserId: 2);

        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
    }

    public function test_broadcasts_on_owner_hosts_channel(): void
    {
        $event = new HostTelemetryRecorded(hostId: 1, ownerUserId: 99);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        // PrivateChannel internally prefixes with "private-"; assert by
        // the suffix the JS client subscribes to.
        $this->assertStringEndsWith('users.99.hosts', $channels[0]->name);
    }

    public function test_no_channels_when_owner_user_id_is_null(): void
    {
        $event = new HostTelemetryRecorded(hostId: 1, ownerUserId: null);

        $this->assertSame([], $event->broadcastOn());
    }

    public function test_broadcast_with_payload_carries_just_the_host_id(): void
    {
        $event = new HostTelemetryRecorded(hostId: 42, ownerUserId: 1);

        $this->assertSame(['host_id' => 42], $event->broadcastWith());
    }

    public function test_broadcast_as_uses_stable_dotted_name(): void
    {
        $event = new HostTelemetryRecorded(hostId: 1, ownerUserId: 2);

        $this->assertSame('HostTelemetryRecorded', $event->broadcastAs());
    }
}
