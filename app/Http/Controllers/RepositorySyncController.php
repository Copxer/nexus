<?php

namespace App\Http\Controllers;

use App\Domain\GitHub\Jobs\SyncGitHubRepositoryJob;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;

/**
 * Manual "Run sync" button on the Repository show page header. Re-dispatches
 * `SyncGitHubRepositoryJob` for the given repository, which refreshes the
 * metadata (default branch, language, stars, push timestamps) and cascades
 * into `SyncRepositoryIssuesJob` + `SyncRepositoryPullRequestsJob` on success.
 *
 * Mirrors the per-tab `RepositoryIssuesSyncController` /
 * `RepositoryPullRequestsSyncController` shape introduced in specs 015 + 016
 * — the button is the parent-level sibling of those two.
 *
 * Authorization: `ProjectPolicy::update` — only the project owner can
 * trigger a re-sync, matching the existing tab-level controllers.
 */
class RepositorySyncController extends Controller
{
    public function __invoke(Repository $repository): RedirectResponse
    {
        $repository->loadMissing('project');
        $this->authorize('update', $repository->project);

        SyncGitHubRepositoryJob::dispatch($repository->id);

        return back()->with('status', 'Repository sync queued.');
    }
}
