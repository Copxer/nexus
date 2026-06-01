<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time pulse emitted whenever `TriggerAlertAction` inserts a new
 * Alert row (spec 032). The Vue `Pages/Alerts/Index` page subscribes
 * via Echo and partial-reloads its `alerts` + `filters` + `filterOptions`
 * props on receipt; the TopBar bell partial-reloads the shared
 * `alerts.activeCount` prop so the badge updates across every page.
 *
 * The idempotent re-trigger path in `TriggerAlertAction` (steady-state
 * `last_seen_at` bump) does **not** dispatch — only fresh inserts do.
 * The rail already carries the original `alert.triggered` activity
 * event; a re-fire would just spam the toast.
 *
 * Pre-resolved owner id in the constructor (mirrors spec 028's
 * `HostTelemetryRecorded`) — avoids the broadcaster lazy-loading the
 * alert / project relations during fan-out.
 *
 * `ShouldBroadcastNow` so the publish hits Reverb synchronously.
 * `ShouldDispatchAfterCommit` defends against any future caller that
 * wraps the action in an outer transaction — the broadcast then still
 * waits for the outermost commit before firing.
 */
class AlertTriggered implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $alertId,
        public readonly ?int $ownerUserId,
    ) {}

    /**
     * Broadcast on the alert owner's private alerts channel.
     * `users.{userId}.alerts` is authorized in `routes/channels.php`.
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
            new PrivateChannel("users.{$this->ownerUserId}.alerts"),
        ];
    }

    /**
     * Light-weight pulse — the client uses this as a trigger, not as
     * the source of truth. The Alerts page partial-reloads its row
     * set after receiving any pulse; the TopBar partial-reloads the
     * `alerts` shared prop for the badge count.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'alert_id' => $this->alertId,
        ];
    }

    /**
     * Stable event name for Echo subscribers:
     * `Echo.private('users.{id}.alerts').listen('.AlertTriggered', ...)`.
     */
    public function broadcastAs(): string
    {
        return 'AlertTriggered';
    }
}
