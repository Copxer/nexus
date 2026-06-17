<?php

namespace App\Domain\GitHub\Jobs;

use App\Domain\GitHub\WebhookHandlers\IssuesWebhookHandler;
use App\Domain\GitHub\WebhookHandlers\PullRequestWebhookHandler;
use App\Domain\GitHub\WebhookHandlers\PushWebhookHandler;
use App\Domain\GitHub\WebhookHandlers\ReleaseWebhookHandler;
use App\Domain\GitHub\WebhookHandlers\WorkflowRunWebhookHandler;
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
 *   received → ... → failed (handler threw; `failed()` records the
 *                            terminal state with `error_message` after
 *                            `$tries` exhausted)
 *
 * Each handler is the small, single-purpose concrete class — see
 * `App\Domain\GitHub\WebhookHandlers\*`. Returning a status enum lets
 * us distinguish "we ran but the inputs weren't ready" (Skipped) from
 * "we ran successfully" (Processed) without tunneling exceptions.
 *
 * Spec 037 — `$tries = 3` per §18.3 ("webhook job failure: retry 3
 * times, then mark failed"). The in-job throw becomes the retry
 * signal; `failed()` is the terminal-state recorder. The delivery
 * row's payload + signature persist for forensic audit and manual
 * retry via `/settings/webhook-deliveries`.
 */
class ProcessGitHubWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Spec 037 — 3 attempts per §18.3. */
    public int $tries = 3;

    public function __construct(public readonly int $deliveryId) {}

    /** Spec 037 — quick retry, then a longer cool-off. */
    public function backoff(): array
    {
        return [10, 60];
    }

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
            Log::error('GitHub webhook processing — handler threw', [
                'delivery_id' => $delivery->id,
                'event' => $delivery->event,
                'action' => $delivery->action,
                'attempt' => $this->attempts(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            // Rethrow so the retry pipeline runs. `failed()` is the
            // terminal recorder. Mid-flight status stays `received`
            // so the UI shows "Retrying…" until tries are exhausted.
            throw $e;
        }
    }

    /**
     * Spec 037 — terminal-failure handler. Persists `Failed` with the
     * exception message once the retry pipeline gives up. The
     * delivery row's payload + signature are preserved for forensic
     * audit + manual retry via `/settings/webhook-deliveries`.
     */
    public function failed(Throwable $e): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::Failed->value,
            'error_message' => $e->getMessage(),
            'processed_at' => now(),
        ])->save();
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
            'workflow_run' => app(WorkflowRunWebhookHandler::class),
            'push' => app(PushWebhookHandler::class),
            'release' => app(ReleaseWebhookHandler::class),
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
