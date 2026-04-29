<?php

namespace App\Domain\GitHub\Actions;

use App\Enums\GithubPullRequestState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Pure mapper from a single GitHub `/pulls` payload to the array we
 * persist on `github_pull_requests`.
 *
 * Unlike `NormalizeGitHubIssueAction`, this never returns null — the
 * `/pulls` endpoint only returns PRs (no fan-in from `/issues`).
 *
 * `state` is derived: GitHub serves `state=open|closed` and a separate
 * `merged` boolean. We collapse the two into one of `open|closed|merged`
 * so the page can badge in one match.
 *
 * `synced_at` is intentionally NOT set here — the caller stamps it once
 * per upsert so all rows from the same fetch share the same value.
 */
class NormalizeGitHubPullRequestAction
{
    /** Body preview cap, same firm bound as github_issues. */
    private const BODY_PREVIEW_LIMIT = 280;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function execute(array $payload): ?array
    {
        if (! isset($payload['id'], $payload['number'], $payload['title'])) {
            return null;
        }

        return [
            'github_id' => (int) $payload['id'],
            'number' => (int) $payload['number'],
            'title' => (string) $payload['title'],
            'body_preview' => $this->preview($payload['body'] ?? null),
            'state' => $this->deriveState($payload)->value,
            'author_login' => $payload['user']['login'] ?? null,
            'base_branch' => (string) ($payload['base']['ref'] ?? ''),
            'head_branch' => (string) ($payload['head']['ref'] ?? ''),
            'draft' => (bool) ($payload['draft'] ?? false),
            'merged' => $this->isMerged($payload),
            'additions' => (int) ($payload['additions'] ?? 0),
            'deletions' => (int) ($payload['deletions'] ?? 0),
            'changed_files' => (int) ($payload['changed_files'] ?? 0),
            'comments_count' => (int) ($payload['comments'] ?? 0),
            'review_comments_count' => (int) ($payload['review_comments'] ?? 0),
            'created_at_github' => $this->parseTimestamp($payload['created_at'] ?? null),
            'updated_at_github' => $this->parseTimestamp($payload['updated_at'] ?? null),
            'closed_at_github' => $this->parseTimestamp($payload['closed_at'] ?? null),
            'merged_at' => $this->parseTimestamp($payload['merged_at'] ?? null),
        ];
    }

    private function preview(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        // No ellipsis suffix — same firm 280-char bound as the issues
        // normalizer (spec 015).
        return Str::limit($body, self::BODY_PREVIEW_LIMIT, '');
    }

    /**
     * Collapse GitHub's `state` + `merged` flag into our 3-state enum.
     * GitHub's `/pulls` payload uses `state=open|closed` and a separate
     * `merged` boolean (or a non-null `merged_at`). A merged PR is
     * always also `state=closed` on GitHub's side.
     */
    private function deriveState(array $payload): GithubPullRequestState
    {
        if ($this->isMerged($payload)) {
            return GithubPullRequestState::Merged;
        }

        $rawState = (string) ($payload['state'] ?? 'open');

        return match ($rawState) {
            'closed' => GithubPullRequestState::Closed,
            default => GithubPullRequestState::Open,
        };
    }

    /**
     * Some GitHub payloads include `merged: true`, others rely on
     * `merged_at` being non-null. Trust either.
     */
    private function isMerged(array $payload): bool
    {
        if (isset($payload['merged']) && (bool) $payload['merged']) {
            return true;
        }

        return isset($payload['merged_at']) && ! empty($payload['merged_at']);
    }

    private function parseTimestamp(?string $iso): ?Carbon
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        return Carbon::parse($iso);
    }
}
