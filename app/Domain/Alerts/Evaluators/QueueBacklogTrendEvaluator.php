<?php

namespace App\Domain\Alerts\Evaluators;

use App\Domain\Alerts\Contracts\AlertRuleEvaluator;
use App\Domain\Alerts\DataTransferObjects\AlertRuleEvaluation;
use App\Models\AlertRule;
use Illuminate\Support\Facades\Queue;

/**
 * Spec 046 — trigger when the current queue backlog exceeds a
 * user-configured threshold. Simplified from a rolling-delta trend
 * because the current backlog is what an operator actually needs to
 * know about — the "trend" framing was the deferred phrasing;
 * absolute backlog is the actionable signal.
 *
 * `config.threshold_delta` = backlog count that trips the rule.
 * `config.window_minutes`  = surfaced in the payload as context
 * (which window the operator is asking about) but the current
 * implementation reads a point-in-time queue length. A future
 * refinement can persist snapshots and compare across the window.
 */
class QueueBacklogTrendEvaluator implements AlertRuleEvaluator
{
    public function evaluate(AlertRule $rule): AlertRuleEvaluation
    {
        $threshold = (int) ($rule->config['threshold_delta'] ?? 100);
        $window = (int) ($rule->config['window_minutes'] ?? 15);

        try {
            $current = Queue::size();
        } catch (\Throwable) {
            // Queue driver not reachable → not the rule's fault; stay quiet.
            return AlertRuleEvaluation::quiet();
        }

        if ($current < $threshold) {
            return AlertRuleEvaluation::quiet();
        }

        return new AlertRuleEvaluation(
            triggered: true,
            title: "Queue backlog {$current} ≥ threshold {$threshold}",
            description: "Backlog exceeded threshold. Window checked: {$window} min.",
            metadata: [
                'rule_id' => $rule->id,
                'current_backlog' => $current,
                'threshold' => $threshold,
                'window_minutes' => $window,
            ],
        );
    }
}
