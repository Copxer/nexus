<?php

namespace Tests\Feature\Events;

use App\Events\WorkflowRunUpserted;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Tests\TestCase;

class WorkflowRunUpsertedTest extends TestCase
{
    public function test_implements_should_broadcast_now(): void
    {
        $event = new WorkflowRunUpserted(runId: 42, repositoryId: 7, ownerUserId: 1);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
    }

    public function test_broadcasts_on_owner_deployments_channel(): void
    {
        $event = new WorkflowRunUpserted(runId: 42, repositoryId: 7, ownerUserId: 99);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        // PrivateChannel internally prefixes with "private-"; assert
        // by string match on the suffix the client subscribes to.
        $this->assertStringEndsWith('users.99.deployments', $channels[0]->name);
    }

    public function test_no_channels_when_owner_user_id_is_null(): void
    {
        // The handler passes null when project->owner_user_id is null —
        // the defensive branch returns no channels rather than emitting
        // a broadcast nobody is authorized to receive.
        $event = new WorkflowRunUpserted(runId: 42, repositoryId: 7, ownerUserId: null);

        $this->assertSame([], $event->broadcastOn());
    }

    public function test_broadcast_with_payload_carries_just_the_pulse(): void
    {
        $event = new WorkflowRunUpserted(runId: 42, repositoryId: 7, ownerUserId: 1);

        $this->assertSame(
            ['run_id' => 42, 'repository_id' => 7],
            $event->broadcastWith(),
        );
    }

    public function test_broadcast_as_uses_stable_dotted_name(): void
    {
        $event = new WorkflowRunUpserted(runId: 42, repositoryId: 7, ownerUserId: 1);

        $this->assertSame('WorkflowRunUpserted', $event->broadcastAs());
    }
}
