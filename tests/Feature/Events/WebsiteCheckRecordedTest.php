<?php

namespace Tests\Feature\Events;

use App\Events\WebsiteCheckRecorded;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Tests\TestCase;

class WebsiteCheckRecordedTest extends TestCase
{
    public function test_implements_should_broadcast_now(): void
    {
        $event = new WebsiteCheckRecorded(checkId: 1, websiteId: 2, ownerUserId: 3);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
    }

    public function test_broadcasts_on_owner_monitoring_channel(): void
    {
        $event = new WebsiteCheckRecorded(checkId: 1, websiteId: 2, ownerUserId: 99);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        // PrivateChannel internally prefixes with "private-"; assert
        // by the suffix the JS client subscribes to.
        $this->assertStringEndsWith('users.99.monitoring', $channels[0]->name);
    }

    public function test_no_channels_when_owner_user_id_is_null(): void
    {
        $event = new WebsiteCheckRecorded(checkId: 1, websiteId: 2, ownerUserId: null);

        $this->assertSame([], $event->broadcastOn());
    }

    public function test_broadcast_with_payload_carries_just_the_pulse(): void
    {
        $event = new WebsiteCheckRecorded(checkId: 42, websiteId: 7, ownerUserId: 1);

        $this->assertSame(
            ['check_id' => 42, 'website_id' => 7],
            $event->broadcastWith(),
        );
    }

    public function test_broadcast_as_uses_stable_dotted_name(): void
    {
        $event = new WebsiteCheckRecorded(checkId: 1, websiteId: 2, ownerUserId: 3);

        $this->assertSame('WebsiteCheckRecorded', $event->broadcastAs());
    }
}
