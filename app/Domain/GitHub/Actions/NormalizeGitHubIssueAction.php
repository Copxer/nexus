<?php

namespace App\Domain\GitHub\Actions;

use App\Enums\GithubIssueState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Pure mapper from a single GitHub `/issues` payload to the array
 * we persist on `github_issues`.
 *
 * GitHub's `/repos/{full}/issues` endpoint returns pull requests
 * alongside issues — they carry a `pull_request` object. This action
 * returns `null` for those so the caller drops them; PRs land in
 * spec 016's own table.
 *
 * The `synced_at` timestamp is intentionally NOT set here. The caller
 * (the sync action) stamps it once per upsert so all rows from the
 * same fetch share the same value.
 */
class NormalizeGitHubIssueAction
{
    /** Issue body preview cap in characters. */
    private const BODY_PREVIEW_LIMIT = 280;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function execute(array $payload): ?array
    {
        if (isset($payload['pull_request'])) {
            return null;
        }

        if (! isset($payload['id'], $payload['number'], $payload['title'])) {
            return null;
        }

        return [
            'github_id' => (int) $payload['id'],
            'number' => (int) $payload['number'],
            'title' => (string) $payload['title'],
            'body_preview' => $this->preview($payload['body'] ?? null),
            'state' => $this->parseState($payload['state'] ?? null)->value,
            'author_login' => $payload['user']['login'] ?? null,
            'labels' => $this->normalizeLabels($payload['labels'] ?? []),
            'assignees' => $this->normalizeAssignees($payload['assignees'] ?? []),
            'milestone' => $this->normalizeMilestone($payload['milestone'] ?? null),
            'comments_count' => (int) ($payload['comments'] ?? 0),
            'is_locked' => (bool) ($payload['locked'] ?? false),
            'created_at_github' => $this->parseTimestamp($payload['created_at'] ?? null),
            'updated_at_github' => $this->parseTimestamp($payload['updated_at'] ?? null),
            'closed_at_github' => $this->parseTimestamp($payload['closed_at'] ?? null),
        ];
    }

    private function preview(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        // No ellipsis suffix — the spec caps at 280 chars exactly so
        // downstream consumers can rely on the bound. Adding "…" would
        // push us to 281 and surprise anyone validating row size.
        return Str::limit($body, self::BODY_PREVIEW_LIMIT, '');
    }

    private function parseState(?string $state): GithubIssueState
    {
        return GithubIssueState::tryFrom((string) $state) ?? GithubIssueState::Open;
    }

    /**
     * Keep just `name` + `color`. The full GitHub label payload carries
     * an id, url, description, default flag — none of which we use.
     *
     * @param  array<int, mixed>  $labels
     * @return array<int, array{name: string, color: string}>
     */
    private function normalizeLabels(array $labels): array
    {
        $out = [];

        foreach ($labels as $label) {
            if (! is_array($label) || ! isset($label['name'])) {
                continue;
            }
            $out[] = [
                'name' => (string) $label['name'],
                'color' => (string) ($label['color'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $assignees
     * @return array<int, string>
     */
    private function normalizeAssignees(array $assignees): array
    {
        $out = [];

        foreach ($assignees as $assignee) {
            if (! is_array($assignee) || ! isset($assignee['login'])) {
                continue;
            }
            $out[] = (string) $assignee['login'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $milestone
     * @return array{title: string, due_on: string|null}|null
     */
    private function normalizeMilestone(?array $milestone): ?array
    {
        if ($milestone === null || ! isset($milestone['title'])) {
            return null;
        }

        return [
            'title' => (string) $milestone['title'],
            'due_on' => isset($milestone['due_on']) ? (string) $milestone['due_on'] : null,
        ];
    }

    private function parseTimestamp(?string $iso): ?Carbon
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        return Carbon::parse($iso);
    }
}
