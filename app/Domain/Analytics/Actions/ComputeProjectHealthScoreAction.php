<?php

namespace App\Domain\Analytics\Actions;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\HostStatus;
use App\Enums\WebsiteStatus;
use App\Enums\WorkflowRunConclusion;
use App\Models\Alert;
use App\Models\Container;
use App\Models\Host;
use App\Models\Project;
use App\Models\Website;
use App\Models\WorkflowRun;
use Illuminate\Support\Carbon;

/**
 * Pure-read computation of a project's health score (spec 033,
 * roadmap §14.2). Starts at 100, applies weighted deductions across
 * the signals Phases 4–7 already collect, clamps to `[0, 100]`,
 * returns the integer. No DB writes — callers persist.
 *
 * Deductions stack **per occurrence**, not per signal-class. A
 * project with three active critical alerts deducts 90, not 30. This
 * matches the "reflects real system signals" framing in §Phase 8:
 * three outages is meaningfully worse than one, and the clamp at 0
 * handles catastrophic stacking naturally. Future tuning can swap to
 * a binary "any X exists" mode by changing the `count()` queries to
 * `exists()` casts, but the weight constants are the right pivot
 * point either way.
 *
 * Signals **not** wired yet (and therefore contributing zero):
 *
 * - `-10 for stale PR over 7 days` — needs Phase 4 PR webhook
 *   ingestion that doesn't exist; deferred per Phase 8 README.
 * - `-5 for failed GitHub sync` — the `github.sync.failed` activity
 *   event type isn't emitted today (no grep hits in `app/`). When
 *   ingestion lands, add a `gitHubSyncDeductions()` method here
 *   without changing the weight constant.
 *
 * Both gaps are documented in spec 033's open questions; the formula
 * under-deducts by at most 15 in the worst case, which is acceptable
 * for shipping the rest.
 */
class ComputeProjectHealthScoreAction
{
    public const BASELINE = 100;

    public const DEDUCT_ALERT_CRITICAL = 30;

    public const DEDUCT_ALERT_WARNING = 15;

    public const DEDUCT_DEPLOY_FAILED = 20;

    public const DEDUCT_WEBSITE_SLOW = 10;

    public const DEDUCT_WEBSITE_DOWN = 20;

    public const DEDUCT_HOST_OFFLINE = 15;

    public const DEDUCT_CONTAINER_UNHEALTHY = 10;

    public const DEDUCT_GH_SYNC_FAILED = 5;

    public function execute(Project $project): int
    {
        $score = self::BASELINE
            - $this->alertDeductions($project)
            - $this->websiteDeductions($project)
            - $this->hostDeductions($project)
            - $this->containerDeductions($project)
            - $this->deploymentDeductions($project);

        return (int) max(0, min(100, $score));
    }

    private function alertDeductions(Project $project): int
    {
        $active = [AlertStatus::Open->value, AlertStatus::Acknowledged->value];

        $critical = Alert::query()
            ->where('project_id', $project->id)
            ->whereIn('status', $active)
            ->where('severity', AlertSeverity::Critical->value)
            ->count();

        $warning = Alert::query()
            ->where('project_id', $project->id)
            ->whereIn('status', $active)
            ->where('severity', AlertSeverity::Warning->value)
            ->count();

        return ($critical * self::DEDUCT_ALERT_CRITICAL)
            + ($warning * self::DEDUCT_ALERT_WARNING);
    }

    private function websiteDeductions(Project $project): int
    {
        $down = Website::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [
                WebsiteStatus::Down->value,
                WebsiteStatus::Error->value,
            ])
            ->count();

        $slow = Website::query()
            ->where('project_id', $project->id)
            ->where('status', WebsiteStatus::Slow->value)
            ->count();

        return ($down * self::DEDUCT_WEBSITE_DOWN)
            + ($slow * self::DEDUCT_WEBSITE_SLOW);
    }

    private function hostDeductions(Project $project): int
    {
        $offline = Host::query()
            ->where('project_id', $project->id)
            ->whereNull('archived_at')
            ->where('status', HostStatus::Offline->value)
            ->count();

        return $offline * self::DEDUCT_HOST_OFFLINE;
    }

    private function containerDeductions(Project $project): int
    {
        // Docker daemon's `health_status` is opt-in: not every image
        // declares a HEALTHCHECK. Only count containers explicitly
        // reporting `unhealthy` — `null` / `starting` / `healthy` /
        // anything else doesn't deduct.
        $unhealthy = Container::query()
            ->where('project_id', $project->id)
            ->where('health_status', 'unhealthy')
            ->count();

        return $unhealthy * self::DEDUCT_CONTAINER_UNHEALTHY;
    }

    private function deploymentDeductions(Project $project): int
    {
        // "Failed deployment" in §14.2 maps to a failed default-branch
        // workflow run in the last 24h. We constrain to the run's
        // repository's `default_branch` because feature-branch
        // failures don't count against the project (matching spec
        // 030's promotion rule for `workflow.failed` alerts).
        $since = Carbon::now()->subDay();

        $failed = WorkflowRun::query()
            ->join('repositories', 'workflow_runs.repository_id', '=', 'repositories.id')
            ->where('repositories.project_id', $project->id)
            ->whereColumn('workflow_runs.head_branch', 'repositories.default_branch')
            ->where('workflow_runs.conclusion', WorkflowRunConclusion::Failure->value)
            ->where('workflow_runs.run_completed_at', '>', $since)
            ->count('workflow_runs.id');

        return $failed * self::DEDUCT_DEPLOY_FAILED;
    }
}
