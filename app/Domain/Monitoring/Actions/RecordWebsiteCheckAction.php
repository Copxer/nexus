<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Domain\Monitoring\Probes\WebsiteProbeResult;
use App\Enums\ActivitySeverity;
use App\Enums\WebsiteCheckStatus;
use App\Enums\WebsiteStatus;
use App\Events\WebsiteCheckRecorded;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Support\Carbon;

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
 * controller, scheduler-driven `RunWebsiteCheckJob`) can flash it
 * back to the user without an extra round-trip.
 *
 * Spec 024: extended to emit `ActivityEvent`s on healthy↔failed
 * **category transitions only** — steady-state runs (Up→Up, Down→Down)
 * stay silent so the activity feed isn't flooded.
 *
 * `CreateActivityEventAction` (spec 017) dispatches
 * `ActivityEventCreated` (spec 019); spec 024 extended that broadcaster
 * to resolve a recipient channel for monitoring-source events via
 * `metadata.website_id → website → project → owner_user_id` (since
 * monitoring rows have `repository_id = null` and would otherwise
 * silently fail to broadcast). Realtime fan-out reaches the right rail.
 *
 * Spec 025: dispatches `WebsiteCheckRecorded` after every persisted
 * check (steady-state runs included, not just transitions) so the
 * per-website Show page reflects every probe in realtime — the
 * transition path above only fires on healthy↔failed swings, which
 * leaves "still up, response time changed" updates invisible.
 */
class RecordWebsiteCheckAction
{
    public function __construct(
        private readonly CreateActivityEventAction $createActivity,
    ) {}

    public function execute(Website $website, WebsiteProbeResult $result): WebsiteCheck
    {
        // Capture BEFORE the update so we can detect category swings.
        $previousStatus = $website->status;

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

        $this->maybeEmitTransitionActivity($website, $previousStatus, $result, $checkedAt);

        // Spec 025 — broadcast every persisted check so the Show page
        // reflects steady-state runs too. Pre-resolve the owner id
        // here so the event's broadcaster doesn't lazy-load
        // website→project relations during fan-out.
        $website->loadMissing('project:id,owner_user_id');
        WebsiteCheckRecorded::dispatch(
            $check->id,
            $website->id,
            $website->project?->owner_user_id,
        );

        return $check;
    }

    /**
     * Category transition detector. Three buckets:
     *   - Healthy   = Up | Slow
     *   - Failed    = Down | Error
     *   - Pending   = first-ever probe state (initial seed)
     *
     * Emits an activity event on:
     *   - Healthy → Failed     (incident — `website.down`, danger)
     *   - Pending → Failed     (incident on first probe — same shape)
     *   - Failed  → Healthy    (recovery — `website.up`, success)
     *
     * Steady-state (Healthy → Healthy, Failed → Failed) and the silent
     * Pending → Healthy first-probe-success path emit nothing — keeps
     * the activity feed signal-dense.
     */
    private function maybeEmitTransitionActivity(
        Website $website,
        ?WebsiteStatus $previousStatus,
        WebsiteProbeResult $result,
        Carbon $checkedAt,
    ): void {
        $previousCategory = $this->categoryFor($previousStatus);
        $currentCategory = $this->categoryForCheckStatus($result->status);

        if ($previousCategory === $currentCategory) {
            return; // Steady state — silent.
        }

        if ($previousCategory === 'pending' && $currentCategory === 'healthy') {
            return; // First probe + everything fine — uneventful.
        }

        if ($currentCategory === 'failed') {
            $this->createActivity->execute([
                'event_type' => 'website.down',
                'severity' => ActivitySeverity::Danger,
                'title' => "{$website->name} went down",
                'description' => $this->failureDescription($result),
                'occurred_at' => $checkedAt,
                'source' => 'monitoring',
                'metadata' => [
                    'website_id' => $website->id,
                    'url' => $website->url,
                    'http_status_code' => $result->httpStatusCode,
                    'error_message' => $result->errorMessage,
                ],
            ]);

            return;
        }

        // Failed → Healthy (the only remaining transition).
        $this->createActivity->execute([
            'event_type' => 'website.up',
            'severity' => ActivitySeverity::Success,
            'title' => "{$website->name} recovered",
            'description' => $result->responseTimeMs !== null
                ? "Up in {$result->responseTimeMs}ms"
                : 'Up',
            'occurred_at' => $checkedAt,
            'source' => 'monitoring',
            'metadata' => [
                'website_id' => $website->id,
                'url' => $website->url,
                'http_status_code' => $result->httpStatusCode,
                'response_time_ms' => $result->responseTimeMs,
            ],
        ]);
    }

    /**
     * Bucket a parent `WebsiteStatus` into healthy / failed / pending.
     * Null is treated as `pending` — defensive against a brand-new
     * row that bypassed the factory's default.
     *
     * @return 'healthy'|'failed'|'pending'
     */
    private function categoryFor(?WebsiteStatus $status): string
    {
        return match ($status) {
            WebsiteStatus::Up, WebsiteStatus::Slow => 'healthy',
            WebsiteStatus::Down, WebsiteStatus::Error => 'failed',
            null, WebsiteStatus::Pending => 'pending',
        };
    }

    /**
     * Bucket a `WebsiteCheckStatus` (the freshly-probed result) into
     * the same healthy / failed buckets. There's no `pending` here —
     * a recorded check always reflects an actual probe.
     *
     * @return 'healthy'|'failed'
     */
    private function categoryForCheckStatus(WebsiteCheckStatus $status): string
    {
        return match ($status) {
            WebsiteCheckStatus::Up, WebsiteCheckStatus::Slow => 'healthy',
            WebsiteCheckStatus::Down, WebsiteCheckStatus::Error => 'failed',
        };
    }

    /**
     * Human-readable failure context for the activity event description.
     * Prefer the captured error message (HTTP-layer body preview or
     * transport error) over a bare HTTP status.
     */
    private function failureDescription(WebsiteProbeResult $result): string
    {
        if ($result->errorMessage !== null && $result->errorMessage !== '') {
            return $result->errorMessage;
        }

        if ($result->httpStatusCode !== null) {
            return "HTTP {$result->httpStatusCode}";
        }

        return 'Probe failed';
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
