<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time pulse emitted whenever a host's agent posts telemetry
 * (spec 028). The Vue `Pages/Monitoring/Hosts/Show` page subscribes
 * via Echo and triggers a partial Inertia reload of the host /
 * telemetry props on receipt — server-side query logic re-applies
 * naturally, so we never replicate aggregation logic in JS.
 *
 * Pre-resolved owner id in the constructor (mirrors spec 025's
 * `WebsiteCheckRecorded`) — avoids the broadcaster lazy-loading the
 * host / project relations during fan-out.
 *
 * `ShouldBroadcastNow` so the broadcast hits Reverb synchronously —
 * matches the rest of the event family. `ShouldDispatchAfterCommit`
 * defends against any future caller that wraps the ingest action in an
 * outer transaction: the broadcast then still waits for the outermost
 * commit before it fires, so the Show page never partial-reloads ahead
 * of the write.
 */
class HostTelemetryRecorded implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $hostId,
        public readonly ?int $ownerUserId,
    ) {}

    /**
     * Broadcast on the host owner's private hosts channel.
     * `users.{userId}.hosts` is authorized in `routes/channels.php`.
     *
     * Returns no channels when the owner can't be resolved (orphan
     * project) — Laravel skips the publish.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if ($this->ownerUserId === null) {
            return [];
        }

        return [
            new PrivateChannel("users.{$this->ownerUserId}.hosts"),
        ];
    }

    /**
     * Light-weight pulse — the client uses this as a trigger, not as
     * the source of truth. The Show page partial-reloads its host +
     * telemetry props after receiving any pulse for its own host id.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'host_id' => $this->hostId,
        ];
    }

    /**
     * Stable event name for Echo subscribers:
     * `Echo.private('users.{id}.hosts').listen('.HostTelemetryRecorded', ...)`.
     */
    public function broadcastAs(): string
    {
        return 'HostTelemetryRecorded';
    }
}
