<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\Probes\WebsiteProbeResult;
use App\Enums\WebsiteCheckStatus;
use App\Enums\WebsiteStatus;
use App\Models\Website;
use App\Models\WebsiteCheck;

/**
 * Persistence half of the probe pipeline. Given a `Website` + a
 * `WebsiteProbeResult` from `RunWebsiteProbeAction`, this:
 *
 *   1. Inserts a `WebsiteCheck` row carrying the probe's outcome.
 *   2. Mirrors the result onto `Website.{status,last_checked_at}`.
 *   3. Bumps `last_success_at` (Up/Slow) or `last_failure_at`
 *      (Down/Error) so the index page can show "last good" / "last
 *      bad" without scanning the checks table.
 *
 * Returns the persisted `WebsiteCheck` so callers (manual probe
 * controller, future scheduler job) can flash it back to the user
 * without an extra round-trip.
 *
 * Activity-event creation on status transitions deliberately lives
 * in spec 024 — that's where status transitions are interesting,
 * since manual probes are user-triggered and don't need a separate
 * notification surface.
 */
class RecordWebsiteCheckAction
{
    public function execute(Website $website, WebsiteProbeResult $result): WebsiteCheck
    {
        $checkedAt = now();

        $check = WebsiteCheck::query()->create([
            'website_id' => $website->id,
            'status' => $result->status->value,
            'http_status_code' => $result->httpStatusCode,
            'response_time_ms' => $result->responseTimeMs,
            'error_message' => $result->errorMessage,
            'checked_at' => $checkedAt,
        ]);

        $updates = [
            'status' => $this->parentStatusFor($result->status)->value,
            'last_checked_at' => $checkedAt,
        ];

        if ($this->isSuccessful($result->status)) {
            $updates['last_success_at'] = $checkedAt;
        } else {
            $updates['last_failure_at'] = $checkedAt;
        }

        $website->forceFill($updates)->save();

        return $check;
    }

    /**
     * `WebsiteCheckStatus` and `WebsiteStatus` differ only by the
     * `pending` value (parent only). Map 1:1 by name.
     */
    private function parentStatusFor(WebsiteCheckStatus $checkStatus): WebsiteStatus
    {
        return match ($checkStatus) {
            WebsiteCheckStatus::Up => WebsiteStatus::Up,
            WebsiteCheckStatus::Down => WebsiteStatus::Down,
            WebsiteCheckStatus::Slow => WebsiteStatus::Slow,
            WebsiteCheckStatus::Error => WebsiteStatus::Error,
        };
    }

    /** `Slow` is treated as a successful run for `last_success_at`. */
    private function isSuccessful(WebsiteCheckStatus $status): bool
    {
        return $status === WebsiteCheckStatus::Up
            || $status === WebsiteCheckStatus::Slow;
    }
}
