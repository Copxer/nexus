<?php

namespace App\Domain\GitHub\WebhookHandlers;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Domain\GitHub\Actions\NormalizeGitHubPullRequestAction;
use App\Enums\ActivitySeverity;
use App\Enums\WebhookDeliveryStatus;
use App\Models\GithubPullRequest;
use App\Models\Repository;
use App\Models\WebhookDelivery;
use Illuminate\Support\Carbon;

/**
 * Handle GitHub `pull_request` event deliveries.
 *
 * Action mapping (the `closed` action splits on `merged`):
 *   - opened           → `pull_request.opened`           (info)
 *   - reopened         → `pull_request.reopened`         (info)
 *   - closed + merged  → `pull_request.merged`           (success)
 *   - closed (no merge)→ `pull_request.closed`           (info)
 *   - review_requested → `pull_request.review_requested` (info)
 *   - other            → skipped
 *
 * Returns `Skipped` (not `Failed`) when:
 *   - the action isn't recognized
 *   - the repo isn't imported into Nexus yet
 */
class PullRequestWebhookHandler
{
    public function __construct(
        private readonly NormalizeGitHubPullRequestAction $normalizer,
        private readonly CreateActivityEventAction $createActivity,
    ) {}

    public function handle(WebhookDelivery $delivery): WebhookDeliveryStatus
    {
        $payload = $delivery->payload_json ?? [];
        $action = (string) ($payload['action'] ?? '');
        $prPayload = $payload['pull_request'] ?? null;

        if (! is_array($prPayload)) {
            $delivery->forceFill([
                'error_message' => "Missing `pull_request` block on `{$action}` event.",
            ])->save();

            return WebhookDeliveryStatus::Skipped;
        }

        $rule = $this->resolveRule($action, $prPayload);

        if ($rule === null) {
            $delivery->forceFill([
                'error_message' => "Unhandled `pull_request` action `{$action}`.",
            ])->save();

            return WebhookDeliveryStatus::Skipped;
        }

        $normalized = $this->normalizer->execute($prPayload);

        if ($normalized === null) {
            $delivery->forceFill([
                'error_message' => 'Pull request payload missing required fields.',
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

        GithubPullRequest::query()->updateOrCreate(
            [
                'repository_id' => $repository->id,
                'github_id' => $normalized['github_id'],
            ],
            [
                ...$normalized,
                'synced_at' => now(),
            ],
        );

        $sender = $payload['sender']['login'] ?? null;

        $this->createActivity->execute([
            'event_type' => $rule['type'],
            'severity' => $rule['severity'],
            'title' => "#{$normalized['number']} {$normalized['title']}",
            'description' => $repository->full_name,
            'occurred_at' => $this->occurredAt($prPayload, $action, $normalized['merged']),
            'repository_id' => $repository->id,
            'actor_login' => $sender,
            'metadata' => [
                'pull_request_number' => $normalized['number'],
                'github_id' => $normalized['github_id'],
                'action' => $action,
                'merged' => $normalized['merged'],
            ],
        ]);

        return WebhookDeliveryStatus::Processed;
    }

    /**
     * @param  array<string, mixed>  $prPayload
     * @return array{type: string, severity: ActivitySeverity}|null
     */
    private function resolveRule(string $action, array $prPayload): ?array
    {
        return match ($action) {
            'opened' => ['type' => 'pull_request.opened', 'severity' => ActivitySeverity::Info],
            'reopened' => ['type' => 'pull_request.reopened', 'severity' => ActivitySeverity::Info],
            'review_requested' => ['type' => 'pull_request.review_requested', 'severity' => ActivitySeverity::Info],
            'closed' => $this->resolveClosedRule($prPayload),
            default => null,
        };
    }

    /**
     * `closed` splits on whether the PR was merged. GitHub sets
     * `merged: true` (and `merged_at`) when it was; both null/false
     * when it was just closed.
     *
     * @param  array<string, mixed>  $prPayload
     * @return array{type: string, severity: ActivitySeverity}
     */
    private function resolveClosedRule(array $prPayload): array
    {
        $merged = (bool) ($prPayload['merged'] ?? false)
            || (! empty($prPayload['merged_at']));

        return $merged
            ? ['type' => 'pull_request.merged', 'severity' => ActivitySeverity::Success]
            : ['type' => 'pull_request.closed', 'severity' => ActivitySeverity::Info];
    }

    private function resolveRepository(WebhookDelivery $delivery): ?Repository
    {
        $fullName = $delivery->repository_full_name;

        if ($fullName === null || $fullName === '') {
            return null;
        }

        return Repository::query()->where('full_name', $fullName)->first();
    }

    private function occurredAt(array $prPayload, string $action, bool $merged): Carbon
    {
        $field = match (true) {
            $action === 'closed' && $merged => 'merged_at',
            $action === 'closed' => 'closed_at',
            default => 'updated_at',
        };

        $iso = $prPayload[$field] ?? null;

        if ($iso !== null && $iso !== '') {
            return Carbon::parse($iso);
        }

        return now();
    }
}
