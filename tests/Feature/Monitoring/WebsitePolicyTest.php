<?php

namespace Tests\Feature\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsitePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_project_owner_can_create_update_delete_probe(): void
    {
        $owner = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $website = Website::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($owner->can('create', [Website::class, $project]));
        $this->assertTrue($owner->can('update', $website));
        $this->assertTrue($owner->can('delete', $website));
        $this->assertTrue($owner->can('probe', $website));
    }

    public function test_non_owner_cannot_modify_or_probe(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $website = Website::factory()->create(['project_id' => $project->id]);

        $this->assertFalse($other->can('create', [Website::class, $project]));
        $this->assertFalse($other->can('update', $website));
        $this->assertFalse($other->can('delete', $website));
        $this->assertFalse($other->can('probe', $website));
    }

    public function test_any_verified_user_can_view(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $website = Website::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($other->can('view', $website));
    }

    public function test_unverified_user_is_blocked(): void
    {
        $owner = $this->verifiedUser();
        $unverified = User::factory()->create(['email_verified_at' => null]);
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $website = Website::factory()->create(['project_id' => $project->id]);

        $this->assertFalse($unverified->can('view', $website));
        $this->assertFalse($unverified->can('update', $website));
    }
}
