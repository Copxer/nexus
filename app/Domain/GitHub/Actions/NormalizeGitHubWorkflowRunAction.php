<?php

namespace App\Domain\GitHub\Actions;

use App\Enums\WorkflowRunConclusion;
use App\Enums\WorkflowRunStatus;
use Illuminate\Support\Carbon;

/**
 * Pure mapper from a single GitHub `/actions/runs` payload entry (or
 * the `workflow_run` field of a webhook delivery) to the array we
 * persist on `workflow_runs`.
 *
 * Returns `null` for malformed payloads (missing `id` / `head_sha`)
 * so the caller can skip cleanly. The `repository_id` is intentionally
 * NOT set here — the caller (sync action / webhook handler) attaches
 * it once it's resolved the local Repository row.
 *
 * Status fallback: if GitHub returns a value that doesn't map to one
 * of our three canonical states (`queued`, `in_progress`, `completed`)
 * we collapse to `Queued` — newer pre-completion states like
 * `requested` / `waiting` / `pending` aren't worth a UI distinction.
 */
class NormalizeGitHubWorkflowRunAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function execute(array $payload): ?array
    {
        if (! isset($payload['id'], $payload['head_sha'])) {
            return null;
        }

        $status = $this->parseStatus($payload['status'] ?? null);
        $conclusion = $this->parseConclusion($payload['conclusion'] ?? null);

        $startedAt = $this->parseTimestamp($payload['run_started_at'] ?? $payload['created_at'] ?? null);
        $updatedAt = $this->parseTimestamp($payload['updated_at'] ?? null);

        // GitHub doesn't expose a discrete `completed_at`. The convention
        // is: when status flips to `completed`, the `updated_at` field
        // captures the completion time. Persisting it as `run_completed
        // _at` keeps the analytics shape clean (success-rate windows,
        // duration percentiles) without a derived view.
        //
        // Re-run note: GitHub keeps the same run id when a run is
        // re-run, flipping status back to `queued` / `in_progress`. The
        // `null` fallback below correctly clears `run_completed_at`
        // during the re-run window so analytics don't see a "completed"
        // timestamp on a row that's actively running.
        $completedAt = $status === WorkflowRunStatus::Completed ? $updatedAt : null;

        return [
            'github_id' => (int) $payload['id'],
            'run_number' => (int) ($payload['run_number'] ?? 0),
            'name' => (string) ($payload['name'] ?? 'workflow'),
            'event' => (string) ($payload['event'] ?? 'unknown'),
            'status' => $status->value,
            'conclusion' => $conclusion?->value,
            'head_branch' => isset($payload['head_branch']) ? (string) $payload['head_branch'] : null,
            'head_sha' => (string) $payload['head_sha'],
            'actor_login' => $payload['actor']['login']
                ?? $payload['triggering_actor']['login']
                ?? null,
            'html_url' => (string) ($payload['html_url'] ?? ''),
            'run_started_at' => $startedAt,
            'run_updated_at' => $updatedAt,
            'run_completed_at' => $completedAt,
        ];
    }

    private function parseStatus(?string $status): WorkflowRunStatus
    {
        return WorkflowRunStatus::tryFrom((string) $status) ?? WorkflowRunStatus::Queued;
    }

    private function parseConclusion(?string $conclusion): ?WorkflowRunConclusion
    {
        if ($conclusion === null || $conclusion === '') {
            return null;
        }

        return WorkflowRunConclusion::tryFrom($conclusion);
    }

    private function parseTimestamp(?string $iso): ?Carbon
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        return Carbon::parse($iso);
    }
}
