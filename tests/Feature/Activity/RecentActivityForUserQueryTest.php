<?php

namespace Tests\Feature\Activity;

use App\Domain\Activity\Queries\RecentActivityForUserQuery;
use App\Models\ActivityEvent;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentActivityForUserQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_events_scoped_to_the_users_repositories(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $ownersProject = Project::factory()->create(['owner_user_id' => $owner->id]);
        $othersProject = Project::factory()->create(['owner_user_id' => $other->id]);

        $ownersRepo = Repository::factory()->create([
            'project_id' => $ownersProject->id,
            'full_name' => 'octocat/owners-app',
        ]);
        $othersRepo = Repository::factory()->create([
            'project_id' => $othersProject->id,
            'full_name' => 'octocat/others-app',
        ]);

        ActivityEvent::factory()->create([
            'repository_id' => $ownersRepo->id,
            'title' => 'Owner event',
            'occurred_at' => now()->subMinute(),
        ]);
        ActivityEvent::factory()->create([
            'repository_id' => $othersRepo->id,
            'title' => 'Other user event',
            'occurred_at' => now()->subMinute(),
        ]);

        $events = (new RecentActivityForUserQuery)->handle($owner);

        $this->assertCount(1, $events);
        $this->assertSame('Owner event', $events[0]['title']);
        $this->assertSame('octocat/owners-app', $events[0]['source']);
    }

    public function test_orders_newest_first_and_respects_the_limit(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        // 3 events spaced one minute apart
        $oldest = ActivityEvent::factory()->create([
            'repository_id' => $repo->id,
            'title' => 'Oldest',
            'occurred_at' => now()->subMinutes(3),
        ]);
        $middle = ActivityEvent::factory()->create([
            'repository_id' => $repo->id,
            'title' => 'Middle',
            'occurred_at' => now()->subMinutes(2),
        ]);
        $newest = ActivityEvent::factory()->create([
            'repository_id' => $repo->id,
            'title' => 'Newest',
            'occurred_at' => now()->subMinute(),
        ]);

        // Limit 2 should drop the oldest and order newest first.
        $events = (new RecentActivityForUserQuery)->handle($owner, 2);

        $this->assertCount(2, $events);
        $this->assertSame('Newest', $events[0]['title']);
        $this->assertSame('Middle', $events[1]['title']);
    }

    public function test_maps_event_to_ts_shape(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'octocat/web',
        ]);

        ActivityEvent::factory()->create([
            'repository_id' => $repo->id,
            'event_type' => 'pull_request.merged',
            'severity' => 'success',
            'title' => 'PR #42 merged',
            'occurred_at' => now()->subMinutes(5),
            'metadata' => ['actor_login' => 'octocat'],
        ]);

        $events = (new RecentActivityForUserQuery)->handle($owner);

        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertStringStartsWith('evt-', $event['id']);
        $this->assertSame('pull_request.merged', $event['type']);
        $this->assertSame('success', $event['severity']);
        $this->assertSame('PR #42 merged', $event['title']);
        $this->assertSame('octocat/web', $event['source']);
        $this->assertSame('octocat', $event['metadata']);
        $this->assertNotEmpty($event['occurred_at']); // relative-time string
    }
}
