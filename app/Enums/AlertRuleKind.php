<?php

namespace App\Enums;

/**
 * Spec 046 — kind of metric-driven alert rule. Each case maps to a
 * distinct `AlertRuleEvaluator` implementation. Naming mirrors the
 * existing alert `type` conventions (`website.down`, `host.offline`)
 * so the Deliveries tab reads consistently.
 */
enum AlertRuleKind: string
{
    case QueueBacklogTrend = 'queue.backlog_trend';
    case DeployFrequencyDrop = 'deploy_frequency_drop';
    case UptimeSlope = 'uptime_slope';
    case DeployFailureRate = 'deploy_failure_rate';

    public function label(): string
    {
        return match ($this) {
            self::QueueBacklogTrend => 'Queue backlog trending up',
            self::DeployFrequencyDrop => 'Deploy frequency dropped',
            self::UptimeSlope => 'Uptime trending down',
            self::DeployFailureRate => 'Deploy failure rate high',
        };
    }
}
