<?php

namespace Tests\Feature\Activity;

use App\Models\ActivityEvent;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ActivityControllerTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_index_renders_with_events_scoped_to_the_user(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        ActivityEvent::factory()->count(3)->create([
            'repository_id' => $repo->id,
        ]);

        $other = $this->verifiedUser();
        $otherProject = Project::factory()->create(['owner_user_id' => $other->id]);
        $otherRepo = Repository::factory()->create(['project_id' => $otherProject->id]);
        ActivityEvent::factory()->count(2)->create([
            'repository_id' => $otherRepo->id,
        ]);

        $this->actingAs($user)
            ->get(route('activity.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Activity/Index')
                    ->has('events', 3),
            );
    }

    public function test_index_caps_at_one_hundred_events(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        ActivityEvent::factory()->count(120)->create([
            'repository_id' => $repo->id,
        ]);

        $this->actingAs($user)
            ->get(route('activity.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page->has('events', 100),
            );
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('activity.index'))
            ->assertRedirect(route('login'));
    }
}
