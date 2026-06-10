<?php

namespace Tests\Unit\Domain\Analytics\Queries;

use App\Domain\Analytics\Queries\GetDeploymentMetricsQuery;
use App\Enums\WorkflowRunConclusion;
use App\Enums\WorkflowRunStatus;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GetDeploymentMetricsQueryTest extends TestCase
{
    use RefreshDatabase;

    private function from30Days(): Carbon
    {
        return now()->startOfDay()->subDays(29);
    }

    public function test_empty_state_returns_muted_status_and_zero_frequency(): void
    {
        $user = User::factory()->create();

        $result = app(GetDeploymentMetricsQuery::class)->execute($user, $this->from30Days());

        $this->assertSame(0, $result['frequency']['total']);
        $this->assertCount(30, $result['frequency']['sparkline']);
        $this->assertSame(array_fill(0, 30, 0), $result['frequency']['sparkline']);
        $this->assertNull($result['success_rate']['percent']);
        $this->assertSame('muted', $result['success_rate']['status']);
    }

    public function test_counts_completed_runs_and_computes_success_rate(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        // 3 success + 1 failure = 75% success rate.
        WorkflowRun::factory()->count(3)->create([
            'repository_id' => $repo->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Success->value,
            'run_completed_at' => now()->subDays(2),
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $repo->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Failure->value,
            'run_completed_at' => now()->subDays(2),
        ]);

        $result = app(GetDeploymentMetricsQuery::class)->execute($user, $this->from30Days());

        $this->assertSame(4, $result['frequency']['total']);
        $this->assertSame(75.0, $result['success_rate']['percent']);
        $this->assertSame('danger', $result['success_rate']['status']);
    }

    public function test_cancelled_and_in_progress_runs_dont_skew_success_rate(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        WorkflowRun::factory()->create([
            'repository_id' => $repo->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Success->value,
            'run_completed_at' => now()->subDay(),
        ]);
        // Cancelled doesn't count as success or failure.
        WorkflowRun::factory()->create([
            'repository_id' => $repo->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Cancelled->value,
            'run_completed_at' => now()->subDay(),
        ]);

        $result = app(GetDeploymentMetricsQuery::class)->execute($user, $this->from30Days());

        // Total counts all completed runs (incl. cancelled).
        $this->assertSame(2, $result['frequency']['total']);
        // Success rate ignores cancelled — 1 success / 1 (success + failure) = 100%.
        $this->assertSame(100.0, $result['success_rate']['percent']);
        $this->assertSame('success', $result['success_rate']['status']);
    }

    public function test_sparkline_length_matches_range(): void
    {
        $user = User::factory()->create();

        $from7d = now()->startOfDay()->subDays(6);
        $from90d = now()->startOfDay()->subDays(89);

        $r7 = app(GetDeploymentMetricsQuery::class)->execute($user, $from7d);
        $r90 = app(GetDeploymentMetricsQuery::class)->execute($user, $from90d);

        $this->assertCount(7, $r7['frequency']['sparkline']);
        $this->assertCount(90, $r90['frequency']['sparkline']);
    }

    public function test_runs_outside_the_window_are_excluded(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create(['project_id' => $project->id]);

        WorkflowRun::factory()->create([
            'repository_id' => $repo->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Success->value,
            'run_completed_at' => now()->subDays(40), // outside 30d window
        ]);

        $result = app(GetDeploymentMetricsQuery::class)->execute($user, $this->from30Days());

        $this->assertSame(0, $result['frequency']['total']);
        $this->assertNull($result['success_rate']['percent']);
    }

    public function test_cross_user_isolation(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $projectB = Project::factory()->create(['owner_user_id' => $b->id]);
        $repoB = Repository::factory()->create(['project_id' => $projectB->id]);

        WorkflowRun::factory()->count(5)->create([
            'repository_id' => $repoB->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Failure->value,
            'run_completed_at' => now()->subDay(),
        ]);

        $result = app(GetDeploymentMetricsQuery::class)->execute($a, $this->from30Days());

        $this->assertSame(0, $result['frequency']['total']);
        $this->assertNull($result['success_rate']['percent']);
    }
}
