<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time pulse emitted whenever a `workflow_runs` row is upserted
 * via the GitHub webhook (spec 021). The Vue `Pages/Deployments/Index`
 * page subscribes via Echo and triggers a partial Inertia reload on
 * receipt — server-side filter logic re-applies naturally, so we
 * never need to replicate the filter graph in JS.
 *
 * Deliberately NOT dispatched by `SyncRepositoryWorkflowRunsAction` —
 * a REST backfill can land 100 rows in a tick and would flood the
 * channel. Live deliveries are the only "appear" surface the user
 * expects.
 *
 * Payload is intentionally minimal (`run_id`, `repository_id`) — the
 * client uses it as a trigger, not as the source of truth for the
 * row contents. The partial reload re-fetches the authoritative,
 * filter-aware list from the controller.
 *
 * `ShouldBroadcastNow` so the broadcast hits Reverb synchronously,
 * matching spec 019's `ActivityEventCreated` pattern.
 *
 * Owner resolution lives at the call site (`WorkflowRunWebhookHandler`)
 * — the handler already has the loaded `$repository->project->owner`
 * from earlier work, so passing the int avoids the event class lazy-
 * loading the relations and re-querying every dispatch.
 */
class WorkflowRunUpserted implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $runId,
        public readonly int $repositoryId,
        public readonly ?int $ownerUserId,
    ) {}

    /**
     * Broadcast on the project owner's private deployments channel.
     * `users.{userId}.deployments` is authorized in `routes/channels.php`.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if ($this->ownerUserId === null) {
            return [];
        }

        return [
            new PrivateChannel("users.{$this->ownerUserId}.deployments"),
        ];
    }

    /**
     * Light-weight pulse — the client uses this as a trigger, not as
     * the source of truth. The page partial-reloads the deployments
     * prop after receiving any pulse.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'repository_id' => $this->repositoryId,
        ];
    }

    /**
     * Stable event name for Echo subscribers:
     * `Echo.private('users.{id}.deployments').listen('.WorkflowRunUpserted', ...)`.
     */
    public function broadcastAs(): string
    {
        return 'WorkflowRunUpserted';
    }
}
