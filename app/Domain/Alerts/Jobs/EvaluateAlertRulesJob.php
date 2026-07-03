<?php

namespace App\Domain\Alerts\Jobs;

use App\Domain\Alerts\Actions\TriggerAlertAction;
use App\Domain\Alerts\Contracts\AlertRuleEvaluator;
use App\Domain\Alerts\Evaluators\DeployFailureRateEvaluator;
use App\Domain\Alerts\Evaluators\DeployFrequencyDropEvaluator;
use App\Domain\Alerts\Evaluators\QueueBacklogTrendEvaluator;
use App\Domain\Alerts\Evaluators\UptimeSlopeEvaluator;
use App\Enums\AlertRuleKind;
use App\Enums\AlertSource;
use App\Models\AlertRule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Spec 046 — scheduled every 5 min. Iterates enabled `AlertRule`
 * rows, delegates each to its `AlertRuleEvaluator` implementation
 * via a container-mapped strategy, and dispatches
 * `TriggerAlertAction` on evaluation truth. `last_evaluated_at`
 * updates on every tick; `last_triggered_at` updates on a fresh
 * trigger + gates the cool-down window.
 *
 * Fired alerts carry `AlertSource::System` + `type = "rule.{kind}"`
 * + `source_id = rule.id` — spec 042's delivery layer then fans them
 * out to configured notification channels.
 */
class EvaluateAlertRulesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** Lock across the full tick so overlapping schedules skip a run. */
    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'evaluate-alert-rules';
    }

    public function handle(TriggerAlertAction $trigger): void
    {
        // chunkById orders by `id ASC`; the per-rule updates below only
        // touch `last_evaluated_at` / `last_triggered_at`, never `id`,
        // so the cursor is safe across ticks — no re-visit risk.
        AlertRule::query()
            ->where('enabled', true)
            ->chunkById(100, function ($rules) use ($trigger): void {
                foreach ($rules as $rule) {
                    $this->evaluateOne($rule, $trigger);
                }
            });
    }

    private function evaluateOne(AlertRule $rule, TriggerAlertAction $trigger): void
    {
        $now = Carbon::now();

        // Cool-down gate ahead of the evaluator so a stuck condition
        // doesn't pay the (potentially heavy — SQL joins on
        // workflow_runs / website_checks) evaluator cost every tick.
        // Still advance `last_evaluated_at` so the UI reads "last
        // evaluated N min ago" instead of a stale timestamp.
        if ($rule->isInCoolDown()) {
            $rule->forceFill(['last_evaluated_at' => $now])->save();

            return;
        }

        try {
            $evaluator = $this->evaluatorFor($rule->kind);
        } catch (Throwable $e) {
            Log::warning('AlertRule evaluator resolution failed', [
                'rule_id' => $rule->id,
                'kind' => $rule->kind?->value,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            $evaluation = $evaluator->evaluate($rule);
        } catch (Throwable $e) {
            Log::warning('AlertRule evaluation threw', [
                'rule_id' => $rule->id,
                'kind' => $rule->kind?->value,
                'error' => $e->getMessage(),
            ]);
            $rule->forceFill(['last_evaluated_at' => $now])->save();

            return;
        }

        $rule->forceFill(['last_evaluated_at' => $now])->save();

        if (! $evaluation->triggered) {
            return;
        }

        $trigger->execute([
            'project_id' => null,
            'source' => AlertSource::System,
            'source_id' => $rule->id,
            'type' => 'rule.'.$rule->kind->value,
            'severity' => $rule->severity,
            'title' => $evaluation->title,
            'description' => $evaluation->description,
            'metadata' => $evaluation->metadata,
        ]);

        $rule->forceFill(['last_triggered_at' => $now])->save();
    }

    public function evaluatorFor(AlertRuleKind $kind): AlertRuleEvaluator
    {
        return match ($kind) {
            AlertRuleKind::QueueBacklogTrend => app(QueueBacklogTrendEvaluator::class),
            AlertRuleKind::DeployFrequencyDrop => app(DeployFrequencyDropEvaluator::class),
            AlertRuleKind::UptimeSlope => app(UptimeSlopeEvaluator::class),
            AlertRuleKind::DeployFailureRate => app(DeployFailureRateEvaluator::class),
        };
    }
}
