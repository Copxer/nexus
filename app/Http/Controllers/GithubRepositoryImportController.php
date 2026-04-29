<?php

namespace App\Http\Controllers;

use App\Domain\GitHub\Actions\ImportRepositoryAction;
use App\Domain\GitHub\Exceptions\GitHubApiException;
use App\Domain\GitHub\Services\GitHubClient;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GithubRepositoryImportController extends Controller
{
    /**
     * GET /projects/{project}/repositories/import
     *
     * Renders the picker. We list everything the user can see on
     * GitHub, then flag rows that are already linked to ANY project
     * (so the user understands why a row is disabled) — same-project
     * imports are still possible (idempotent refresh path).
     */
    public function index(Request $request, Project $project): Response|RedirectResponse
    {
        $this->authorize('update', $project);

        $user = $request->user();
        $connection = $user?->githubConnection;

        if ($connection === null) {
            return redirect()
                ->route('settings.index')
                ->with(
                    'error',
                    'Connect your GitHub account first to import repositories.',
                );
        }

        try {
            $payload = (new GitHubClient($connection))->listRepositories();
        } catch (GitHubApiException $e) {
            return redirect()
                ->route('projects.show', $project)
                ->with('error', 'GitHub repository list failed: '.$e->getMessage());
        }

        $existingFullNames = Repository::query()
            ->whereIn('full_name', collect($payload)->pluck('full_name')->filter()->all())
            ->pluck('project_id', 'full_name');

        $repositories = collect($payload)
            ->map(fn (array $repo) => [
                'id' => $repo['id'] ?? null,
                'full_name' => $repo['full_name'] ?? null,
                'description' => $repo['description'] ?? null,
                'language' => $repo['language'] ?? null,
                'private' => (bool) ($repo['private'] ?? false),
                'stars_count' => (int) ($repo['stargazers_count'] ?? 0),
                'forks_count' => (int) ($repo['forks_count'] ?? 0),
                'pushed_at' => $repo['pushed_at'] ?? null,
                'html_url' => $repo['html_url'] ?? null,
                'is_already_linked' => $existingFullNames->has($repo['full_name'] ?? null),
                'linked_to_this_project' => $existingFullNames->get($repo['full_name'] ?? null) === $project->id,
            ])
            ->filter(fn (array $repo) => $repo['full_name'] !== null)
            ->values()
            ->all();

        return Inertia::render('Repositories/Import', [
            'project' => [
                'id' => $project->id,
                'slug' => $project->slug,
                'name' => $project->name,
            ],
            'repositories' => $repositories,
        ]);
    }

    /**
     * POST /projects/{project}/repositories/import
     *
     * Validates the `full_name`, dispatches the import action, redirects
     * back to the project's Repositories tab with a flash.
     */
    public function store(
        Request $request,
        Project $project,
        ImportRepositoryAction $import,
    ): RedirectResponse {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'full_name' => [
                'required',
                'string',
                'max:255',
                'regex:#^[\w.-]+/[\w.-]+$#',
                Rule::notIn(['/']),
            ],
        ]);

        $repository = $import->execute($project, $validated['full_name']);

        return redirect()
            ->route('projects.show', $project)
            ->with('status', "Importing {$repository->full_name}…");
    }
}
