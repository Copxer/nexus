<?php

namespace App\Domain\GitHub\WebhookHandlers;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Domain\GitHub\Actions\NormalizeGitHubIssueAction;
use App\Enums\ActivitySeverity;
use App\Enums\WebhookDeliveryStatus;
use App\Models\GithubIssue;
use App\Models\Repository;
use App\Models\WebhookDelivery;
use Illuminate\Support\Carbon;

/**
 * Handle GitHub `issues` event deliveries.
 *
 *   - opened    → upsert + `issue.created` activity (info)
 *   - closed    → upsert + `issue.closed` activity (success)
 *   - reopened  → upsert + `issue.reopened` activity (info)
 *   - edited    → upsert + `issue.updated` activity (info)
 *   - other     → skipped (logged via the delivery row's error_message)
 *
 * Returns `Skipped` (not `Failed`) when:
 *   - the action isn't one of the four above
 *   - the payload turns out to be a PR (the `issues` event still fires
 *     for PRs as a side-effect; the normalizer drops them)
 *   - the repo isn't imported into Nexus yet (we don't have a row to
 *     attach the issue to)
 */
class IssuesWebhookHandler
{
    private const HANDLED_ACTIONS = [
        'opened' => ['type' => 'issue.created', 'severity' => ActivitySeverity::Info],
        'closed' => ['type' => 'issue.closed', 'severity' => ActivitySeverity::Success],
        'reopened' => ['type' => 'issue.reopened', 'severity' => ActivitySeverity::Info],
        'edited' => ['type' => 'issue.updated', 'severity' => ActivitySeverity::Info],
    ];

    public function __construct(
        private readonly NormalizeGitHubIssueAction $normalizer,
        private readonly CreateActivityEventAction $createActivity,
    ) {}

    public function handle(WebhookDelivery $delivery): WebhookDeliveryStatus
    {
        $payload = $delivery->payload_json ?? [];
        $action = (string) ($payload['action'] ?? '');
        $issuePayload = $payload['issue'] ?? null;

        $rule = self::HANDLED_ACTIONS[$action] ?? null;

        if ($rule === null || ! is_array($issuePayload)) {
            $delivery->forceFill([
                'error_message' => "Unhandled `issues` action `{$action}` or missing payload.",
            ])->save();

            return WebhookDeliveryStatus::Skipped;
        }

        // The `issues` event still fires for PR-as-issue payloads. The
        // normalizer returns null on those — that's the drop point.
        $normalized = $this->normalizer->execute($issuePayload);

        if ($normalized === null) {
            $delivery->forceFill([
                'error_message' => 'Payload looks like a pull request, not an issue.',
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

        GithubIssue::query()->updateOrCreate(
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
            'occurred_at' => $this->occurredAt($issuePayload, $action),
            'repository_id' => $repository->id,
            'actor_login' => $sender,
            'metadata' => [
                'issue_number' => $normalized['number'],
                'github_id' => $normalized['github_id'],
                'action' => $action,
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

    /**
     * Pick the most accurate timestamp for the activity event. For
     * `closed` we want `closed_at`; for everything else `updated_at`
     * is a better fit than `now()` (preserves the actual GitHub-side
     * moment if there's a sync lag).
     */
    private function occurredAt(array $issuePayload, string $action): Carbon
    {
        $field = $action === 'closed' ? 'closed_at' : 'updated_at';
        $iso = $issuePayload[$field] ?? null;

        if ($iso !== null && $iso !== '') {
            return Carbon::parse($iso);
        }

        return now();
    }
}
