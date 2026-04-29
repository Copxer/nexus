<?php

namespace Tests\Feature\Repositories;

use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepositoryPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_project_owner_can_create_and_delete(): void
    {
        $owner = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($owner->can('create', [Repository::class, $project]));
        $this->assertTrue($owner->can('delete', $repo));
    }

    public function test_non_owner_cannot_link_or_delete(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        $this->assertFalse($other->can('create', [Repository::class, $project]));
        $this->assertFalse($other->can('delete', $repo));
    }

    public function test_any_verified_user_can_view(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($other->can('view', $repo));
        $this->assertTrue($other->can('viewAny', Repository::class));
    }

    public function test_unverified_user_is_blocked(): void
    {
        $unverified = User::factory()->create(['email_verified_at' => null]);
        $owner = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        $this->assertFalse($unverified->can('viewAny', Repository::class));
        $this->assertFalse($unverified->can('view', $repo));
        $this->assertFalse($unverified->can('create', [Repository::class, $project]));
        $this->assertFalse($unverified->can('delete', $repo));
    }
}
