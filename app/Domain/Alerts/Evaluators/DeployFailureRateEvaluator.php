<?php

namespace App\Domain\Alerts\Evaluators;

use App\Domain\Alerts\Contracts\AlertRuleEvaluator;
use App\Domain\Alerts\DataTransferObjects\AlertRuleEvaluation;
use App\Enums\WorkflowRunConclusion;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\WorkflowRun;

/**
 * Spec 046 — trigger when the failure rate of the last `sample_size`
 * default-branch workflow runs exceeds `config.failure_rate_percent`.
 *
 * `config.sample_size`           = how many recent runs to look at
 * `config.failure_rate_percent`  = trigger threshold (integer 0..100)
 *
 * Stays quiet when the sample is smaller than the configured size —
 * a fresh install shouldn't be flagged as "failure rate high" just
 * because there aren't enough data points.
 */
class DeployFailureRateEvaluator implements AlertRuleEvaluator
{
    public function evaluate(AlertRule $rule): AlertRuleEvaluation
    {
        $sampleSize = (int) ($rule->config['sample_size'] ?? 10);
        $threshold = (int) ($rule->config['failure_rate_percent'] ?? 30);

        $projectIds = Project::query()
            ->where('owner_user_id', $rule->user_id)
            ->pluck('id');

        if ($projectIds->isEmpty()) {
            return AlertRuleEvaluation::quiet();
        }

        $recent = WorkflowRun::query()
            ->join('repositories', 'workflow_runs.repository_id', '=', 'repositories.id')
            ->whereIn('repositories.project_id', $projectIds)
            ->whereColumn('workflow_runs.head_branch', 'repositories.default_branch')
            ->whereNotNull('workflow_runs.run_completed_at')
            ->orderByDesc('workflow_runs.run_completed_at')
            ->limit($sampleSize)
            ->get(['workflow_runs.id', 'workflow_runs.conclusion']);

        if ($recent->count() < $sampleSize) {
            return AlertRuleEvaluation::quiet();
        }

        $failures = $recent->filter(
            fn (WorkflowRun $r): bool => $r->conclusion === WorkflowRunConclusion::Failure,
        )->count();

        $ratePct = (int) round(($failures / $sampleSize) * 100);
        if ($ratePct < $threshold) {
            return AlertRuleEvaluation::quiet();
        }

        return new AlertRuleEvaluation(
            triggered: true,
            title: "Deploy failure rate {$ratePct}% ({$failures}/{$sampleSize})",
            description: "Deploy failure rate of the last {$sampleSize} runs crossed {$threshold}%.",
            metadata: [
                'rule_id' => $rule->id,
                'failures' => $failures,
                'sample_size' => $sampleSize,
                'rate_percent' => $ratePct,
                'threshold_percent' => $threshold,
            ],
        );
    }
}
