<?php

namespace Tests\Feature\Activity;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Enums\ActivitySeverity;
use App\Events\ActivityEventCreated;
use App\Models\ActivityEvent;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ActivityEventCreatedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_action_dispatches_broadcast_for_repo_owned_event(): void
    {
        Event::fake([ActivityEventCreated::class]);

        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        (new CreateActivityEventAction)->execute([
            'event_type' => 'issue.created',
            'severity' => ActivitySeverity::Info,
            'title' => 'New issue opened',
            'occurred_at' => now(),
            'repository_id' => $repo->id,
        ]);

        Event::assertDispatched(
            ActivityEventCreated::class,
            fn (ActivityEventCreated $event) => $event->activityEvent->repository_id === $repo->id,
        );
    }

    public function test_broadcast_targets_owners_private_channel(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        $row = ActivityEvent::factory()->create(['repository_id' => $repo->id]);
        $row->setRelation('repository', $repo->setRelation('project', $project));

        $channels = (new ActivityEventCreated($row))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-users.{$owner->id}.activity", $channels[0]->name);
    }

    public function test_broadcast_is_silent_when_event_has_no_repository(): void
    {
        $row = ActivityEvent::factory()->create(['repository_id' => null]);

        $channels = (new ActivityEventCreated($row))->broadcastOn();

        $this->assertSame([], $channels);
    }

    public function test_broadcast_payload_matches_ts_shape(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'octocat/web',
        ]);

        $row = ActivityEvent::factory()->create([
            'repository_id' => $repo->id,
            'event_type' => 'pull_request.merged',
            'severity' => 'success',
            'title' => 'PR #99 merged',
            'occurred_at' => now()->subMinutes(2),
            'metadata' => ['actor_login' => 'octocat'],
        ]);
        $row->setRelation('repository', $repo);

        $payload = (new ActivityEventCreated($row))->broadcastWith();

        $this->assertSame('pull_request.merged', $payload['type']);
        $this->assertSame('success', $payload['severity']);
        $this->assertSame('PR #99 merged', $payload['title']);
        $this->assertSame('octocat/web', $payload['source']);
        $this->assertSame('octocat', $payload['metadata']);
        $this->assertStringStartsWith('evt-', $payload['id']);
    }

    public function test_broadcast_event_name_is_stable_across_namespaces(): void
    {
        $row = ActivityEvent::factory()->create(['repository_id' => null]);

        $this->assertSame('ActivityEventCreated', (new ActivityEventCreated($row))->broadcastAs());
    }
}
