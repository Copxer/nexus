<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_non_owner_cannot_update_or_delete(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        $this->assertFalse($other->can('update', $project));
        $this->assertFalse($other->can('delete', $project));
    }

    public function test_owner_can_update_and_delete(): void
    {
        $owner = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        $this->assertTrue($owner->can('update', $project));
        $this->assertTrue($owner->can('delete', $project));
    }

    public function test_any_verified_user_can_view(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        $this->assertTrue($other->can('view', $project));
        $this->assertTrue($other->can('viewAny', Project::class));
    }

    public function test_unverified_user_cannot_view_or_create(): void
    {
        $unverified = User::factory()->create(['email_verified_at' => null]);
        $owner = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        $this->assertFalse($unverified->can('viewAny', Project::class));
        $this->assertFalse($unverified->can('view', $project));
        $this->assertFalse($unverified->can('create', Project::class));
    }
}
