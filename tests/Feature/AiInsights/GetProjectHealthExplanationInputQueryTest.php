<?php

namespace Tests\Feature\AiInsights;

use App\Domain\AiInsights\Queries\GetProjectHealthExplanationInputQuery;
use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Enums\RepositorySyncStatus;
use App\Enums\WebsiteStatus;
use App\Enums\WorkflowRunConclusion;
use App\Enums\WorkflowRunStatus;
use App\Models\Alert;
use App\Models\Container;
use App\Models\Host;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GetProjectHealthExplanationInputQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_scopes_health_snapshot_to_owned_project(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        $this->assertNull(app(GetProjectHealthExplanationInputQuery::class)->execute($otherUser, $project));
        $this->assertSame($project->id, app(GetProjectHealthExplanationInputQuery::class)->execute($user, $project)['project']['id']);
    }

    public function test_includes_health_score_drivers_with_bounded_samples(): void
    {
        Carbon::setTestNow('2026-07-21 12:00:00');

        $user = User::factory()->create();
        $project = Project::factory()->create([
            'owner_user_id' => $user->id,
            'name' => 'Checkout Platform',
            'status' => ProjectStatus::Active->value,
            'priority' => ProjectPriority::Critical->value,
            'environment' => 'production',
            'health_score' => 28,
            'last_activity_at' => '2026-07-21 09:00:00',
        ]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'nexus/checkout-platform',
            'default_branch' => 'main',
            'sync_status' => RepositorySyncStatus::Failed->value,
            'sync_failed_at' => '2026-07-21 08:00:00',
        ]);
        $otherProject = Project::factory()->create(['owner_user_id' => $user->id]);
        $otherRepository = Repository::factory()->create(['project_id' => $otherProject->id]);

        Alert::factory()->count(GetProjectHealthExplanationInputQuery::SAMPLE_LIMIT + 2)->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
            'title' => 'Production site down',
            'last_seen_at' => '2026-07-21 11:00:00',
        ]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Deployment->value,
            'severity' => AlertSeverity::Warning->value,
            'status' => AlertStatus::Acknowledged->value,
        ]);
        Alert::factory()->create([
            'project_id' => $otherProject->id,
            'source' => AlertSource::Website->value,
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
            'title' => 'Other project leak',
        ]);
        WorkflowRun::factory()->count(GetProjectHealthExplanationInputQuery::SAMPLE_LIMIT + 2)->create([
            'repository_id' => $repository->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Failure->value,
            'head_branch' => 'main',
            'run_completed_at' => '2026-07-21 10:00:00',
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $otherRepository->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Failure->value,
            'head_branch' => 'main',
            'run_completed_at' => '2026-07-21 10:00:00',
        ]);
        Website::factory()->count(GetProjectHealthExplanationInputQuery::SAMPLE_LIMIT + 2)->create([
            'project_id' => $project->id,
            'name' => 'Marketing site',
            'url' => 'https://example.test/private?token=secret',
            'status' => WebsiteStatus::Down->value,
            'last_checked_at' => '2026-07-21 10:00:00',
            'last_failure_at' => '2026-07-21 10:00:00',
        ]);
        Host::factory()->offline()->count(GetProjectHealthExplanationInputQuery::SAMPLE_LIMIT + 2)->create([
            'project_id' => $project->id,
            'endpoint_url' => 'https://agent.example.test/webhook',
        ]);
        $host = Host::factory()->online()->create(['project_id' => $project->id]);
        Container::factory()->count(GetProjectHealthExplanationInputQuery::SAMPLE_LIMIT + 2)->create([
            'host_id' => $host->id,
            'project_id' => $project->id,
            'health_status' => 'unhealthy',
            'last_seen_at' => '2026-07-21 10:00:00',
            'labels' => ['SECRET_TOKEN' => 'abc123'],
        ]);

        $snapshot = app(GetProjectHealthExplanationInputQuery::class)->execute($user, $project);

        $this->assertSame('project-health-explanation-input-v1', $snapshot['snapshot_version']);
        $this->assertSame(28, $snapshot['project']['health_score']);
        $this->assertSame('critical', $snapshot['project']['health_band']);
        $this->assertSame(8, $snapshot['drivers']['alerts']['active_total']);
        $this->assertSame(7, $snapshot['drivers']['alerts']['active_by_severity']['critical']);
        $this->assertCount(GetProjectHealthExplanationInputQuery::SAMPLE_LIMIT, $snapshot['drivers']['alerts']['sample']);
        $this->assertSame(7, $snapshot['drivers']['deployments']['failed_default_branch_last_24h']);
        $this->assertCount(GetProjectHealthExplanationInputQuery::SAMPLE_LIMIT, $snapshot['drivers']['deployments']['failed_workflows_sample']);
        $this->assertSame(7, $snapshot['drivers']['websites']['by_status']['down']);
        $this->assertCount(GetProjectHealthExplanationInputQuery::SAMPLE_LIMIT, $snapshot['drivers']['websites']['problem_sample']);
        $this->assertSame(7, $snapshot['drivers']['hosts']['offline_count']);
        $this->assertCount(GetProjectHealthExplanationInputQuery::SAMPLE_LIMIT, $snapshot['drivers']['hosts']['problem_sample']);
        $this->assertSame(7, $snapshot['drivers']['containers']['unhealthy_count']);
        $this->assertCount(GetProjectHealthExplanationInputQuery::SAMPLE_LIMIT, $snapshot['drivers']['containers']['problem_sample']);
        $this->assertSame(1, $snapshot['drivers']['github_sync']['repositories_total']);
        $this->assertSame('nexus/checkout-platform', $snapshot['drivers']['github_sync']['failed_repositories_sample'][0]['full_name']);
    }

    public function test_excludes_sensitive_urls_tokens_logs_and_secrets_from_snapshot(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        Repository::factory()->create([
            'project_id' => $project->id,
            'html_url' => 'https://github.com/acme/private?access_token=secret',
            'sync_error' => 'raw logs include TOKEN',
            'sync_failed_at' => now(),
        ]);
        Website::factory()->create([
            'project_id' => $project->id,
            'name' => 'SECRET_KEY=abc123 marketing',
            'url' => 'https://example.test?token=secret',
            'status' => WebsiteStatus::Down->value,
        ]);
        Host::factory()->offline()->create([
            'project_id' => $project->id,
            'endpoint_url' => 'https://agent.example.test/webhook',
            'metadata' => ['secret' => 'abc'],
        ]);
        $host = Host::factory()->online()->create(['project_id' => $project->id]);
        Container::factory()->create([
            'host_id' => $host->id,
            'project_id' => $project->id,
            'health_status' => 'unhealthy',
            'labels' => ['ACCESS_TOKEN' => 'abc123'],
        ]);

        $encoded = json_encode(app(GetProjectHealthExplanationInputQuery::class)->execute($user, $project), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('access_token', $encoded);
        $this->assertStringNotContainsString('TOKEN', $encoded);
        $this->assertStringNotContainsString('token=secret', $encoded);
        $this->assertStringNotContainsString('webhook', $encoded);
        $this->assertStringNotContainsString('ACCESS_TOKEN', $encoded);
        $this->assertStringNotContainsString('SECRET_KEY', $encoded);
        $this->assertStringNotContainsString('abc123', $encoded);
        $this->assertStringNotContainsString('raw logs', $encoded);
        $this->assertStringNotContainsString('sync_error', $encoded);
    }
}
