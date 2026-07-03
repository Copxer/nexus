<?php

namespace App\Domain\Alerts\Support;

use App\Enums\AlertRuleKind;
use App\Enums\AlertSeverity;

/**
 * Spec 046 — static catalog of starter templates for the "Add rule"
 * picker. Reduces cold-start friction so an operator opening
 * `/settings/rules` with no rules configured has picks to react to,
 * not a blank form.
 */
class AlertRuleTemplate
{
    /**
     * @return array<int, array{
     *   id: string,
     *   name: string,
     *   kind: string,
     *   severity: string,
     *   config: array<string, mixed>,
     *   description: string,
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'id' => 'queue-backlog-100',
                'name' => 'Queue backlog above 100',
                'kind' => AlertRuleKind::QueueBacklogTrend->value,
                'severity' => AlertSeverity::Warning->value,
                'config' => ['window_minutes' => 15, 'threshold_delta' => 100],
                'description' => 'Warn when the pending queue backlog goes over 100 jobs.',
            ],
            [
                'id' => 'queue-backlog-500',
                'name' => 'Queue backlog critical (500)',
                'kind' => AlertRuleKind::QueueBacklogTrend->value,
                'severity' => AlertSeverity::Critical->value,
                'config' => ['window_minutes' => 15, 'threshold_delta' => 500],
                'description' => 'Page operators when the queue backlog crosses 500 jobs.',
            ],
            [
                'id' => 'deploy-frequency-drop-50',
                'name' => 'Deploy frequency dropped 50%',
                'kind' => AlertRuleKind::DeployFrequencyDrop->value,
                'severity' => AlertSeverity::Warning->value,
                'config' => ['window_days' => 7, 'drop_percent' => 50],
                'description' => 'Watch for a 50% drop in successful default-branch deploys week-over-week.',
            ],
            [
                'id' => 'uptime-slope-1pp',
                'name' => 'Uptime dropping >1pp/day',
                'kind' => AlertRuleKind::UptimeSlope->value,
                'severity' => AlertSeverity::Warning->value,
                'config' => ['window_hours' => 24, 'slope_threshold' => -1.0],
                'description' => 'Fire when uptime drops more than 1 percentage point across the last 24h.',
            ],
            [
                'id' => 'deploy-failure-30pct',
                'name' => 'Deploy failure rate >30%',
                'kind' => AlertRuleKind::DeployFailureRate->value,
                'severity' => AlertSeverity::Critical->value,
                'config' => ['sample_size' => 10, 'failure_rate_percent' => 30],
                'description' => 'Trigger when 3+ of the last 10 default-branch deploys failed.',
            ],
        ];
    }
}
