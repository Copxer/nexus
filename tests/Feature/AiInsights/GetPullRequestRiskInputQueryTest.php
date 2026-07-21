<?php

namespace Tests\Feature\AiInsights;

use App\Domain\AiInsights\Queries\GetPullRequestRiskInputQuery;
use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\GithubPullRequestState;
use App\Enums\ProjectPriority;
use App\Enums\RepositorySyncStatus;
use App\Enums\WorkflowRunConclusion;
use App\Enums\WorkflowRunStatus;
use App\Models\Alert;
use App\Models\GithubPullRequest;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GetPullRequestRiskInputQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_scopes_pr_snapshot_to_owned_project(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repository = Repository::factory()->create(['project_id' => $project->id]);
        $pullRequest = GithubPullRequest::factory()->create(['repository_id' => $repository->id]);

        $this->assertNull(app(GetPullRequestRiskInputQuery::class)->execute($otherUser, $pullRequest));
        $this->assertSame($pullRequest->id, app(GetPullRequestRiskInputQuery::class)->execute($user, $pullRequest)['pull_request']['id']);
    }

    public function test_includes_bounded_pr_facts_workflows_alerts_and_project_signals(): void
    {
        Carbon::setTestNow('2026-07-21 12:00:00');

        $user = User::factory()->create();
        $project = Project::factory()->create([
            'owner_user_id' => $user->id,
            'name' => 'Billing API',
            'priority' => ProjectPriority::High->value,
            'health_score' => 42,
        ]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'nexus/billing-api',
            'default_branch' => 'main',
            'language' => 'PHP',
            'sync_status' => RepositorySyncStatus::Synced->value,
            'prs_sync_status' => RepositorySyncStatus::Synced->value,
            'workflow_runs_sync_status' => RepositorySyncStatus::Failed->value,
        ]);
        $pullRequest = GithubPullRequest::factory()->create([
            'repository_id' => $repository->id,
            'number' => 123,
            'title' => 'Refactor checkout workflow',
            'body_preview' => str_repeat('safe context ', 80).'SECRET_TOKEN=abc123',
            'state' => GithubPullRequestState::Open->value,
            'author_login' => 'octo-dev',
            'base_branch' => 'main',
            'head_branch' => 'feature/checkout-risk',
            'draft' => true,
            'merged' => false,
            'additions' => 320,
            'deletions' => 40,
            'changed_files' => 12,
            'comments_count' => 4,
            'review_comments_count' => 2,
            'created_at_github' => '2026-07-11 12:00:00',
            'updated_at_github' => '2026-07-12 12:00:00',
        ]);

        WorkflowRun::factory()->count(GetPullRequestRiskInputQuery::FAILED_WORKFLOWS_LIMIT + 2)->create([
            'repository_id' => $repository->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Failure->value,
            'head_branch' => 'feature/checkout-risk',
            'run_completed_at' => '2026-07-21 10:00:00',
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $repository->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Failure->value,
            'head_branch' => 'unrelated-branch',
            'run_completed_at' => '2026-07-21 11:00:00',
        ]);
        Alert::factory()->count(GetPullRequestRiskInputQuery::ALERTS_LIMIT + 2)->create([
            'project_id' => $project->id,
            'source' => AlertSource::Deployment->value,
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
            'title' => 'CI failed repeatedly',
            'last_seen_at' => '2026-07-21 09:00:00',
        ]);
        Alert::factory()->resolved()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
            'title' => 'Resolved alert should not appear',
        ]);

        $snapshot = app(GetPullRequestRiskInputQuery::class)->execute($user, $pullRequest);

        $this->assertSame('pr-risk-input-v1', $snapshot['snapshot_version']);
        $this->assertSame('high', $snapshot['project']['priority']);
        $this->assertSame(42, $snapshot['project']['health_score']);
        $this->assertSame('warning', $snapshot['project']['health_band']);
        $this->assertSame('nexus/billing-api', $snapshot['repository']['full_name']);
        $this->assertSame('failed', $snapshot['repository']['workflow_runs_sync_status']);
        $this->assertSame(123, $snapshot['pull_request']['number']);
        $this->assertArrayNotHasKey('body_preview', $snapshot['pull_request']);
        $this->assertSame(10, $snapshot['pull_request']['age_days']);
        $this->assertTrue($snapshot['pull_request']['stale']);
        $this->assertCount(GetPullRequestRiskInputQuery::FAILED_WORKFLOWS_LIMIT, $snapshot['recent_failed_workflows']);
        $this->assertSame(['feature/checkout-risk'], array_values(array_unique(array_column($snapshot['recent_failed_workflows'], 'head_branch'))));
        $this->assertCount(GetPullRequestRiskInputQuery::ALERTS_LIMIT, $snapshot['active_alerts']);
        $this->assertNotContains('Resolved alert should not appear', array_column($snapshot['active_alerts'], 'title'));
    }

    public function test_excludes_sensitive_raw_fields_from_snapshot(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'html_url' => 'https://github.com/acme/private-repo?access_token=secret',
            'sync_error' => 'webhook_url=https://hooks.example.test/token',
            'prs_sync_error' => 'raw logs include ACCESS_TOKEN',
        ]);
        $pullRequest = GithubPullRequest::factory()->create([
            'repository_id' => $repository->id,
            'body_preview' => '{"SECRET_TOKEN":"abc123"} SECRET_TOKEN=abc123 bounded body preview only',
        ]);

        $encoded = json_encode(app(GetPullRequestRiskInputQuery::class)->execute($user, $pullRequest), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('access_token', $encoded);
        $this->assertStringNotContainsString('webhook_url', $encoded);
        $this->assertStringNotContainsString('ACCESS_TOKEN', $encoded);
        $this->assertStringNotContainsString('SECRET_TOKEN', $encoded);
        $this->assertStringNotContainsString('abc123', $encoded);
        $this->assertStringNotContainsString('raw logs', $encoded);
        $this->assertStringNotContainsString('sync_error', $encoded);
        $this->assertArrayNotHasKey('body_preview', json_decode($encoded, true, flags: JSON_THROW_ON_ERROR)['pull_request']);
    }
}
