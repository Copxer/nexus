<?php

namespace App\Domain\Observability;

/**
 * Spec 038 — single source of truth for system-health alert
 * thresholds. Used by both `EvaluateSystemHealthJob` (decides which
 * alerts to fire) and `GetSystemHealthQuery` (decides which tone the
 * Settings card paints). Keeping them aligned prevents the card
 * from reading "warning" while no alert has fired (or vice versa).
 *
 * Magic numbers are §17-driven defaults. A future polish spec could
 * make these user-tunable.
 */
class SystemHealthThresholds
{
    public const QUEUE_BACKLOG_WARNING = 100;

    public const QUEUE_BACKLOG_CRITICAL = 500;

    public const QUEUE_FAILURES_5M_WARN = 5;

    public const QUEUE_FAILURES_5M_CRIT = 20;

    public const WEBHOOK_FAILRATE_WARN_PCT = 20.0;

    public const WEBHOOK_FAILRATE_CRIT_PCT = 50.0;

    public const WEBHOOK_MIN_SAMPLE = 5;

    public const GITHUB_RATE_REMAINING_WARN = 100;

    public const GITHUB_RATE_REMAINING_CRIT = 20;

    public const AGENT_AUTH_FAILURES_5M_WARN = 10;

    public const AGENT_AUTH_FAILURES_5M_CRIT = 50;
}
