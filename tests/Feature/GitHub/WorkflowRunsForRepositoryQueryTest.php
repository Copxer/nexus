<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Queries\WorkflowRunsForRepositoryQuery;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowRunsForRepositoryQueryTest extends TestCase
{
    use RefreshDatabase;

    private function setUpRepository(): Repository
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        return Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'octocat/hello-world',
        ]);
    }

    public function test_orders_by_run_started_at_desc(): void
    {
        $repository = $this->setUpRepository();

        WorkflowRun::factory()->create([
            'repository_id' => $repository->id,
            'github_id' => 1,
            'run_number' => 1,
            'run_started_at' => now()->subHours(3),
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $repository->id,
            'github_id' => 2,
            'run_number' => 2,
            'run_started_at' => now()->subHour(),
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $repository->id,
            'github_id' => 3,
            'run_number' => 3,
            'run_started_at' => now()->subDay(),
        ]);

        $rows = (new WorkflowRunsForRepositoryQuery)->execute($repository);

        $this->assertCount(3, $rows);
        // Most-recent first: run 2, 1, 3 by run_started_at desc.
        $this->assertSame([2, 1, 3], array_map(fn ($row) => $row['run_number'], $rows));
    }

    public function test_scopes_to_the_given_repository(): void
    {
        $repository = $this->setUpRepository();
        $other = Repository::factory()->create(['full_name' => 'other/repo']);

        WorkflowRun::factory()->count(3)->create(['repository_id' => $repository->id]);
        WorkflowRun::factory()->count(2)->create(['repository_id' => $other->id]);

        $this->assertCount(3, (new WorkflowRunsForRepositoryQuery)->execute($repository));
        $this->assertCount(2, (new WorkflowRunsForRepositoryQuery)->execute($other));
    }

    public function test_caps_at_100_rows(): void
    {
        $repository = $this->setUpRepository();
        WorkflowRun::factory()->count(120)->create(['repository_id' => $repository->id]);

        $rows = (new WorkflowRunsForRepositoryQuery)->execute($repository);

        $this->assertCount(100, $rows);
    }

    public function test_returns_arrays_with_the_expected_keys(): void
    {
        $repository = $this->setUpRepository();
        WorkflowRun::factory()->create(['repository_id' => $repository->id]);

        $row = (new WorkflowRunsForRepositoryQuery)->execute($repository)[0];

        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('run_number', $row);
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayHasKey('event', $row);
        $this->assertArrayHasKey('status', $row);
        $this->assertArrayHasKey('conclusion', $row);
        $this->assertArrayHasKey('head_branch', $row);
        $this->assertArrayHasKey('head_sha', $row);
        $this->assertArrayHasKey('actor_login', $row);
        $this->assertArrayHasKey('html_url', $row);
        $this->assertArrayHasKey('run_started_at', $row);
        $this->assertArrayHasKey('run_updated_at', $row);
    }
}
