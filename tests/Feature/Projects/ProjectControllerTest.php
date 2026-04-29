<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectControllerTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_index_lists_projects_for_a_verified_user(): void
    {
        $user = $this->verifiedUser();
        Project::factory()->create([
            'owner_user_id' => $user->id,
            'name' => 'Customer Portal',
        ]);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Projects/Index')
                    ->has('projects', 1)
                    ->where('projects.0.name', 'Customer Portal')
            );
    }

    public function test_create_form_renders_with_options_payload(): void
    {
        $this->actingAs($this->verifiedUser())
            ->get(route('projects.create'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Projects/Create')
                    ->has('options.statuses')
                    ->has('options.priorities')
                    ->has('options.colors')
                    ->has('options.icons', 12)
            );
    }

    public function test_store_creates_a_project_owned_by_the_current_user(): void
    {
        $user = $this->verifiedUser();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Billing API',
            'description' => 'Subscription orchestration.',
            'status' => 'active',
            'priority' => 'critical',
            'environment' => 'production',
            'color' => 'magenta',
            'icon' => 'BarChart3',
        ]);

        $project = Project::query()->firstWhere('name', 'Billing API');

        $this->assertNotNull($project);
        $this->assertSame($user->id, $project->owner_user_id);
        $this->assertSame('billing-api', $project->slug);
        $response->assertRedirect(route('projects.show', $project));
    }

    public function test_show_renders_the_project(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create([
            'owner_user_id' => $user->id,
            'name' => 'Edge Cache Pilot',
        ]);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Projects/Show')
                    ->where('project.name', 'Edge Cache Pilot')
                    ->where('canUpdate', true)
                    ->where('canDelete', true)
            );
    }

    public function test_edit_renders_for_owner(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('projects.edit', $project))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page->component('Projects/Edit')
            );
    }

    public function test_update_changes_the_project(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create([
            'owner_user_id' => $user->id,
            'priority' => 'low',
        ]);

        $response = $this->actingAs($user)->patch(
            route('projects.update', $project),
            [
                'name' => $project->name,
                'description' => $project->description,
                'status' => 'active',
                'priority' => 'critical',
                'environment' => 'production',
                'color' => 'cyan',
                'icon' => 'Rocket',
            ],
        );

        $project->refresh();

        $this->assertSame('critical', $project->priority->value);
        $this->assertSame('cyan', $project->color);
        $response->assertRedirect(route('projects.show', $project));
    }

    public function test_destroy_deletes_for_owner(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        $this->actingAs($user)
            ->delete(route('projects.destroy', $project))
            ->assertRedirect(route('projects.index'));

        $this->assertNull(Project::query()->find($project->id));
    }
}
