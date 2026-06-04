<?php

namespace Tests\Unit\Domain\Analytics;

use App\Domain\Analytics\Actions\ComputeProjectHealthScoreAction;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\HostStatus;
use App\Enums\WebsiteStatus;
use App\Enums\WorkflowRunConclusion;
use App\Enums\WorkflowRunStatus;
use App\Models\Alert;
use App\Models\Container;
use App\Models\Host;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Website;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ComputeProjectHealthScoreActionTest extends TestCase
{
    use RefreshDatabase;

    private function compute(Project $project): int
    {
        return app(ComputeProjectHealthScoreAction::class)->execute($project);
    }

    public function test_baseline_is_100_when_no_signals_deduct(): void
    {
        $project = Project::factory()->create();

        $this->assertSame(100, $this->compute($project));
    }

    public function test_one_critical_alert_deducts_30(): void
    {
        $project = Project::factory()->create();
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
        ]);

        $this->assertSame(70, $this->compute($project));
    }

    public function test_one_warning_alert_deducts_15(): void
    {
        $project = Project::factory()->create();
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Warning->value,
            'status' => AlertStatus::Open->value,
        ]);

        $this->assertSame(85, $this->compute($project));
    }

    public function test_acknowledged_alert_still_counts(): void
    {
        // §14.2 deducts for *active* alerts — open + acknowledged.
        // Only resolved + muted stop dragging the score down.
        $project = Project::factory()->create();
        Alert::factory()->acknowledged()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
        ]);

        $this->assertSame(70, $this->compute($project));
    }

    public function test_resolved_and_muted_alerts_do_not_count(): void
    {
        $project = Project::factory()->create();
        Alert::factory()->resolved()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
        ]);
        Alert::factory()->muted()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
        ]);

        $this->assertSame(100, $this->compute($project));
    }

    public function test_multiple_alerts_stack_per_occurrence(): void
    {
        $project = Project::factory()->create();
        // 2 critical (-60) + 1 warning (-15) = -75
        Alert::factory()->count(2)->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
        ]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Warning->value,
            'status' => AlertStatus::Open->value,
        ]);

        $this->assertSame(25, $this->compute($project));
    }

    public function test_website_down_and_slow_deductions_stack(): void
    {
        $project = Project::factory()->create();
        Website::factory()->create([
            'project_id' => $project->id,
            'status' => WebsiteStatus::Down->value,
        ]);
        Website::factory()->create([
            'project_id' => $project->id,
            'status' => WebsiteStatus::Slow->value,
        ]);

        // 100 - 20 (down) - 10 (slow) = 70
        $this->assertSame(70, $this->compute($project));
    }

    public function test_website_error_status_is_treated_as_down(): void
    {
        $project = Project::factory()->create();
        Website::factory()->create([
            'project_id' => $project->id,
            'status' => WebsiteStatus::Error->value,
        ]);

        $this->assertSame(80, $this->compute($project));
    }

    public function test_host_offline_deducts_15(): void
    {
        $project = Project::factory()->create();
        Host::factory()->create([
            'project_id' => $project->id,
            'status' => HostStatus::Offline->value,
        ]);

        $this->assertSame(85, $this->compute($project));
    }

    public function test_archived_host_is_not_counted(): void
    {
        $project = Project::factory()->create();
        Host::factory()->create([
            'project_id' => $project->id,
            'status' => HostStatus::Offline->value,
            'archived_at' => Carbon::now()->subDay(),
        ]);

        $this->assertSame(100, $this->compute($project));
    }

    public function test_unhealthy_container_deducts_10(): void
    {
        $project = Project::factory()->create();
        $host = Host::factory()->create(['project_id' => $project->id]);
        Container::factory()->create([
            'host_id' => $host->id,
            'project_id' => $project->id,
            'health_status' => 'unhealthy',
        ]);

        $this->assertSame(90, $this->compute($project));
    }

    public function test_healthy_and_null_health_status_containers_do_not_count(): void
    {
        $project = Project::factory()->create();
        $host = Host::factory()->create(['project_id' => $project->id]);
        Container::factory()->create([
            'host_id' => $host->id,
            'project_id' => $project->id,
            'health_status' => 'healthy',
        ]);
        Container::factory()->create([
            'host_id' => $host->id,
            'project_id' => $project->id,
            'health_status' => null,
        ]);

        $this->assertSame(100, $this->compute($project));
    }

    public function test_failed_default_branch_workflow_in_24h_deducts_20(): void
    {
        $project = Project::factory()->create();
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'default_branch' => 'main',
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $repo->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Failure->value,
            'head_branch' => 'main',
            'run_completed_at' => Carbon::now()->subHours(2),
        ]);

        $this->assertSame(80, $this->compute($project));
    }

    public function test_failed_feature_branch_workflow_does_not_deduct(): void
    {
        $project = Project::factory()->create();
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'default_branch' => 'main',
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $repo->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Failure->value,
            'head_branch' => 'feature/widget',
            'run_completed_at' => Carbon::now()->subHours(2),
        ]);

        $this->assertSame(100, $this->compute($project));
    }

    public function test_failed_default_branch_workflow_older_than_24h_does_not_deduct(): void
    {
        $project = Project::factory()->create();
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'default_branch' => 'main',
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $repo->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Failure->value,
            'head_branch' => 'main',
            'run_completed_at' => Carbon::now()->subDays(2),
        ]);

        $this->assertSame(100, $this->compute($project));
    }

    public function test_clamps_to_zero_on_worst_case_stack(): void
    {
        $project = Project::factory()->create();
        // 5 critical alerts = -150 → clamped to 0.
        Alert::factory()->count(5)->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
        ]);

        $this->assertSame(0, $this->compute($project));
    }

    public function test_signals_on_another_project_do_not_affect_this_score(): void
    {
        $a = Project::factory()->create();
        $b = Project::factory()->create();

        // Pile signals on project B.
        Alert::factory()->count(3)->create([
            'project_id' => $b->id,
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
        ]);
        Website::factory()->create([
            'project_id' => $b->id,
            'status' => WebsiteStatus::Down->value,
        ]);
        Host::factory()->create([
            'project_id' => $b->id,
            'status' => HostStatus::Offline->value,
        ]);

        $this->assertSame(100, $this->compute($a));
    }

    public function test_full_deduction_stack_calculates_correctly(): void
    {
        // Sanity check that all signal classes combine without
        // double-counting. 100 - 30 (crit alert) - 15 (warn alert)
        // - 20 (website down) - 10 (website slow) - 15 (host offline)
        // - 10 (container unhealthy) - 20 (failed deploy) = -20 → 0.
        $project = Project::factory()->create();
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
        ]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Warning->value,
            'status' => AlertStatus::Open->value,
        ]);
        Website::factory()->create([
            'project_id' => $project->id,
            'status' => WebsiteStatus::Down->value,
        ]);
        Website::factory()->create([
            'project_id' => $project->id,
            'status' => WebsiteStatus::Slow->value,
        ]);
        $host = Host::factory()->create([
            'project_id' => $project->id,
            'status' => HostStatus::Offline->value,
        ]);
        Container::factory()->create([
            'host_id' => $host->id,
            'project_id' => $project->id,
            'health_status' => 'unhealthy',
        ]);
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'default_branch' => 'main',
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $repo->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Failure->value,
            'head_branch' => 'main',
            'run_completed_at' => Carbon::now()->subHours(2),
        ]);

        $this->assertSame(0, $this->compute($project));
    }
}
