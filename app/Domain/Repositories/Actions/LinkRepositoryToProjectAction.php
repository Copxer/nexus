<?php

namespace App\Domain\Repositories\Actions;

use App\Enums\RepositorySyncStatus;
use App\Models\Project;
use App\Models\Repository;
use InvalidArgumentException;

/**
 * Manually link a GitHub repository to a Project.
 *
 * Accepts either:
 *   - a full URL: `https://github.com/owner/name`, optionally with `.git`
 *     suffix or a trailing `/`
 *   - or a bare slug: `owner/name`
 *
 * Idempotent: if the same `full_name` is already linked to the same
 * project we return the existing row instead of throwing. A `full_name`
 * already linked to a different project bubbles up as a unique-index
 * violation; the controller surfaces a friendly 409.
 *
 * The hydrated row is created with `sync_status = pending` and null
 * sync timestamps; phase-2's GitHub sync job populates the real
 * metadata (stars, language, default branch, etc.).
 */
class LinkRepositoryToProjectAction
{
    public function execute(Project $project, string $input): Repository
    {
        [$owner, $name] = $this->parse($input);
        $full = "{$owner}/{$name}";

        $existing = Repository::query()
            ->where('full_name', $full)
            ->where('project_id', $project->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Repository::query()->create([
            'project_id' => $project->id,
            'provider' => 'github',
            'provider_id' => null,
            'owner' => $owner,
            'name' => $name,
            'full_name' => $full,
            'html_url' => "https://github.com/{$full}",
            'default_branch' => 'main',
            'visibility' => 'public',
            'sync_status' => RepositorySyncStatus::Pending->value,
        ]);
    }

    /**
     * Pull `[owner, name]` out of either a URL or a `owner/name` slug.
     *
     * @return array{0: string, 1: string}
     *
     * @throws InvalidArgumentException if the input doesn't match either shape.
     */
    public function parse(string $input): array
    {
        $trimmed = trim($input);

        // Try the URL shape first.
        if (preg_match('#^https?://(?:www\.)?github\.com/([\w.-]+)/([\w.-]+?)(?:\.git)?/?$#i', $trimmed, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        // Bare `owner/name` slug.
        if (preg_match('#^([\w.-]+)/([\w.-]+)$#', $trimmed, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        throw new InvalidArgumentException(
            "Couldn't parse \"{$input}\" — expected a GitHub URL or `owner/name` slug.",
        );
    }
}
