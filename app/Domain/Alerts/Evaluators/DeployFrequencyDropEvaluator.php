<?php

namespace App\Domain\Alerts\Evaluators;

use App\Domain\Alerts\Contracts\AlertRuleEvaluator;
use App\Domain\Alerts\DataTransferObjects\AlertRuleEvaluation;
use App\Enums\WorkflowRunConclusion;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\WorkflowRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Spec 046 — trigger when the user's deploy-frequency drops by more
 * than `config.drop_percent` (comparing current vs. prior window).
 *
 * `config.window_days` = window size for each of the two periods.
 * `config.drop_percent` = triggering threshold (positive integer).
 *
 * Deploys = successful default-branch workflow runs (mirrors
 * spec 022's `deployments.success` counting rule) scoped to the
 * user's projects.
 *
 * When either window has zero deploys the evaluator stays quiet —
 * a fresh install with no history shouldn't page anyone.
 */
class DeployFrequencyDropEvaluator implements AlertRuleEvaluator
{
    public function evaluate(AlertRule $rule): AlertRuleEvaluation
    {
        $windowDays = (int) ($rule->config['window_days'] ?? 7);
        $dropThreshold = (int) ($rule->config['drop_percent'] ?? 50);

        $projectIds = Project::query()
            ->where('owner_user_id', $rule->user_id)
            ->pluck('id');

        if ($projectIds->isEmpty()) {
            return AlertRuleEvaluation::quiet();
        }

        $now = Carbon::now();
        $currentStart = $now->copy()->subDays($windowDays);
        $priorStart = $now->copy()->subDays($windowDays * 2);

        $current = $this->countDeploysBetween($projectIds, $currentStart, $now);
        $prior = $this->countDeploysBetween($projectIds, $priorStart, $currentStart);

        if ($prior === 0) {
            return AlertRuleEvaluation::quiet();
        }

        $dropPct = (int) round((($prior - $current) / $prior) * 100);
        if ($dropPct < $dropThreshold) {
            return AlertRuleEvaluation::quiet();
        }

        return new AlertRuleEvaluation(
            triggered: true,
            title: "Deploys down {$dropPct}% ({$current} vs {$prior})",
            description: "Deploy frequency dropped {$dropPct}% over the last {$windowDays}d vs the prior {$windowDays}d.",
            metadata: [
                'rule_id' => $rule->id,
                'current_deploys' => $current,
                'prior_deploys' => $prior,
                'drop_percent' => $dropPct,
                'window_days' => $windowDays,
            ],
        );
    }

    private function countDeploysBetween(
        Collection $projectIds,
        Carbon $start,
        Carbon $end,
    ): int {
        return WorkflowRun::query()
            ->join('repositories', 'workflow_runs.repository_id', '=', 'repositories.id')
            ->whereIn('repositories.project_id', $projectIds)
            ->whereColumn('workflow_runs.head_branch', 'repositories.default_branch')
            ->where('workflow_runs.conclusion', WorkflowRunConclusion::Success->value)
            ->whereBetween('workflow_runs.run_completed_at', [$start, $end])
            ->count('workflow_runs.id');
    }
}
