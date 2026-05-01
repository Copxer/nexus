<?php

namespace Tests\Feature\Deployments;

use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DeploymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $this->get(route('deployments.index'))
            ->assertRedirect(route('login'));
    }

    public function test_unverified_user_is_redirected_to_verification(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)
            ->get(route('deployments.index'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_renders_deployments_page_with_payload(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repository = Repository::factory()->create(['project_id' => $project->id]);
        WorkflowRun::factory()->create(['repository_id' => $repository->id]);

        $this->actingAs($user)
            ->get(route('deployments.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Deployments/Index')
                    ->has('deployments', 1)
                    ->has('filters')
                    ->has('filterOptions.projects', 1)
                    ->has('filterOptions.repositories', 1)
            );
    }

    public function test_echoes_filters_back_into_payload(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->get(route('deployments.index', [
                'status' => 'completed',
                'conclusion' => 'failure',
                'branch' => 'main',
            ]))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('filters.status', 'completed')
                    ->where('filters.conclusion', 'failure')
                    ->where('filters.branch', 'main')
            );
    }

    public function test_rejects_invalid_status_filter(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->get(route('deployments.index', ['status' => 'banana']))
            ->assertSessionHasErrors('status');
    }

    public function test_rejects_invalid_conclusion_filter(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->get(route('deployments.index', ['conclusion' => 'amazing']))
            ->assertSessionHasErrors('conclusion');
    }

    public function test_does_not_leak_other_users_runs(): void
    {
        $user = $this->verifiedUser();
        $other = $this->verifiedUser();
        $otherProject = Project::factory()->create(['owner_user_id' => $other->id]);
        $otherRepo = Repository::factory()->create(['project_id' => $otherProject->id]);
        WorkflowRun::factory()->create(['repository_id' => $otherRepo->id]);

        $this->actingAs($user)
            ->get(route('deployments.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('deployments', 0)
                    ->has('filterOptions.repositories', 0)
            );
    }
}
