<?php

namespace Tests\Feature\Repositories;

use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepositoryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_key_is_full_name(): void
    {
        $repo = new Repository;

        $this->assertSame('full_name', $repo->getRouteKeyName());
    }

    public function test_project_relation_resolves(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        $this->assertSame($project->id, $repo->project->id);
    }

    public function test_project_has_many_repositories(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        Repository::factory()->count(3)->create(['project_id' => $project->id]);

        $this->assertSame(3, $project->repositories()->count());
    }
}
