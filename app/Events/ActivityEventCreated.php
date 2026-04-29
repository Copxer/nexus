<?php

namespace App\Events;

use App\Domain\Activity\ActivityEventPresenter;
use App\Models\ActivityEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
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
class ActivityEventCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly ActivityEvent $activityEvent) {}

    /**
     * Broadcast on the project owner's private activity channel.
     * `users.{userId}.activity` is authorized in `routes/channels.php`.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $repository = $this->activityEvent->repository;

        // No repository → no project → no recipient. Silently drop the
        // broadcast (the row still exists in the DB; spec 018's
        // page-load query already filters these out for every user).
        if ($repository === null) {
            return [];
        }

        $project = $repository->project;

        if ($project === null || $project->owner_user_id === null) {
            return [];
        }

        return [
            new PrivateChannel("users.{$project->owner_user_id}.activity"),
        ];
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
