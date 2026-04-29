<?php

namespace Tests\Feature\Activity;

use App\Models\ActivityEvent;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * `HandleInertiaRequests::share()` (spec 018) registers an `activity.recent`
 * key so every authenticated page picks up the latest events without each
 * controller having to query for them. These tests exercise the shared
 * prop via `/overview` (any authenticated route works) — guests get an
 * empty array, the owner sees their events.
 */
class SharedActivityPropTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_authenticated_request_sees_activity_recent_populated(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        ActivityEvent::factory()->create([
            'repository_id' => $repo->id,
            'title' => 'Smoke event',
            'occurred_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->get(route('overview'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('activity.recent', 1)
                    ->where('activity.recent.0.title', 'Smoke event'),
            );
    }

    public function test_users_only_see_their_own_events_via_shared_prop(): void
    {
        $user = $this->verifiedUser();
        $other = $this->verifiedUser();

        $usersProject = Project::factory()->create(['owner_user_id' => $user->id]);
        $othersProject = Project::factory()->create(['owner_user_id' => $other->id]);

        $usersRepo = Repository::factory()->create(['project_id' => $usersProject->id]);
        $othersRepo = Repository::factory()->create(['project_id' => $othersProject->id]);

        ActivityEvent::factory()->create([
            'repository_id' => $usersRepo->id,
            'title' => 'My event',
            'occurred_at' => now()->subMinute(),
        ]);
        ActivityEvent::factory()->create([
            'repository_id' => $othersRepo->id,
            'title' => 'Their event',
            'occurred_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->get(route('overview'))
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('activity.recent', 1)
                    ->where('activity.recent.0.title', 'My event'),
            );
    }
}
