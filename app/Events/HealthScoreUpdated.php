<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time pulse emitted whenever `RefreshProjectHealthScoreAction`
 * persists a changed score (spec 033). Dispatched only when the new
 * score differs from the stored one, so a no-op recompute doesn't
 * churn Echo subscribers.
 *
 * Broadcasts on `users.{ownerUserId}.dashboard` — a new channel
 * introduced in 033 that 035 will reuse for `HeatmapUpdated`. The
 * payload carries the resolved band string so the frontend doesn't
 * have to recompute the §14.2 threshold table.
 *
 * `ShouldBroadcastNow` + `ShouldDispatchAfterCommit` match the rest
 * of the Phase 7 / 8 event vocabulary: the broadcast fires once the
 * surrounding transaction commits, and the Reverb HTTP call is
 * synchronous so the listener doesn't queue an intermediate job.
 */
class HealthScoreUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $projectId,
        public readonly ?int $ownerUserId,
        public readonly int $score,
        public readonly string $band,
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
            new PrivateChannel("users.{$this->ownerUserId}.dashboard"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'project_id' => $this->projectId,
            'health_score' => $this->score,
            'band' => $this->band,
        ];
    }

    public function broadcastAs(): string
    {
        return 'HealthScoreUpdated';
    }
}
