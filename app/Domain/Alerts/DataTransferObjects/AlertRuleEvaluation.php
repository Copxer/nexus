<?php

namespace App\Domain\Alerts\DataTransferObjects;

/**
 * Spec 046 — result of running a single `AlertRuleEvaluator`.
 *
 * `triggered: false` → the job records `last_evaluated_at` and moves on.
 * `triggered: true`  → the job dispatches `TriggerAlertAction` with
 * the payload fields carried on this DTO. `metadata` is a small
 * structured bag surfaced on the alert row for debug + inclusion in
 * the outbound notification payload.
 */
final class AlertRuleEvaluation
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public readonly bool $triggered,
        public readonly string $title = '',
        public readonly ?string $description = null,
        public readonly array $metadata = [],
    ) {}

    public static function quiet(): self
    {
        return new self(triggered: false);
    }
}
