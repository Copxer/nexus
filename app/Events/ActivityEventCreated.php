<?php

namespace App\Events;

use App\Domain\Activity\ActivityEventPresenter;
use App\Models\ActivityEvent;
use App\Models\Host;
use App\Models\Website;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time announcement that an `ActivityEvent` row was just inserted
 * (spec 019). `CreateActivityEventAction` dispatches this on every
 * insert; Echo subscribers prepend the payload into their local feed
 * without a page refresh.
 *
 * Implements `ShouldBroadcastNow` so the broadcast hits Reverb
 * synchronously — page-load fresh from spec 018 stays the cold start;
 * this fills the gap between two refreshes. If the row's repository
 * has no owning project (system-emitted events, future), we no-op
 * `broadcastOn()` and Laravel skips the publish.
 */
class ActivityEventCreated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly ActivityEvent $activityEvent) {}

    /**
     * Broadcast on the project owner's private activity channel.
     * `users.{userId}.activity` is authorized in `routes/channels.php`.
     *
     * Three scoping paths:
     *   1. **Repo-scoped events** (spec 017's webhook handlers,
     *      spec 020's deployments) — channel resolves through
     *      `repository → project → owner_user_id`.
     *   2. **Monitoring-scoped events** (spec 024 — `source: monitoring`,
     *      `repository_id` is null) — channel resolves through
     *      `metadata.website_id → website → project → owner_user_id`.
     *   3. **Hosts-scoped events** (spec 029 — `source: hosts`,
     *      `repository_id` is null) — channel resolves through
     *      `metadata.host_id → host → project → owner_user_id`.
     *
     * If neither path resolves to an owner the broadcast no-ops; the
     * row still exists in the DB, and `RecentActivityForUserQuery`
     * picks it up on the next page-load refresh.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $ownerUserId = $this->resolveOwnerUserId();

        if ($ownerUserId === null) {
            return [];
        }

        return [
            new PrivateChannel("users.{$ownerUserId}.activity"),
        ];
    }

    /**
     * Resolve the broadcast recipient user id. Returns null when the
     * event isn't tied to any owner (orphan rows, system events
     * without a website / repository).
     */
    private function resolveOwnerUserId(): ?int
    {
        // Repo-scoped path (specs 017 / 020).
        if ($this->activityEvent->repository_id !== null) {
            $repository = $this->activityEvent->repository;
            $project = $repository?->project;

            return $project?->owner_user_id;
        }

        // Monitoring-scoped path (spec 024).
        if ($this->activityEvent->source === 'monitoring') {
            $metadata = $this->activityEvent->metadata ?? [];
            $websiteId = $metadata['website_id'] ?? null;

            if ($websiteId === null) {
                return null;
            }

            $website = Website::query()->find($websiteId);

            return $website?->project?->owner_user_id;
        }

        // Hosts-scoped path (spec 029).
        if ($this->activityEvent->source === 'hosts') {
            $metadata = $this->activityEvent->metadata ?? [];
            $hostId = $metadata['host_id'] ?? null;

            if ($hostId === null) {
                return null;
            }

            $host = Host::query()->find($hostId);

            return $host?->project?->owner_user_id;
        }

        return null;
    }

    /**
     * Wire-shape sent to the Echo client. Mirrors the TS
     * `ActivityEvent` interface in `resources/js/types/index.d.ts`
     * via the shared `ActivityEventPresenter`.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ActivityEventPresenter::present($this->activityEvent);
    }

    /**
     * Stable event name that Echo subscribes to:
     * `Echo.private('users.{id}.activity').listen('.ActivityEventCreated', ...)`.
     *
     * The leading dot tells Laravel to use this exact name instead of
     * the FQCN — keeps the JS side decoupled from PHP namespaces.
     */
    public function broadcastAs(): string
    {
        return 'ActivityEventCreated';
    }
}
