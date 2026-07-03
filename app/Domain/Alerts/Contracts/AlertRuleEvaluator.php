<?php

namespace App\Domain\Alerts\Contracts;

use App\Domain\Alerts\DataTransferObjects\AlertRuleEvaluation;
use App\Models\AlertRule;

/**
 * §6.5 Strategy pattern — one implementation per `AlertRuleKind`.
 *
 * Evaluators are pure computations: they read from the database +
 * queue + logs, apply the rule's config threshold, and return an
 * `AlertRuleEvaluation`. They never mutate the rule row and never
 * dispatch the trigger action themselves — the scheduled job holds
 * both responsibilities so evaluator state stays isolated.
 */
interface AlertRuleEvaluator
{
    public function evaluate(AlertRule $rule): AlertRuleEvaluation;
}
