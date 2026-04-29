<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\GitHub\Actions\VerifyGitHubWebhookSignatureAction;
use App\Domain\GitHub\Jobs\ProcessGitHubWebhookJob;
use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives GitHub webhook POSTs at `/webhooks/github`.
 *
 *   1. Verify signature against the raw body. Bad signature → 401, no
 *      DB write. (An attacker who could fill `github_webhook_deliveries`
 *      with garbage rows still couldn't trigger handlers, but we
 *      refuse the row anyway so the audit trail isn't polluted.)
 *   2. Idempotency: GitHub retries on timeout. We unique-index on
 *      `github_delivery_id`; a second arrival with the same id is
 *      acknowledged 200 with no second insert and no second dispatch.
 *   3. Insert a fresh row in `received` state, dispatch the processing
 *      job, return 200. Heavy lifting happens async (per §8.5
 *      "Do not process webhook synchronously").
 */
class GitHubWebhookController
{
    public function __invoke(
        Request $request,
        VerifyGitHubWebhookSignatureAction $verifier,
    ): Response {
        $rawBody = $request->getContent();
        $signature = $request->header('X-Hub-Signature-256');
        $secret = (string) config('services.github.webhook_secret', '');

        if (! $verifier->execute($rawBody, $signature, $secret)) {
            return response('Invalid signature', 401);
        }

        $deliveryId = (string) $request->header('X-GitHub-Delivery', '');
        $event = (string) $request->header('X-GitHub-Event', '');

        if ($deliveryId === '' || $event === '') {
            return response('Missing GitHub headers', 400);
        }

        // Idempotent replay of the same delivery is a no-op.
        $existing = WebhookDelivery::query()
            ->where('github_delivery_id', $deliveryId)
            ->first();

        if ($existing !== null) {
            return response('OK', 200);
        }

        $payload = json_decode($rawBody, true) ?? [];

        $delivery = WebhookDelivery::query()->create([
            'github_delivery_id' => $deliveryId,
            'event' => $event,
            'action' => $payload['action'] ?? null,
            'repository_full_name' => $payload['repository']['full_name'] ?? null,
            'payload_json' => $payload,
            'signature' => (string) $signature,
            'status' => WebhookDeliveryStatus::Received->value,
            'received_at' => now(),
        ]);

        ProcessGitHubWebhookJob::dispatch($delivery->id);

        return response('OK', 200);
    }
}
