<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_slug_auto_generates_from_name_on_create(): void
    {
        $user = User::factory()->create();

        $project = Project::factory()->create([
            'name' => 'Customer Portal v3',
            'owner_user_id' => $user->id,
        ]);

        $this->assertSame('customer-portal-v3', $project->slug);
    }

    public function test_slug_collision_appends_a_3_char_suffix(): void
    {
        $user = User::factory()->create();

        $first = Project::factory()->create([
            'name' => 'Billing API',
            'owner_user_id' => $user->id,
        ]);
        $second = Project::factory()->create([
            'name' => 'Billing API',
            'owner_user_id' => $user->id,
        ]);

        $this->assertSame('billing-api', $first->slug);
        $this->assertNotSame($first->slug, $second->slug);
        $this->assertMatchesRegularExpression('/^billing-api-[a-z0-9]{3}$/', $second->slug);
    }

    public function test_slug_falls_back_to_project_when_name_has_no_slug_chars(): void
    {
        $user = User::factory()->create();

        $project = Project::factory()->create([
            'name' => '!!! ???',
            'owner_user_id' => $user->id,
        ]);

        // Bare 'project', or 'project-XXX' if another '!!!' was created first.
        $this->assertMatchesRegularExpression('/^project(?:-[a-z0-9]{3})?$/', $project->slug);
    }
}
