<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alerts\Evaluators\DeployFailureRateEvaluator;
use App\Domain\Alerts\Evaluators\DeployFrequencyDropEvaluator;
use App\Domain\Alerts\Evaluators\QueueBacklogTrendEvaluator;
use App\Domain\Alerts\Evaluators\UptimeSlopeEvaluator;
use App\Enums\AlertRuleKind;
use App\Enums\WebsiteCheckStatus;
use App\Enums\WorkflowRunConclusion;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Spec 046 — one focused test per evaluator: quiet baseline +
 * triggering condition. The scheduled job test
 * (`EvaluateAlertRulesJobTest`) covers dispatch + cool-down + persist.
 */
class AlertRuleEvaluatorsTest extends TestCase
{
    use RefreshDatabase;

    // ── QueueBacklogTrend ─────────────────────────────────────

    public function test_queue_backlog_evaluator_quiet_when_backlog_below_threshold(): void
    {
        $rule = AlertRule::factory()
            ->ofKind(AlertRuleKind::QueueBacklogTrend, ['threshold_delta' => 999999])
            ->create();

        $result = app(QueueBacklogTrendEvaluator::class)->evaluate($rule);

        $this->assertFalse($result->triggered);
    }

    public function test_queue_backlog_evaluator_triggers_when_backlog_at_or_above_threshold(): void
    {
        // Threshold of zero — any current backlog count meets it.
        $rule = AlertRule::factory()
            ->ofKind(AlertRuleKind::QueueBacklogTrend, ['threshold_delta' => 0])
            ->create();

        $result = app(QueueBacklogTrendEvaluator::class)->evaluate($rule);

        $this->assertTrue($result->triggered);
        $this->assertArrayHasKey('current_backlog', $result->metadata);
    }

    // ── DeployFrequencyDrop ───────────────────────────────────

    public function test_deploy_frequency_drop_evaluator_quiet_without_prior_baseline(): void
    {
        $user = User::factory()->create();
        $rule = AlertRule::factory()
            ->for($user)
            ->ofKind(AlertRuleKind::DeployFrequencyDrop)
            ->create();

        // User has projects but no workflow runs → prior-window count = 0 → quiet.
        Project::factory()->create(['owner_user_id' => $user->id]);

        $result = app(DeployFrequencyDropEvaluator::class)->evaluate($rule);

        $this->assertFalse($result->triggered);
    }

    public function test_deploy_frequency_drop_evaluator_triggers_on_50pct_drop(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'default_branch' => 'main',
        ]);

        // Prior window: 10 successful deploys. Current window: 3.
        // Drop = 70% > 50% threshold → triggers.
        $priorMid = Carbon::now()->subDays(10);
        $currentMid = Carbon::now()->subDays(3);

        WorkflowRun::factory()->count(10)->create([
            'repository_id' => $repo->id,
            'head_branch' => 'main',
            'conclusion' => WorkflowRunConclusion::Success->value,
            'run_completed_at' => $priorMid,
        ]);
        WorkflowRun::factory()->count(3)->create([
            'repository_id' => $repo->id,
            'head_branch' => 'main',
            'conclusion' => WorkflowRunConclusion::Success->value,
            'run_completed_at' => $currentMid,
        ]);

        $rule = AlertRule::factory()
            ->for($user)
            ->ofKind(AlertRuleKind::DeployFrequencyDrop, ['window_days' => 7, 'drop_percent' => 50])
            ->create();

        $result = app(DeployFrequencyDropEvaluator::class)->evaluate($rule);

        $this->assertTrue($result->triggered);
        $this->assertGreaterThanOrEqual(50, $result->metadata['drop_percent']);
    }

    // ── UptimeSlope ───────────────────────────────────────────

    public function test_uptime_slope_evaluator_quiet_without_checks(): void
    {
        $user = User::factory()->create();
        $rule = AlertRule::factory()
            ->for($user)
            ->ofKind(AlertRuleKind::UptimeSlope)
            ->create();

        $result = app(UptimeSlopeEvaluator::class)->evaluate($rule);

        $this->assertFalse($result->triggered);
    }

    public function test_uptime_slope_evaluator_triggers_when_uptime_drops(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $website = Website::factory()->create(['project_id' => $project->id]);

        // First half of 24h: 10 successful checks. Second half: 2 up, 8 down.
        // First-half uptime = 100%; second-half = 20%; slope = -80pp.
        $firstHalfWhen = Carbon::now()->subHours(20);
        $secondHalfWhen = Carbon::now()->subHours(2);

        for ($i = 0; $i < 10; $i++) {
            WebsiteCheck::factory()->create([
                'website_id' => $website->id,
                'status' => WebsiteCheckStatus::Up->value,
                'checked_at' => $firstHalfWhen,
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            WebsiteCheck::factory()->create([
                'website_id' => $website->id,
                'status' => WebsiteCheckStatus::Up->value,
                'checked_at' => $secondHalfWhen,
            ]);
        }
        for ($i = 0; $i < 8; $i++) {
            WebsiteCheck::factory()->create([
                'website_id' => $website->id,
                'status' => WebsiteCheckStatus::Down->value,
                'checked_at' => $secondHalfWhen,
            ]);
        }

        $rule = AlertRule::factory()
            ->for($user)
            ->ofKind(AlertRuleKind::UptimeSlope, ['window_hours' => 24, 'slope_threshold' => -1.0])
            ->create();

        $result = app(UptimeSlopeEvaluator::class)->evaluate($rule);

        $this->assertTrue($result->triggered);
        $this->assertLessThan(-1.0, $result->metadata['slope_percentage_points']);
    }

    // ── DeployFailureRate ─────────────────────────────────────

    public function test_deploy_failure_rate_evaluator_quiet_when_sample_smaller_than_configured(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'default_branch' => 'main',
        ]);

        // Only 2 completed runs — sample_size=10 → quiet.
        WorkflowRun::factory()->count(2)->create([
            'repository_id' => $repo->id,
            'head_branch' => 'main',
            'conclusion' => WorkflowRunConclusion::Failure->value,
            'run_completed_at' => Carbon::now(),
        ]);

        $rule = AlertRule::factory()
            ->for($user)
            ->ofKind(AlertRuleKind::DeployFailureRate, ['sample_size' => 10, 'failure_rate_percent' => 30])
            ->create();

        $result = app(DeployFailureRateEvaluator::class)->evaluate($rule);

        $this->assertFalse($result->triggered);
    }

    public function test_deploy_failure_rate_evaluator_triggers_when_50pct_of_sample_failed(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repo = Repository::factory()->create([
            'project_id' => $project->id,
            'default_branch' => 'main',
        ]);

        // 10 recent runs: 5 fail, 5 succeed → 50% rate > 30% threshold.
        $when = Carbon::now();
        WorkflowRun::factory()->count(5)->create([
            'repository_id' => $repo->id,
            'head_branch' => 'main',
            'conclusion' => WorkflowRunConclusion::Failure->value,
            'run_completed_at' => $when,
        ]);
        WorkflowRun::factory()->count(5)->create([
            'repository_id' => $repo->id,
            'head_branch' => 'main',
            'conclusion' => WorkflowRunConclusion::Success->value,
            'run_completed_at' => $when->copy()->subMinute(),
        ]);

        $rule = AlertRule::factory()
            ->for($user)
            ->ofKind(AlertRuleKind::DeployFailureRate, ['sample_size' => 10, 'failure_rate_percent' => 30])
            ->create();

        $result = app(DeployFailureRateEvaluator::class)->evaluate($rule);

        $this->assertTrue($result->triggered);
        $this->assertSame(50, $result->metadata['rate_percent']);
    }
}
