<?php

namespace Tests\Feature\Monitoring;

use App\Models\Host;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_create_requires_a_project_the_user_owns(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        $this->assertTrue($owner->can('create', [Host::class, $project]));
        $this->assertFalse($other->can('create', [Host::class, $project]));
        $this->assertFalse($owner->can('create', [Host::class, null]));
    }

    public function test_update_and_delete_follow_host_project_owner(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($owner->can('update', $host));
        $this->assertTrue($owner->can('delete', $host));
        $this->assertTrue($owner->can('manageTokens', $host));

        $this->assertFalse($other->can('update', $host));
        $this->assertFalse($other->can('delete', $host));
        $this->assertFalse($other->can('manageTokens', $host));
    }

    public function test_view_open_to_any_verified_user_in_phase_1(): void
    {
        $user = $this->verifiedUser();
        $owner = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);

        // Mirrors WebsitePolicy — single-tenant phase-1 keeps view open
        // so the team-pivot lands without changing every callsite.
        $this->assertTrue($user->can('view', $host));
        $this->assertTrue($user->can('viewAny', Host::class));
    }
}
