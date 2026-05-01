<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time pulse emitted whenever a `website_checks` row is persisted
 * (spec 025). The Vue `Pages/Monitoring/Websites/Show` page subscribes
 * via Echo and triggers a partial Inertia reload of the website /
 * summary / checks props on receipt — server-side query logic
 * re-applies naturally, so we never replicate filter or aggregation
 * logic in JS.
 *
 * Spec 024's transition events (`website.down` / `website.up`) ride
 * the activity feed via `ActivityEventCreated` and surface on the
 * AppLayout right rail. This event is **on top of that** — it fires
 * for every check (steady-state runs included) so the per-website
 * Show page reflects every probe in realtime, not just transitions.
 *
 * Pre-resolved owner id in the constructor (mirrors spec 021's
 * `WorkflowRunUpserted` decision) — avoids the broadcaster lazy-
 * loading the website / project relations during fan-out.
 *
 * `ShouldBroadcastNow` so the broadcast hits Reverb synchronously —
 * matches the rest of the event family.
 */
class WebsiteCheckRecorded implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $checkId,
        public readonly int $websiteId,
        public readonly ?int $ownerUserId,
    ) {}

    /**
     * Broadcast on the project owner's private monitoring channel.
     * `users.{userId}.monitoring` is authorized in `routes/channels.php`.
     *
     * Returns no channels when the owner can't be resolved (orphan
     * project, system-emitted check) — Laravel skips the publish.
     *
     * **Channel choice trade-off:** a per-user channel means the Show
     * page receives pulses for ALL of the user's monitors and filters
     * client-side by `website_id`. A per-website channel
     * (`websites.{id}.checks`) would eliminate the filter but adds
     * channel-auth proliferation and subscription churn on navigation.
     * Phase-1 picks the per-user channel for simplicity. Revisit if
     * monitor counts cross ~1k or check intervals drop below 30s.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if ($this->ownerUserId === null) {
            return [];
        }

        return [
            new PrivateChannel("users.{$this->ownerUserId}.monitoring"),
        ];
    }

    /**
     * Light-weight pulse — the client uses this as a trigger, not as
     * the source of truth. The Show page partial-reloads the website
     * + summary + checks props after receiving any pulse for its own
     * website id.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'check_id' => $this->checkId,
            'website_id' => $this->websiteId,
        ];
    }

    /**
     * Stable event name for Echo subscribers:
     * `Echo.private('users.{id}.monitoring').listen('.WebsiteCheckRecorded', ...)`.
     */
    public function broadcastAs(): string
    {
        return 'WebsiteCheckRecorded';
    }
}
