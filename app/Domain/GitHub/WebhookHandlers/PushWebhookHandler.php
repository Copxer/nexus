<?php

namespace App\Domain\GitHub\WebhookHandlers;

use App\Enums\WebhookDeliveryStatus;
use App\Models\Repository;
use App\Models\WebhookDelivery;
use Illuminate\Support\Carbon;

/**
 * Handle GitHub `push` event deliveries (spec 019).
 *
 * Pushes are too high-frequency to surface as activity events (every
 * commit on every branch fires one). Instead this handler only updates
 * `repositories.last_pushed_at` so the repo show page's `Last pushed`
 * field stays fresh between metadata syncs. Activity events for
 * pushes can be revisited per-roadmap §8.10 if/when filtering arrives
 * to keep the feed readable.
 *
 * Returns `Skipped` (not `Failed`) when:
 *   - the repo isn't imported into Nexus yet
 *   - the payload has no usable timestamp
 */
class PushWebhookHandler
{
    public function handle(WebhookDelivery $delivery): WebhookDeliveryStatus
    {
        $repository = $this->resolveRepository($delivery);

        if ($repository === null) {
            $delivery->forceFill([
                'error_message' => 'Repository not imported into Nexus.',
            ])->save();

            return WebhookDeliveryStatus::Skipped;
        }

        $payload = $delivery->payload_json ?? [];
        $iso = $payload['head_commit']['timestamp']
            ?? $payload['repository']['pushed_at']
            ?? null;

        if ($iso === null || $iso === '') {
            $delivery->forceFill([
                'error_message' => 'No usable push timestamp on the payload.',
            ])->save();

            return WebhookDeliveryStatus::Skipped;
        }

        $repository->forceFill([
            'last_pushed_at' => Carbon::parse($iso),
        ])->save();

        return WebhookDeliveryStatus::Processed;
    }

    private function resolveRepository(WebhookDelivery $delivery): ?Repository
    {
        $fullName = $delivery->repository_full_name;

        if ($fullName === null || $fullName === '') {
            return null;
        }

        return Repository::query()->where('full_name', $fullName)->first();
    }
}
