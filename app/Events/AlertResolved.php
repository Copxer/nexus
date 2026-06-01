<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time pulse emitted whenever `ResolveAlertAction` closes an open
 * or acknowledged Alert row (spec 032). Dispatched per row inside the
 * action's foreach so a multi-row resolve (rare in practice — Trigger's
 * idempotency guarantees at most one open + acknowledged row per
 * `(source, source_id, type)`) still surfaces every closure.
 *
 * Same surface as `AlertTriggered`: pre-resolved owner id,
 * lightweight `{alert_id}` payload, `users.{id}.alerts` private
 * channel, `ShouldBroadcastNow` + `ShouldDispatchAfterCommit`.
 */
class AlertResolved implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $alertId,
        public readonly ?int $ownerUserId,
    ) {}

    /**
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
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'alert_id' => $this->alertId,
        ];
    }

    public function broadcastAs(): string
    {
        return 'AlertResolved';
    }
}
