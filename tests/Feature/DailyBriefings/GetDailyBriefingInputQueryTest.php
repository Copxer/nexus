<?php

namespace Tests\Feature\DailyBriefings;

use App\Domain\DailyBriefings\Queries\GetDailyBriefingInputQuery;
use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\GithubIssueState;
use App\Enums\GithubPullRequestState;
use App\Enums\WorkflowRunConclusion;
use App\Enums\WorkflowRunStatus;
use App\Models\ActivityEvent;
use App\Models\Alert;
use App\Models\GithubIssue;
use App\Models\GithubPullRequest;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetDailyBriefingInputQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_scopes_snapshot_to_user_projects_and_requested_project_filter(): void
    {
        $user = User::factory()->create();
        $includedProject = Project::factory()->create(['owner_user_id' => $user->id, 'name' => 'Included']);
        $excludedOwnedProject = Project::factory()->create(['owner_user_id' => $user->id, 'name' => 'Excluded owned']);
        $otherProject = Project::factory()->create(['name' => 'Other user']);
        $includedRepo = Repository::factory()->create(['project_id' => $includedProject->id]);
        $excludedRepo = Repository::factory()->create(['project_id' => $excludedOwnedProject->id]);
        $otherRepo = Repository::factory()->create(['project_id' => $otherProject->id]);

        GithubIssue::factory()->create([
            'repository_id' => $includedRepo->id,
            'state' => GithubIssueState::Open->value,
            'title' => 'Visible issue',
            'created_at_github' => '2026-07-20 12:00:00',
            'closed_at_github' => null,
        ]);
        GithubIssue::factory()->create([
            'repository_id' => $excludedRepo->id,
            'state' => GithubIssueState::Open->value,
            'title' => 'Filtered issue',
            'created_at_github' => '2026-07-20 12:00:00',
            'closed_at_github' => null,
        ]);
        GithubIssue::factory()->create([
            'repository_id' => $otherRepo->id,
            'state' => GithubIssueState::Open->value,
            'title' => 'Leaked issue',
            'created_at_github' => '2026-07-20 12:00:00',
            'closed_at_github' => null,
        ]);

        $snapshot = app(GetDailyBriefingInputQuery::class)->execute(
            $user,
            '2026-07-20',
            'UTC',
            [$includedProject->id, $otherProject->id],
        );

        $this->assertSame(1, $snapshot['projects']['total']);
        $this->assertSame(1, $snapshot['github']['issues']['opened']);
        $this->assertSame(['Visible issue'], array_column($snapshot['github']['work_items'], 'title'));
    }

    public function test_converts_local_timezone_day_to_utc_query_window(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repository = Repository::factory()->create(['project_id' => $project->id]);

        GithubIssue::factory()->create([
            'repository_id' => $repository->id,
            'state' => GithubIssueState::Open->value,
            'title' => 'Before local day',
            'created_at_github' => '2026-07-20 03:59:59',
            'closed_at_github' => null,
        ]);
        GithubIssue::factory()->create([
            'repository_id' => $repository->id,
            'state' => GithubIssueState::Open->value,
            'title' => 'Inside local day',
            'created_at_github' => '2026-07-20 04:00:00',
            'closed_at_github' => null,
        ]);
        GithubIssue::factory()->create([
            'repository_id' => $repository->id,
            'state' => GithubIssueState::Open->value,
            'title' => 'End boundary',
            'created_at_github' => '2026-07-21 04:00:00',
            'closed_at_github' => null,
        ]);

        $snapshot = app(GetDailyBriefingInputQuery::class)->execute($user, '2026-07-20', 'America/New_York');

        $this->assertSame('2026-07-20T04:00:00+00:00', $snapshot['window']['starts_at_utc']);
        $this->assertSame('2026-07-21T04:00:00+00:00', $snapshot['window']['ends_at_utc']);
        $this->assertSame(1, $snapshot['github']['issues']['opened']);
        $this->assertSame(['Inside local day'], array_column($snapshot['github']['work_items'], 'title'));
    }

    public function test_counts_snapshot_sections_and_caps_samples(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id, 'health_score' => 42]);
        $repository = Repository::factory()->create(['project_id' => $project->id]);

        GithubIssue::factory()->create([
            'repository_id' => $repository->id,
            'state' => GithubIssueState::Closed->value,
            'created_at_github' => '2026-07-20 09:00:00',
            'closed_at_github' => '2026-07-20 10:00:00',
        ]);
        GithubPullRequest::factory()->create([
            'repository_id' => $repository->id,
            'state' => GithubPullRequestState::Merged->value,
            'merged' => true,
            'created_at_github' => '2026-07-20 09:30:00',
            'closed_at_github' => '2026-07-20 11:00:00',
            'merged_at' => '2026-07-20 11:00:00',
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $repository->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Success->value,
            'run_completed_at' => '2026-07-20 12:00:00',
        ]);
        WorkflowRun::factory()->create([
            'repository_id' => $repository->id,
            'status' => WorkflowRunStatus::Completed->value,
            'conclusion' => WorkflowRunConclusion::Failure->value,
            'run_completed_at' => '2026-07-20 13:00:00',
        ]);

        Alert::factory()->count(GetDailyBriefingInputQuery::ALERTS_LIMIT + 2)->create([
            'project_id' => $project->id,
            'source' => AlertSource::Deployment->value,
            'severity' => AlertSeverity::Warning->value,
            'status' => AlertStatus::Open->value,
            'triggered_at' => '2026-07-20 14:00:00',
            'last_seen_at' => '2026-07-20 14:00:00',
        ]);
        ActivityEvent::factory()->count(GetDailyBriefingInputQuery::ACTIVITY_EVENTS_LIMIT + 2)->create([
            'repository_id' => $repository->id,
            'occurred_at' => '2026-07-20 15:00:00',
        ]);

        $snapshot = app(GetDailyBriefingInputQuery::class)->execute($user, '2026-07-20', 'UTC');

        $this->assertSame(1, $snapshot['github']['issues']['opened']);
        $this->assertSame(1, $snapshot['github']['issues']['closed']);
        $this->assertSame(1, $snapshot['github']['pull_requests']['merged']);
        $this->assertSame(1, $snapshot['deployments']['successful']);
        $this->assertSame(1, $snapshot['deployments']['failed']);
        $this->assertSame(GetDailyBriefingInputQuery::ALERTS_LIMIT + 2, $snapshot['alerts']['triggered']);
        $this->assertCount(GetDailyBriefingInputQuery::ALERTS_LIMIT, $snapshot['alerts']['sample']);
        $this->assertSame(GetDailyBriefingInputQuery::ACTIVITY_EVENTS_LIMIT + 2, $snapshot['activity']['total']);
        $this->assertCount(GetDailyBriefingInputQuery::ACTIVITY_EVENTS_LIMIT, $snapshot['activity']['top_events']);
        $this->assertSame($project->id, $snapshot['health']['worst_projects'][0]['id']);
    }
}
