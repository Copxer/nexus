<?php

namespace App\Domain\Alerts\Evaluators;

use App\Domain\Alerts\Contracts\AlertRuleEvaluator;
use App\Domain\Alerts\DataTransferObjects\AlertRuleEvaluation;
use App\Enums\WebsiteCheckStatus;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\WebsiteCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Spec 046 — trigger when uptime is trending down.
 *
 * Simple two-half compare: uptime% for the first half of the window
 * vs. uptime% for the second half. Slope = second - first (percentage
 * points per window). A negative slope crossing
 * `config.slope_threshold` fires the rule.
 *
 * A linear-regression fit is on the roadmap (spec §Open questions);
 * the simpler two-half compare gets shipped first because it's easy
 * to reason about and covers 95% of the interesting cases.
 */
class UptimeSlopeEvaluator implements AlertRuleEvaluator
{
    public function evaluate(AlertRule $rule): AlertRuleEvaluation
    {
        $windowHours = (int) ($rule->config['window_hours'] ?? 24);
        // Negative float — slope threshold in percentage points across the window.
        // Default -1.0 means "any drop of >1 percentage point across the window".
        $slopeThreshold = (float) ($rule->config['slope_threshold'] ?? -1.0);

        $projectIds = Project::query()
            ->where('owner_user_id', $rule->user_id)
            ->pluck('id');

        if ($projectIds->isEmpty()) {
            return AlertRuleEvaluation::quiet();
        }

        $now = Carbon::now();
        $mid = $now->copy()->subHours(intdiv($windowHours, 2));
        $start = $now->copy()->subHours($windowHours);

        $firstHalf = $this->uptimePercent($projectIds, $start, $mid);
        $secondHalf = $this->uptimePercent($projectIds, $mid, $now);

        if ($firstHalf === null || $secondHalf === null) {
            return AlertRuleEvaluation::quiet();
        }

        $slope = $secondHalf - $firstHalf;
        if ($slope > $slopeThreshold) {
            return AlertRuleEvaluation::quiet();
        }

        return new AlertRuleEvaluation(
            triggered: true,
            title: sprintf(
                'Uptime slope %.1fpp (%.1f%% → %.1f%%)',
                $slope,
                $firstHalf,
                $secondHalf,
            ),
            description: "Uptime dropped over the last {$windowHours}h.",
            metadata: [
                'rule_id' => $rule->id,
                'first_half_percent' => $firstHalf,
                'second_half_percent' => $secondHalf,
                'slope_percentage_points' => $slope,
                'window_hours' => $windowHours,
            ],
        );
    }

    private function uptimePercent(
        Collection $projectIds,
        Carbon $start,
        Carbon $end,
    ): ?float {
        $total = WebsiteCheck::query()
            ->join('websites', 'website_checks.website_id', '=', 'websites.id')
            ->whereIn('websites.project_id', $projectIds)
            ->whereBetween('website_checks.checked_at', [$start, $end])
            ->count();

        if ($total === 0) {
            return null;
        }

        $up = WebsiteCheck::query()
            ->join('websites', 'website_checks.website_id', '=', 'websites.id')
            ->whereIn('websites.project_id', $projectIds)
            ->whereBetween('website_checks.checked_at', [$start, $end])
            ->where('website_checks.status', WebsiteCheckStatus::Up->value)
            ->count();

        return ($up / $total) * 100;
    }
}
