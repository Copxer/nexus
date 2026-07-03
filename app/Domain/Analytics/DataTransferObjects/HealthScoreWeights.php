<?php

namespace App\Domain\Analytics\DataTransferObjects;

use App\Domain\Analytics\Actions\ComputeProjectHealthScoreAction;
use App\Models\ProjectHealthScoreWeightOverride;
use App\Models\User;

/**
 * Spec 046 — immutable value object carrying the resolved weights for a
 * health-score computation. `null` on any field falls back to the class
 * constant in `ComputeProjectHealthScoreAction`, so `defaults()` returns
 * an all-nulls instance meaning "use defaults for every signal."
 */
final class HealthScoreWeights
{
    public function __construct(
        public readonly ?int $deductAlertCritical,
        public readonly ?int $deductAlertWarning,
        public readonly ?int $deductDeployFailed,
        public readonly ?int $deductWebsiteSlow,
        public readonly ?int $deductWebsiteDown,
        public readonly ?int $deductHostOffline,
        public readonly ?int $deductContainerUnhealthy,
        public readonly ?int $deductGhSyncFailed,
    ) {}

    /** All-null instance — use every default. */
    public static function defaults(): self
    {
        return new self(null, null, null, null, null, null, null, null);
    }

    /**
     * Resolve the user's override row into a value object. If the user
     * has no row (or it exists with every column null), the returned
     * instance is functionally identical to `defaults()`.
     */
    public static function forUser(User $user): self
    {
        $row = ProjectHealthScoreWeightOverride::query()
            ->where('user_id', $user->id)
            ->first();

        if ($row === null) {
            return self::defaults();
        }

        return new self(
            deductAlertCritical: $row->deduct_alert_critical,
            deductAlertWarning: $row->deduct_alert_warning,
            deductDeployFailed: $row->deduct_deploy_failed,
            deductWebsiteSlow: $row->deduct_website_slow,
            deductWebsiteDown: $row->deduct_website_down,
            deductHostOffline: $row->deduct_host_offline,
            deductContainerUnhealthy: $row->deduct_container_unhealthy,
            deductGhSyncFailed: $row->deduct_gh_sync_failed,
        );
    }

    public function alertCritical(): int
    {
        return $this->deductAlertCritical ?? ComputeProjectHealthScoreAction::DEDUCT_ALERT_CRITICAL;
    }

    public function alertWarning(): int
    {
        return $this->deductAlertWarning ?? ComputeProjectHealthScoreAction::DEDUCT_ALERT_WARNING;
    }

    public function deployFailed(): int
    {
        return $this->deductDeployFailed ?? ComputeProjectHealthScoreAction::DEDUCT_DEPLOY_FAILED;
    }

    public function websiteSlow(): int
    {
        return $this->deductWebsiteSlow ?? ComputeProjectHealthScoreAction::DEDUCT_WEBSITE_SLOW;
    }

    public function websiteDown(): int
    {
        return $this->deductWebsiteDown ?? ComputeProjectHealthScoreAction::DEDUCT_WEBSITE_DOWN;
    }

    public function hostOffline(): int
    {
        return $this->deductHostOffline ?? ComputeProjectHealthScoreAction::DEDUCT_HOST_OFFLINE;
    }

    public function containerUnhealthy(): int
    {
        return $this->deductContainerUnhealthy ?? ComputeProjectHealthScoreAction::DEDUCT_CONTAINER_UNHEALTHY;
    }

    public function ghSyncFailed(): int
    {
        return $this->deductGhSyncFailed ?? ComputeProjectHealthScoreAction::DEDUCT_GH_SYNC_FAILED;
    }
}
