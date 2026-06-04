<?php

namespace Tests\Feature\Events;

use App\Events\HealthScoreUpdated;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Tests\TestCase;

class HealthScoreUpdatedTest extends TestCase
{
    public function test_implements_should_broadcast_now(): void
    {
        $event = new HealthScoreUpdated(projectId: 1, ownerUserId: 2, score: 80, band: 'good');

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
    }

    public function test_implements_should_dispatch_after_commit(): void
    {
        $event = new HealthScoreUpdated(projectId: 1, ownerUserId: 2, score: 80, band: 'good');

        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
    }

    public function test_broadcasts_on_owner_dashboard_channel(): void
    {
        $event = new HealthScoreUpdated(projectId: 1, ownerUserId: 99, score: 80, band: 'good');

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertStringEndsWith('users.99.dashboard', $channels[0]->name);
    }

    public function test_no_channels_when_owner_user_id_is_null(): void
    {
        $event = new HealthScoreUpdated(projectId: 1, ownerUserId: null, score: 80, band: 'good');

        $this->assertSame([], $event->broadcastOn());
    }

    public function test_broadcast_with_payload_carries_project_score_and_band(): void
    {
        $event = new HealthScoreUpdated(projectId: 42, ownerUserId: 1, score: 65, band: 'degraded');

        $this->assertSame(
            ['project_id' => 42, 'health_score' => 65, 'band' => 'degraded'],
            $event->broadcastWith(),
        );
    }

    public function test_broadcast_as_uses_stable_dotted_name(): void
    {
        $event = new HealthScoreUpdated(projectId: 1, ownerUserId: 2, score: 80, band: 'good');

        $this->assertSame('HealthScoreUpdated', $event->broadcastAs());
    }
}
