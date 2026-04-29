<?php

namespace App\Domain\GitHub\WebhookHandlers;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Enums\ActivitySeverity;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Repository;
use App\Models\WebhookDelivery;
use Illuminate\Support\Carbon;

/**
 * Handle GitHub `release` event deliveries (spec 019).
 *
 *   - released  → `release.published` activity (info)
 *   - published → `release.published` activity (info, alias)
 *   - other     → skipped
 *
 * Pre-releases (drafts, edits, deletions) intentionally skip — only
 * publish-grade events deserve a feed entry.
 */
class ReleaseWebhookHandler
{
    private const HANDLED_ACTIONS = ['released', 'published'];

    public function __construct(
        private readonly CreateActivityEventAction $createActivity,
    ) {}

    public function handle(WebhookDelivery $delivery): WebhookDeliveryStatus
    {
        $payload = $delivery->payload_json ?? [];
        $action = (string) ($payload['action'] ?? '');
        $release = $payload['release'] ?? null;

        if (! in_array($action, self::HANDLED_ACTIONS, true) || ! is_array($release)) {
            $delivery->forceFill([
                'error_message' => "Unhandled `release` action `{$action}` or missing payload.",
            ])->save();

            return WebhookDeliveryStatus::Skipped;
        }

        // Filter drafts and pre-releases from the feed.
        if (($release['draft'] ?? false) === true) {
            $delivery->forceFill([
                'error_message' => 'Release is a draft — not surfaced.',
            ])->save();

            return WebhookDeliveryStatus::Skipped;
        }

        $repository = $this->resolveRepository($delivery);

        if ($repository === null) {
            $delivery->forceFill([
                'error_message' => 'Repository not imported into Nexus.',
            ])->save();

            return WebhookDeliveryStatus::Skipped;
        }

        $tagName = (string) ($release['tag_name'] ?? '');
        $name = (string) ($release['name'] ?? $tagName);
        $isPrerelease = (bool) ($release['prerelease'] ?? false);
        $sender = $payload['sender']['login'] ?? null;

        $title = $tagName !== ''
            ? "{$name} ({$tagName}) published"
            : "{$name} published";

        $this->createActivity->execute([
            'event_type' => 'release.published',
            'severity' => ActivitySeverity::Info,
            'title' => $title,
            'description' => $repository->full_name,
            'occurred_at' => $this->occurredAt($release),
            'repository_id' => $repository->id,
            'actor_login' => $sender,
            'metadata' => [
                'tag_name' => $tagName,
                'name' => $name,
                'prerelease' => $isPrerelease,
                'html_url' => $release['html_url'] ?? null,
            ],
        ]);

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

    private function occurredAt(array $release): Carbon
    {
        $iso = $release['published_at'] ?? $release['created_at'] ?? null;

        if ($iso !== null && $iso !== '') {
            return Carbon::parse($iso);
        }

        return now();
    }
}
