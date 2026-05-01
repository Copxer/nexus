<?php

namespace Tests\Feature\Deployments;

use App\Domain\GitHub\Queries\DeploymentTimelineQuery;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentTimelineQueryTest extends TestCase
{
    use RefreshDatabase;

    private function setUpUserWithProject(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'octo/test',
        ]);

        return ['user' => $user, 'project' => $project, 'repository' => $repository];
    }

    public function test_scopes_to_user_owned_projects(): void
    {
        $context = $this->setUpUserWithProject();

        // Run the user owns.
        WorkflowRun::factory()->create(['repository_id' => $context['repository']->id]);

        // Run another user owns — must not appear in the first user's view.
        $otherUser = User::factory()->create();
        $otherProject = Project::factory()->create(['owner_user_id' => $otherUser->id]);
        $otherRepo = Repository::factory()->create([
            'project_id' => $otherProject->id,
            'full_name' => 'other/test',
        ]);
        WorkflowRun::factory()->create(['repository_id' => $otherRepo->id]);

        $rows = (new DeploymentTimelineQuery)->execute($context['user']);

        $this->assertCount(1, $rows);
        $this->assertSame($context['repository']->id, $rows[0]['repository']['id']);
    }

    public function test_filters_by_project_id(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->create(['owner_user_id' => $user->id]);
        $projectB = Project::factory()->create(['owner_user_id' => $user->id]);
        $repoA = Repository::factory()->create(['project_id' => $projectA->id]);
        $repoB = Repository::factory()->create(['project_id' => $projectB->id]);

        WorkflowRun::factory()->create(['repository_id' => $repoA->id]);
        WorkflowRun::factory()->create(['repository_id' => $repoB->id]);

        $rows = (new DeploymentTimelineQuery)->execute($user, [
            'project_id' => $projectA->id,
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame($projectA->id, $rows[0]['project']['id']);
    }

    public function test_filters_by_repository_id(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repoA = Repository::factory()->create(['project_id' => $project->id]);
        $repoB = Repository::factory()->create(['project_id' => $project->id]);

        WorkflowRun::factory()->create(['repository_id' => $repoA->id]);
        WorkflowRun::factory()->create(['repository_id' => $repoB->id]);

        $rows = (new DeploymentTimelineQuery)->execute($user, [
            'repository_id' => $repoA->id,
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame($repoA->id, $rows[0]['repository']['id']);
    }

    public function test_repository_filter_outside_user_scope_returns_empty(): void
    {
        $context = $this->setUpUserWithProject();

        // Foreign repo — owned by someone else.
        $other = User::factory()->create();
        $otherProject = Project::factory()->create(['owner_user_id' => $other->id]);
        $otherRepo = Repository::factory()->create(['project_id' => $otherProject->id]);
        WorkflowRun::factory()->create(['repository_id' => $otherRepo->id]);

        $rows = (new DeploymentTimelineQuery)->execute($context['user'], [
            'repository_id' => $otherRepo->id,
        ]);

        $this->assertSame([], $rows);
    }

    public function test_filters_by_status(): void
    {
        $context = $this->setUpUserWithProject();

        WorkflowRun::factory()->create([
            'repository_id' => $context['repository']->id,
            'status' => 'in_progress',
            'conclusion' => null,
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $context['repository']->id,
            'status' => 'completed',
            'conclusion' => 'success',
        ]);

        $rows = (new DeploymentTimelineQuery)->execute($context['user'], [
            'status' => 'in_progress',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('in_progress', $rows[0]['status']);
    }

    public function test_filters_by_conclusion(): void
    {
        $context = $this->setUpUserWithProject();

        WorkflowRun::factory()->create([
            'repository_id' => $context['repository']->id,
            'status' => 'completed',
            'conclusion' => 'success',
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $context['repository']->id,
            'status' => 'completed',
            'conclusion' => 'failure',
        ]);

        $rows = (new DeploymentTimelineQuery)->execute($context['user'], [
            'conclusion' => 'failure',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('failure', $rows[0]['conclusion']);
    }

    public function test_filters_by_branch(): void
    {
        $context = $this->setUpUserWithProject();

        WorkflowRun::factory()->create([
            'repository_id' => $context['repository']->id,
            'head_branch' => 'main',
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $context['repository']->id,
            'head_branch' => 'develop',
        ]);

        $rows = (new DeploymentTimelineQuery)->execute($context['user'], [
            'branch' => 'main',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('main', $rows[0]['head_branch']);
    }

    public function test_filters_compose(): void
    {
        $context = $this->setUpUserWithProject();

        // Matches all filters.
        WorkflowRun::factory()->create([
            'repository_id' => $context['repository']->id,
            'status' => 'completed',
            'conclusion' => 'success',
            'head_branch' => 'main',
        ]);
        // Matches conclusion + branch but wrong status.
        WorkflowRun::factory()->create([
            'repository_id' => $context['repository']->id,
            'status' => 'in_progress',
            'conclusion' => null,
            'head_branch' => 'main',
        ]);
        // Matches status + conclusion but wrong branch.
        WorkflowRun::factory()->create([
            'repository_id' => $context['repository']->id,
            'status' => 'completed',
            'conclusion' => 'success',
            'head_branch' => 'develop',
        ]);

        $rows = (new DeploymentTimelineQuery)->execute($context['user'], [
            'status' => 'completed',
            'conclusion' => 'success',
            'branch' => 'main',
        ]);

        $this->assertCount(1, $rows);
    }

    public function test_orders_by_run_started_at_desc(): void
    {
        $context = $this->setUpUserWithProject();

        WorkflowRun::factory()->create([
            'repository_id' => $context['repository']->id,
            'github_id' => 1,
            'run_number' => 1,
            'run_started_at' => now()->subHours(3),
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $context['repository']->id,
            'github_id' => 2,
            'run_number' => 2,
            'run_started_at' => now()->subMinutes(10),
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $context['repository']->id,
            'github_id' => 3,
            'run_number' => 3,
            'run_started_at' => now()->subDay(),
        ]);

        $rows = (new DeploymentTimelineQuery)->execute($context['user']);

        $this->assertSame([2, 1, 3], array_map(fn ($row) => $row['run_number'], $rows));
    }

    public function test_caps_at_100_rows(): void
    {
        $context = $this->setUpUserWithProject();
        WorkflowRun::factory()->count(120)->create([
            'repository_id' => $context['repository']->id,
        ]);

        $rows = (new DeploymentTimelineQuery)->execute($context['user']);

        $this->assertCount(100, $rows);
    }

    public function test_returns_empty_when_user_has_no_projects(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], (new DeploymentTimelineQuery)->execute($user));
    }

    public function test_returned_rows_carry_repository_and_project_chips(): void
    {
        $context = $this->setUpUserWithProject();
        WorkflowRun::factory()->create(['repository_id' => $context['repository']->id]);

        $row = (new DeploymentTimelineQuery)->execute($context['user'])[0];

        $this->assertArrayHasKey('repository', $row);
        $this->assertSame($context['repository']->id, $row['repository']['id']);
        $this->assertArrayHasKey('project', $row);
        $this->assertSame($context['project']->id, $row['project']['id']);
    }

    public function test_completed_run_carries_duration_seconds(): void
    {
        $context = $this->setUpUserWithProject();
        WorkflowRun::factory()->create([
            'repository_id' => $context['repository']->id,
            'status' => 'completed',
            'conclusion' => 'success',
            'run_started_at' => now()->subMinutes(5),
            'run_completed_at' => now(),
        ]);

        $row = (new DeploymentTimelineQuery)->execute($context['user'])[0];

        $this->assertNotNull($row['duration_seconds']);
        $this->assertGreaterThanOrEqual(295, $row['duration_seconds']);
        $this->assertLessThanOrEqual(305, $row['duration_seconds']);
    }

    public function test_in_flight_run_has_null_duration(): void
    {
        $context = $this->setUpUserWithProject();
        WorkflowRun::factory()->create([
            'repository_id' => $context['repository']->id,
            'status' => 'in_progress',
            'conclusion' => null,
            'run_started_at' => now()->subMinutes(2),
            'run_completed_at' => null,
        ]);

        $row = (new DeploymentTimelineQuery)->execute($context['user'])[0];

        $this->assertNull($row['duration_seconds']);
    }
}
