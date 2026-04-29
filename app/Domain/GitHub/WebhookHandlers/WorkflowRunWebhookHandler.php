<?php

namespace App\Domain\GitHub\WebhookHandlers;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Enums\ActivitySeverity;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Repository;
use App\Models\WebhookDelivery;
use Illuminate\Support\Carbon;

/**
 * Handle GitHub `workflow_run` event deliveries (spec 019).
 *
 *   - completed (conclusion=success)   → `workflow.succeeded` activity (success)
 *   - completed (conclusion=failure)   → `workflow.failed` activity (danger)
 *   - completed (conclusion=cancelled) → `workflow.failed` activity (warning)
 *   - completed (conclusion=timed_out) → `workflow.failed` activity (warning)
 *   - other actions / conclusions      → skipped
 *
 * `requested` / `in_progress` actions are intentionally skipped — the
 * activity feed should only surface terminal outcomes so it doesn't
 * thrash on every step transition.
 */
class WorkflowRunWebhookHandler
{
    /**
     * Map a GitHub workflow `conclusion` value to our local activity
     * type + severity. Every other conclusion (including `null`,
     * `action_required`, `stale`, `neutral`, `skipped`) lands as a
     * skipped delivery — they're rarely interesting on the rail.
     */
    private const HANDLED_CONCLUSIONS = [
        'success' => ['type' => 'workflow.succeeded', 'severity' => ActivitySeverity::Success],
        'failure' => ['type' => 'workflow.failed', 'severity' => ActivitySeverity::Danger],
        'cancelled' => ['type' => 'workflow.failed', 'severity' => ActivitySeverity::Warning],
        'timed_out' => ['type' => 'workflow.failed', 'severity' => ActivitySeverity::Warning],
    ];

    public function __construct(
        private readonly CreateActivityEventAction $createActivity,
    ) {}

    public function handle(WebhookDelivery $delivery): WebhookDeliveryStatus
    {
        $payload = $delivery->payload_json ?? [];
        $action = (string) ($payload['action'] ?? '');
        $run = $payload['workflow_run'] ?? null;

        if ($action !== 'completed' || ! is_array($run)) {
            $delivery->forceFill([
                'error_message' => "Skipped — only `completed` actions surface to activity (got `{$action}`).",
            ])->save();

            return WebhookDeliveryStatus::Skipped;
        }

        $conclusion = (string) ($run['conclusion'] ?? '');
        $rule = self::HANDLED_CONCLUSIONS[$conclusion] ?? null;

        if ($rule === null) {
            $delivery->forceFill([
                'error_message' => "Skipped — conclusion `{$conclusion}` not surfaced to activity.",
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

        $workflowName = (string) ($run['name'] ?? 'workflow');
        $headBranch = (string) ($run['head_branch'] ?? $repository->default_branch ?? 'main');
        $runNumber = (int) ($run['run_number'] ?? 0);
        $sender = $payload['sender']['login'] ?? null;

        $title = $runNumber > 0
            ? "{$workflowName} #{$runNumber} on {$headBranch}"
            : "{$workflowName} on {$headBranch}";

        $this->createActivity->execute([
            'event_type' => $rule['type'],
            'severity' => $rule['severity'],
            'title' => $title,
            'description' => $repository->full_name,
            'occurred_at' => $this->occurredAt($run),
            'repository_id' => $repository->id,
            'actor_login' => $sender,
            'metadata' => [
                'workflow_name' => $workflowName,
                'run_number' => $runNumber,
                'conclusion' => $conclusion,
                'head_branch' => $headBranch,
                'html_url' => $run['html_url'] ?? null,
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

    private function occurredAt(array $run): Carbon
    {
        $iso = $run['updated_at'] ?? $run['run_started_at'] ?? null;

        if ($iso !== null && $iso !== '') {
            return Carbon::parse($iso);
        }

        return now();
    }
}
