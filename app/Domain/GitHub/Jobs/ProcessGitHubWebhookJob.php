<?php

namespace App\Domain\GitHub\Jobs;

use App\Domain\GitHub\WebhookHandlers\IssuesWebhookHandler;
use App\Domain\GitHub\WebhookHandlers\PullRequestWebhookHandler;
use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Process a single `github_webhook_deliveries` row by routing the
 * stored payload to its event handler.
 *
 *   received → processed (handler ran successfully + returned `Processed`)
 *   received → skipped   (no handler for this event, or handler returned
 *                         `Skipped` because we don't have the local repo
 *                         yet — common when GitHub fires before import)
 *   received → failed    (handler threw; `error_message` carries the reason)
 *
 * Each handler is the small, single-purpose concrete class — see
 * `App\Domain\GitHub\WebhookHandlers\*`. Returning a status enum lets
 * us distinguish "we ran but the inputs weren't ready" (Skipped) from
 * "we ran successfully" (Processed) without tunneling exceptions.
 *
 * `tries = 1` matches spec 014/015/016 sync jobs. Replay is via the
 * delivery row's stored payload (a future spec adds a "Re-process"
 * action on top of that).
 */
class ProcessGitHubWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null) {
            // Row deleted between dispatch and run. No-op.
            return;
        }

        try {
            $status = $this->routeToHandler($delivery);

            $delivery->forceFill([
                'status' => $status->value,
                'processed_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            Log::error('GitHub webhook processing failed', [
                'delivery_id' => $delivery->id,
                'event' => $delivery->event,
                'action' => $delivery->action,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $delivery->forceFill([
                'status' => WebhookDeliveryStatus::Failed->value,
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ])->save();
        }
    }

    /**
     * Dispatch to the handler matching `delivery.event`. Unknown events
     * land as `Skipped` per §8.5 ("Log unknown events. Do not fail
     * silently") — we DO log, but we don't fail.
     */
    private function routeToHandler(WebhookDelivery $delivery): WebhookDeliveryStatus
    {
        $handler = match ($delivery->event) {
            'issues' => app(IssuesWebhookHandler::class),
            'pull_request' => app(PullRequestWebhookHandler::class),
            default => null,
        };

        if ($handler === null) {
            Log::info('GitHub webhook event has no handler', [
                'delivery_id' => $delivery->id,
                'event' => $delivery->event,
                'action' => $delivery->action,
            ]);

            $delivery->forceFill([
                'error_message' => "No handler registered for event `{$delivery->event}`.",
            ])->save();

            return WebhookDeliveryStatus::Skipped;
        }

        return $handler->handle($delivery);
    }
}
